<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

class RequestBookingCancellationAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, string $reason): Booking
    {
        // BookingStatus::Accepted does not exist (removed from the enum) —
        // the previous check referencing it was a fatal error, so
        // cancellation was broken for every booking. Fixed to the real
        // rule: Pending, or Confirmed before the event/service has started.
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


        $booking->update([
            'cancellation_reason' => $reason,
            'cancellation_requested_at' => now(),
            'cancellation_decision' => null,
            'cancellation_decided_at' => null,
        ]);

        $fresh = $booking->fresh();
        $fresh->photographer->notify(new \App\Notifications\Booking\CancellationRequestedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.cancellation_requested',
            description: "{$fresh->client->name} requested cancellation for booking #{$fresh->id}",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }

        public function hasPendingRescheduleRequest(): bool
    {
        return $this->reschedule_requested_at !== null && $this->reschedule_decision === null;
    }
}