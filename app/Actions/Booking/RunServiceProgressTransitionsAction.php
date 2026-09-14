<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;

class RunServiceProgressTransitionsAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(): array
    {
        return [
            'moved_to_event_day' => $this->markEventDay(),
        ];
    }

    private function markEventDay(): int
    {
        $bookings = Booking::with('client')
            ->where('status', BookingStatus::Confirmed)
            ->where('service_status', ServiceTrackerStatus::Upcoming)
            ->whereRaw("CONCAT(event_date, ' ', start_time) <= ?", [now()->format('Y-m-d H:i:s')])
            ->get();

        foreach ($bookings as $booking) {
            $booking->update([
                'service_status' => ServiceTrackerStatus::EventDay,
                'service_status_updated_at' => now(),
            ]);

            $this->activityLogger->execute(
                causer: null,
                subject: $booking,
                action: 'booking.service_tracker_updated',
                description: "Booking #{$booking->id} automatically moved to Event Day.",
            );
        }

        return $bookings->count();
    }

    // NOTE: booking completion is intentionally NOT handled here. Reaching
    // Delivered no longer auto-completes a booking — completion only happens
    // when the photographer explicitly clicks "Mark Service as Completed"
    // (MarkServiceCompletedAction / POST .../bookings/{booking}/complete).
    // No time-based, coverage-duration-based, or buffer-based completion
    // exists anywhere in this action.
}