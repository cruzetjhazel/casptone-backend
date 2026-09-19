<?php

namespace App\Actions\Payment;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Enums\PaymentType;
use App\Enums\PhotographerPaymentReferenceStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PhotographerPaymentReference;
use App\Models\User;
use App\Services\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a Client's submitted GCash payment and matches it against a
 * Photographer-recorded reference (§9.2/§9.3). Match -> Payment Verified,
 * Booking Confirmed (§8.11). No match -> Payment sits Not Matched, booking
 * moves to Pending Verification and stays Accepted — it does NOT auto-confirm
 * (§8.5.2/§9.4). The Photographer then manually verifies or rejects it.
 */
class SubmitPaymentAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
    ) {
    }

    public function execute(Booking $booking, array $data): Payment
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages([
                'booking' => ['This booking is not currently awaiting payment.'],
            ]);
        }

        if (! in_array($booking->payment_status, [BookingPaymentStatus::Pending], true)) {
            throw ValidationException::withMessages([
                'booking' => ['A payment has already been submitted for this booking.'],
            ]);
        }

        $plan = PaymentPlan::from($data['plan']);
        $expectedAmount = $booking->onlineAmountDueFor($plan);

        if (round((float) $data['amount'], 2) !== round($expectedAmount, 2)) {
            throw ValidationException::withMessages([
                'amount' => ["The amount paid must match the {$plan->value} payment amount of ".number_format($expectedAmount, 2).'.'],
            ]);
        }

        // Duplicate-reference protection: the DB's unique constraint on
        // reference_number was intentionally dropped (retries/typo
        // corrections need to be resubmittable), so this is now the only
        // guard against the same GCash reference number being used to pay
        // for two different bookings. We only block it once it has actually
        // succeeded elsewhere (Matched or ManuallyVerified) — a reference
        // that's merely NotMatched/Rejected on another booking might be a
        // legitimate retry after a typo and shouldn't be blocked here.
        $alreadyUsedElsewhere = Payment::where('photographer_id', $booking->photographer_id)
            ->where('reference_number', $data['reference_number'])
            ->where('booking_id', '!=', $booking->id)
            ->whereIn('matching_status', [PaymentMatchingStatus::Matched, PaymentMatchingStatus::ManuallyVerified])
            ->exists();

        if ($alreadyUsedElsewhere) {
            throw ValidationException::withMessages([
                'reference_number' => ['This GCash reference number has already been used to pay for a different booking.'],
            ]);
        }

        // §9.3 — primary matching key is Photographer ID + GCash Reference Code,
        // and the reference must not already be used for another booking.
        $match = PhotographerPaymentReference::where('photographer_id', $booking->photographer_id)
            ->where('reference_number', $data['reference_number'])
            ->where('status', PhotographerPaymentReferenceStatus::Available)
            ->first();

        $matched = $match !== null && round((float) $match->amount_received, 2) === round((float) $data['amount'], 2);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'photographer_id' => $booking->photographer_id,
            'type' => PaymentType::Online,
            'method' => 'gcash',
            'plan' => $plan->value,
            'amount' => $data['amount'],
            'reference_number' => $data['reference_number'],
            'payer_name' => $data['payer_name'],
            'payment_date' => $data['payment_date'],
            'photographer_payment_reference_id' => $matched ? $match->id : null,
            'matching_status' => $matched ? PaymentMatchingStatus::Matched : PaymentMatchingStatus::NotMatched,
        ]);

        // Confirming payment is what actually "claims" the slot (see
        // SlotConflictService), so this step must be atomic: lock the
        // photographer row, re-check no rival booking already won this
        // slot, then confirm and release the rivals — all inside one
        // transaction so two near-simultaneous payments can't both win.
        // In the rare case a rival booking somehow already won the slot
        // (both clients paid within the same instant), we don't leave this
        // payment in limbo — it falls back to manual review instead.
        $lostRaceToRival = false;

        if ($matched) {
            try {
                DB::transaction(function () use ($booking, $match, $plan) {
                    User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();

                    $this->slotConflictService->assertNoPaidConflict($booking);

                    $match->update(['status' => PhotographerPaymentReferenceStatus::Used]);

                    $booking->update([
                        'payment_plan' => $plan,
                        'payment_status' => $plan === PaymentPlan::Full
                            ? BookingPaymentStatus::FullyPaid
                            : BookingPaymentStatus::PartiallyPaid,
                        'status' => BookingStatus::Confirmed,
                    ]);

                    $this->slotConflictService->releaseConflictingBookings($booking->fresh());
                });
            } catch (ValidationException $e) {
                $lostRaceToRival = true;
                // Roll the payment record back to an honest "not matched"
                // state — the match was never actually applied (the
                // transaction above threw before committing), so don't
                // leave the payment claiming a match that didn't happen.
                $payment->update([
                    'matching_status' => PaymentMatchingStatus::NotMatched,
                    'photographer_payment_reference_id' => null,
                    'verification_notes' => 'Auto-match reversed: another client\'s payment for this same slot was confirmed first. Needs manual review.',
                ]);
            }
        }

        if ($matched && ! $lostRaceToRival) {
            $freshBooking = $booking->fresh();
            $freshPayment = $payment->fresh();

            $freshBooking->client->notify(new \App\Notifications\Payment\PaymentVerifiedNotification($freshPayment));
            $freshBooking->photographer->notify(new \App\Notifications\Payment\PaymentReceivedNotification($freshPayment));
            $freshBooking->client->notify(new \App\Notifications\Booking\BookingConfirmedNotification($freshBooking));
            $freshBooking->photographer->notify(new \App\Notifications\Booking\BookingConfirmedNotification($freshBooking));

            if ($plan === PaymentPlan::Full) {
                $freshBooking->client->notify(new \App\Notifications\Payment\FullPaymentCompletedNotification($freshBooking));
                $freshBooking->photographer->notify(new \App\Notifications\Payment\FullPaymentCompletedNotification($freshBooking));
            } else {
                $freshBooking->client->notify(new \App\Notifications\Payment\RemainingBalanceNotification($freshBooking));
            }

            $this->activityLogger->execute(
                causer: $freshBooking->client,
                subject: $freshPayment,
                action: 'payment.submitted_matched',
                description: "Submitted and auto-matched {$plan->value} payment for booking #{$freshBooking->id}",
                metadata: ['amount' => $data['amount'], 'plan' => $plan->value],
            );
        } else {
            $booking->update([
                'payment_plan' => $plan,
                'payment_status' => BookingPaymentStatus::PendingVerification,
            ]);

            $freshPayment = $payment->fresh();

            $booking->client->notify(new \App\Notifications\Payment\PaymentPendingVerificationNotification($freshPayment));
            $booking->photographer->notify(new \App\Notifications\Payment\PaymentNeedsReviewNotification($freshPayment));

            $this->activityLogger->execute(
                causer: $booking->client,
                subject: $freshPayment,
                action: 'payment.submitted_unmatched',
                description: "Submitted {$plan->value} payment for booking #{$booking->id}, pending manual verification",
                metadata: ['amount' => $data['amount'], 'plan' => $plan->value],
            );
        }

        return $payment->fresh();
    }
}