<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingExtensionStatus;
use App\Models\Booking;
use App\Models\BookingExtension;
use App\Models\BookingSchedule;
use Illuminate\Validation\ValidationException;

class RequestBookingExtensionAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
    ) {
    }

    /**
     * $scheduleId targets one specific BookingSchedule row for a
     * multi-schedule booking (e.g. "extend the Wedding on Oct 20, not the
     * Prenup on Oct 5"). Null targets the primary schedule (the booking's
     * own event_date/start_time/end_time) — the only option that existed
     * before multi-schedule bookings, and still the common case since most
     * bookings have just the one schedule.
     */
    public function execute(Booking $booking, int $additionalHours, ?int $scheduleId = null): BookingExtension
    {
        if (! $booking->isEligibleForExtensionRequest()) {
            throw ValidationException::withMessages([
                'status' => ['Additional coverage can only be requested for a confirmed sliding-hours custom booking.'],
            ]);
        }

        if ($scheduleId !== null && ! BookingSchedule::where('id', $scheduleId)->where('booking_id', $booking->id)->exists()) {
            throw ValidationException::withMessages([
                'schedule_id' => ['That schedule does not belong to this booking.'],
            ]);
        }

        $extensionTarget = $scheduleId !== null ? BookingSchedule::find($scheduleId) : $booking;
        if ($extensionTarget->end_time === null) {
            throw ValidationException::withMessages([
                'schedule_id' => ['That schedule\'s duration has not been confirmed yet, so it cannot be extended.'],
            ]);
        }

        // Kept simple as one pending extension per BOOKING (not per
        // schedule) — a client with a multi-schedule booking still has to
        // wait for one request to be decided before requesting another,
        // even against a different schedule entry. Concurrent pending
        // extensions across schedules isn't a case any of the examples in
        // spec called for, and avoiding it keeps DecideBookingExtensionAction
        // from having to reason about overlapping in-flight requests.
        if ($booking->hasPendingExtensionRequest()) {
            throw ValidationException::withMessages([
                'status' => ['An extension request is already pending for this booking.'],
            ]);
        }

        $config = $booking->photographer->customPackageConfig;

        if (! $config || $config->hourly_rate === null) {
            throw ValidationException::withMessages([
                'status' => ['This photographer no longer offers sliding-hours pricing, so additional coverage can\'t be priced.'],
            ]);
        }

        $hourlyRate = (float) $config->hourly_rate;

        $extension = BookingExtension::create([
            'booking_id' => $booking->id,
            'booking_schedule_id' => $scheduleId,
            'requested_hours' => $additionalHours,
            'hourly_rate' => $hourlyRate,
            'additional_charge' => $hourlyRate * $additionalHours,
            'status' => BookingExtensionStatus::Pending,
            'requested_at' => now(),
        ]);

        $booking->photographer->notify(
            new \App\Notifications\Booking\BookingExtensionRequestedNotification($booking, $extension)
        );

        $this->activityLogger->execute(
            causer: $booking->client,
            subject: $booking,
            action: 'booking.extension_requested',
            description: "Requested {$additionalHours} additional hour(s) for booking #{$booking->id}",
            metadata: ['extension_id' => $extension->id, 'additional_charge' => $extension->additional_charge],
        );

        return $extension;
    }
}
