<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Models\Booking;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Notifications\Booking\CancellationRequestRejectedNotification;
use App\Models\User;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideBookingCancellationAction
{
        public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
    ) {
    }

    public function execute(Booking $booking, CancellationDecision $decision): Booking
    {
        $heldSlot = false;

        // Same lock order as payment verification (photographer row, then the booking), so a
        // cancellation approval and a payment verification can never interleave.
        $fresh = DB::transaction(function () use ($booking, $decision, &$heldSlot) {
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasPendingCancellationRequest()) {
                throw ValidationException::withMessages([
                    'status' => ['There is no pending cancellation request for this booking.'],
                ]);
            }

            $locked->cancellation_decision = $decision;
            $locked->cancellation_decided_at = now();

            if ($decision === CancellationDecision::Approved) {
                // Did this booking actually hold a paid slot? Only then is a slot being released.
                $heldSlot = $locked->status === BookingStatus::Confirmed && $locked->isPaymentSettled();

                $locked->status = BookingStatus::Cancelled;
                $locked->cancelled_by = 'client';
                $locked->hold_expires_at = null;
                // Payment rows and refund fields are deliberately NOT touched here: any refund
                // is settled by the photographer/admin outside the system and recorded on the
                // payment (see admin/payments/{payment}/refund).
            }

            $locked->save();

            return $locked->fresh();
        });

        if ($decision === CancellationDecision::Approved) {
            $fresh->client->notify(new BookingCancelledNotification($fresh));
    $fresh->photographer->notify(new BookingCancelledNotification($fresh));

    // If the cancelled booking itself held an unpaid offer, pass that slot on.
    app(OfferReleasedSlotAction::class)->passOnOffer($fresh);

            // Tell the photographer which waitlisted clients can now be offered the slot.
            if ($heldSlot) {
                app(OfferReleasedSlotAction::class)->execute($fresh);
            }
        } else {
            $fresh->client->notify(new CancellationRequestRejectedNotification($fresh));
        }

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: $decision === CancellationDecision::Approved
                ? 'booking.cancellation_approved'
                : 'booking.cancellation_rejected',
            description: $decision === CancellationDecision::Approved
                ? "Approved cancellation for booking #{$fresh->id}"
                : "Declined cancellation request for booking #{$fresh->id}",
        );

        return $fresh;
    }
}