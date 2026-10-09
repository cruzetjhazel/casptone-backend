<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Models\Booking;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Notifications\Booking\CancellationRequestedNotification;
use Illuminate\Validation\ValidationException;

/**
 * The client cancels a booking.
 *  - Nothing paid yet  -> cancelled right away (nothing for the photographer to approve).
 *  - Money paid / being verified -> a cancellation REQUEST the photographer approves or declines.
 */
class RequestBookingCancellationAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, string $reason): Booking
    {
        if (! $booking->isEligibleForCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can no longer be cancelled — the service has already started.'],
            ]);
        }

        if ($booking->hasPendingCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['A cancellation request is already pending for this booking.'],
            ]);
        }

        $hasPaymentActivity = in_array($booking->payment_status, [
            BookingPaymentStatus::PendingVerification,
            BookingPaymentStatus::PartiallyPaid,
            BookingPaymentStatus::FullyPaid,
        ], true);

        if (! $hasPaymentActivity) {
            $booking->update([
                'status' => BookingStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancellation_requested_at' => now(),
                'cancellation_decision' => CancellationDecision::Approved,
                'cancellation_decided_at' => now(),
                'cancelled_by' => 'client',
                'hold_expires_at' => null,
            ]);

            $fresh = $booking->fresh();

            $fresh->photographer->notify(new BookingCancelledNotification($fresh));

            $this->activityLogger->execute(
                causer: $fresh->client,
                subject: $fresh,
                action: 'booking.cancelled',
                description: "{$fresh->client->name} cancelled booking #{$fresh->id}",
                metadata: ['reason' => $reason],
            );

            return $fresh;
        }

        $booking->update([
            'cancellation_reason' => $reason,
            'cancellation_requested_at' => now(),
            'cancellation_decision' => null,
            'cancellation_decided_at' => null,
        ]);

        $fresh = $booking->fresh();

        $fresh->photographer->notify(new CancellationRequestedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.cancellation_requested',
            description: "{$fresh->client->name} requested cancellation for booking #{$fresh->id}",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }
}