<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

class RequestBookingModificationAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, string $type, string $reason): Booking
    {
        if (! $booking->isEligibleForCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can no longer be modified — the service has already started.'],
            ]);
        }

        $booking->update([
            'modification_type' => $type,
            'modification_reason' => $reason,
            'modification_requested_at' => now(),
        ]);

        $fresh = $booking->fresh();
        $fresh->photographer->notify(new \App\Notifications\Booking\BookingModificationRequestedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.modification_requested',
            description: "Requested a '{$type}' modification for booking #{$fresh->id}",
            metadata: ['type' => $type, 'reason' => $reason],
        );

        return $fresh;
    }
}