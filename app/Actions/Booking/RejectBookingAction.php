<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

class RejectBookingAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, string $reason): Booking
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only pending booking requests can be rejected.'],
            ]);
        }

        if ($booking->isHoldExpired()) {
            throw ValidationException::withMessages([
                'status' => ['The response window for this request has passed, so it can no longer be declined.'],
            ]);
        }

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'rejection_reason' => $reason,
            'hold_expires_at' => null,
        ]);

        $fresh = $booking->fresh();
        $fresh->client->notify(new \App\Notifications\Booking\BookingRejectedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'booking.rejected',
            description: "Rejected booking #{$fresh->id} for {$fresh->client->name}",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }
}