<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Booking;
use App\Services\Photographer\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class RequestBookingRescheduleAction
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected LogActivityAction $activityLogger,
    ) {
    }

    public function execute(Booking $booking, string $eventDate, string $startTime, string $reason): Booking
    {
        if (! $booking->isEligibleForCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can no longer be rescheduled — the service has already started.'],
            ]);
        }

        if ($booking->hasPendingRescheduleRequest()) {
            throw ValidationException::withMessages([
                'status' => ['A reschedule request is already pending for this booking.'],
            ]);
        }

        $neededMinutes = Carbon::parse($booking->start_time)->diffInMinutes(Carbon::parse($booking->end_time));
        $slots = $this->availabilityService->getAvailableStartTimes($booking->photographer, $eventDate, $neededMinutes);

        if (! in_array($startTime, $slots, true)) {
            throw ValidationException::withMessages([
                'start_time' => ['This date and time is not available for booking.'],
            ]);
        }

        $booking->update([
            'requested_event_date' => $eventDate,
            'requested_start_time' => $startTime,
            'reschedule_requested_at' => now(),
            'reschedule_decision' => null,
            'reschedule_decided_at' => null,
            'cancellation_reason' => $booking->cancellation_reason, // untouched, left explicit for clarity
        ]);

        $fresh = $booking->fresh();
        $fresh->photographer->notify(new \App\Notifications\Booking\BookingRescheduleRequestedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.reschedule_requested',
            description: "Requested reschedule to {$eventDate} {$startTime} for booking #{$fresh->id}",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }
}