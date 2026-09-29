<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingExtensionStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\BookingExtension;
use App\Models\BookingSchedule;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DecideBookingExtensionAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
    ) {
    }

    public function execute(BookingExtension $extension, BookingExtensionStatus $decision, ?string $declineReason = null): BookingExtension
    {
        if ($extension->status !== BookingExtensionStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['This extension request has already been decided.'],
            ]);
        }

        $booking = $extension->booking;
        // Null booking_schedule_id -> the primary schedule (booking's own
        // columns); otherwise -> that specific BookingSchedule row. Either
        // way we only ever need its date + current end time to compute the
        // new end time, and a setter to persist it once approved.
        $target = $extension->booking_schedule_id ? $extension->schedule : $booking;
        $targetDate = $target->event_date->format('Y-m-d');

        if ($decision === BookingExtensionStatus::Approved) {
            $newEndTime = Carbon::parse("{$targetDate} {$target->end_time}")->addHours($extension->requested_hours);

            // Guard against extending into a slot another paid/confirmed
            // booking (or one of ITS additional schedules) already
            // occupies — same rule CreateBookingAction::assertNoConflict
            // applies to a brand-new booking/schedule.
            $conflict = $booking->photographer->bookingsAsPhotographer()
                ->where('id', '!=', $booking->id)
                ->where('event_date', $targetDate)
                ->where('status', BookingStatus::Confirmed)
                ->whereIn('payment_status', [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid])
                ->where('start_time', '<', $newEndTime->format('H:i:s'))
                ->where('end_time', '>', $target->end_time)
                ->exists();

            if (! $conflict) {
                $conflict = BookingSchedule::query()
                    ->where('booking_id', '!=', $booking->id)
                    ->where('event_date', $targetDate)
                    ->where('start_time', '<', $newEndTime->format('H:i:s'))
                    ->where('end_time', '>', $target->end_time)
                    ->whereHas('booking', fn ($q) => $q
                        ->where('photographer_id', $booking->photographer_id)
                        ->where('status', BookingStatus::Confirmed)
                        ->whereIn('payment_status', [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid])
                    )
                    ->exists();
            }

            if ($conflict) {
                throw ValidationException::withMessages([
                    'additional_hours' => ['That much additional time overlaps another confirmed booking — try a shorter extension.'],
                ]);
            }

            $extension->new_end_time = $newEndTime->format('H:i');

            // The extension is recorded separately (additional_charge stays
            // on the BookingExtension row, never folded into
            // bookings.total_price / the package snapshot — see spec: "Do
            // not silently increase the original booking total"), but the
            // TARGET schedule's end_time DOES move so the calendar/
            // availability correctly blocks the extra time against other
            // clients. Only the one schedule being extended changes — every
            // other schedule on this booking (and the package snapshot
            // itself) is untouched.
            $target->end_time = $extension->new_end_time;
            $target->save();
        }

        $extension->status = $decision;
        $extension->decided_at = now();
        $extension->decline_reason = $decision === BookingExtensionStatus::Declined ? $declineReason : null;
        $extension->save();

        $fresh = $extension->fresh();
        $booking->client->notify(
            new \App\Notifications\Booking\BookingExtensionDecidedNotification($booking, $fresh)
        );

        $this->activityLogger->execute(
            causer: $booking->photographer,
            subject: $booking,
            action: $decision === BookingExtensionStatus::Approved ? 'booking.extension_approved' : 'booking.extension_declined',
            description: $decision === BookingExtensionStatus::Approved
                ? "Approved {$extension->requested_hours}-hour extension for booking #{$booking->id}"
                : "Declined extension request for booking #{$booking->id}",
        );

        return $fresh;
    }
}
