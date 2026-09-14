<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

class ModifyBookingDetailsAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, array $data): Booking
    {
        if (! $booking->isEligibleForCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can no longer be modified — the service has already started.'],
            ]);
        }

        $booking->update(array_intersect_key($data, array_flip([
            'location_type', 'event_address', 'guest_count', 'special_requests',
        ])));

        $fresh = $booking->fresh();

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.modified',
            description: "Updated details for booking #{$fresh->id}",
        );

        return $fresh;
    }
}