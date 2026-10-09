<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

class AcceptBookingAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected \App\Services\Photographer\Booking\BookingDeadlineService $deadlines,
    ) {
    }

    public function execute(Booking $booking): Booking
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only pending booking requests can be accepted.'],
            ]);
        }

        if ($booking->isHoldExpired()) {
            throw ValidationException::withMessages([
                'status' => ['The response window for this request has passed, so it can no longer be accepted.'],
            ]);
        }

        // Throws if the event is too close to collect a payment; nothing is saved then.
        $paymentDeadline = $this->deadlines->paymentDeadline($booking);

        $booking->update([
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => $paymentDeadline, // client's payment deadline
        ]);

        $fresh = $booking->fresh();
        $fresh->client->notify(new \App\Notifications\Booking\BookingAcceptedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'booking.accepted',
            description: "Accepted booking #{$fresh->id} for {$fresh->client->name}",
        );

        return $fresh;
    }
}