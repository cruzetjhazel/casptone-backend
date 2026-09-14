<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Models\Booking;
use App\Services\Photographer\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DecideBookingRescheduleAction
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected LogActivityAction $activityLogger,
    ) {
    }

    public function execute(Booking $booking, CancellationDecision $decision): Booking
    {
        if (! $booking->hasPendingRescheduleRequest()) {
            throw ValidationException::withMessages([
                'status' => ['There is no pending reschedule request for this booking.'],
            ]);
        }

        if ($decision === CancellationDecision::Approved) {
            $eventDate = $booking->requested_event_date->format('Y-m-d');
            $startTime = $booking->requested_start_time;
            $neededMinutes = Carbon::parse($booking->start_time)->diffInMinutes(Carbon::parse($booking->end_time));

            $slots = $this->availabilityService->getAvailableStartTimes($booking->photographer, $eventDate, $neededMinutes);

            if (! in_array($startTime, $slots, true)) {
                throw ValidationException::withMessages([
                    'start_time' => ['That slot is no longer available — ask the client to pick another time.'],
                ]);
            }

            $endTime = Carbon::parse($startTime)->addMinutes($neededMinutes)->format('H:i');

            $booking->update([
                'event_date' => $eventDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => BookingStatus::Confirmed,
            ]);
        }

        $booking->reschedule_decision = $decision;
        $booking->reschedule_decided_at = now();
        $booking->save();

        $fresh = $booking->fresh();
        $fresh->client->notify(new \App\Notifications\Booking\BookingRescheduleDecidedNotification($fresh, $decision));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: $decision === CancellationDecision::Approved ? 'booking.reschedule_approved' : 'booking.reschedule_rejected',
            description: $decision === CancellationDecision::Approved
                ? "Approved reschedule for booking #{$fresh->id}"
                : "Rejected reschedule request for booking #{$fresh->id}",
        );

        return $fresh;
    }
}