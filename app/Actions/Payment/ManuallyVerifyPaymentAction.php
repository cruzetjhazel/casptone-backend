<?php

namespace App\Actions\Payment;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Models\Payment;
use App\Models\User;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §8.11 Manual Payment Review — used when automatic matching fails but the
 * Photographer confirms the payment was genuinely received.
 */
class ManuallyVerifyPaymentAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
    ) {
    }

    public function execute(Payment $payment, User $verifier, ?string $notes): Payment
    {
        if (! $payment->isAwaitingManualReview()) {
            throw ValidationException::withMessages([
                'payment' => ['Only payments that failed automatic matching can be manually verified.'],
            ]);
        }

        // Re-check duplicate-reference protection (see SubmitPaymentAction) —
        // another booking may have successfully used this same reference
        // number in the time between this payment being submitted and now.
        $alreadyUsedElsewhere = Payment::where('photographer_id', $payment->photographer_id)
            ->where('reference_number', $payment->reference_number)
            ->where('id', '!=', $payment->id)
            ->whereIn('matching_status', [PaymentMatchingStatus::Matched, PaymentMatchingStatus::ManuallyVerified])
            ->exists();

        if ($alreadyUsedElsewhere) {
            throw ValidationException::withMessages([
                'payment' => ['This GCash reference number has already been used to verify a different payment. Please investigate before verifying.'],
            ]);
        }

        $booking = $payment->booking;
        $plan = $payment->plan;

        // Same atomicity requirement as SubmitPaymentAction's auto-match
        // path: confirming payment claims the slot, so lock + re-check +
        // release rivals must happen together. Unlike the auto-match path,
        // a manual verification is a deliberate human decision, so if this
        // slot was already won by another confirmed+paid booking in the
        // meantime, we surface that to the photographer as an error rather
        // than silently downgrading it — they need to know before deciding
        // how to handle this client's money.
        DB::transaction(function () use ($booking, $plan) {
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $this->slotConflictService->assertNoPaidConflict($booking);

            $booking->update([
                'payment_status' => $plan === PaymentPlan::Full
                    ? BookingPaymentStatus::FullyPaid
                    : BookingPaymentStatus::PartiallyPaid,
                'status' => BookingStatus::Confirmed,
            ]);

            $this->slotConflictService->releaseConflictingBookings($booking->fresh());
        });

        $payment->update([
            'matching_status' => PaymentMatchingStatus::ManuallyVerified,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'verification_action' => 'verified',
            'verification_notes' => $notes,
        ]);

        $freshBooking = $booking->fresh();
        $freshPayment = $payment->fresh();

        $freshBooking->client->notify(new \App\Notifications\Payment\PaymentManuallyVerifiedNotification($freshPayment));
        $freshBooking->client->notify(new \App\Notifications\Booking\BookingConfirmedNotification($freshBooking));
        $freshBooking->photographer->notify(new \App\Notifications\Booking\BookingConfirmedNotification($freshBooking));

        if ($plan === PaymentPlan::Full) {
            $freshBooking->client->notify(new \App\Notifications\Payment\FullPaymentCompletedNotification($freshBooking));
            $freshBooking->photographer->notify(new \App\Notifications\Payment\FullPaymentCompletedNotification($freshBooking));
        } else {
            $freshBooking->client->notify(new \App\Notifications\Payment\RemainingBalanceNotification($freshBooking));
        }

        $this->activityLogger->execute(
            causer: $verifier,
            subject: $freshPayment,
            action: 'payment.manually_verified',
            description: "Manually verified payment for booking #{$freshBooking->id}",
            metadata: array_filter(['notes' => $notes]),
        );

        return $payment->fresh();
    }
}