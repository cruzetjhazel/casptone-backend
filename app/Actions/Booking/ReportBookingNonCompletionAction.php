<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingNonCompletionReason;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReportBookingNonCompletionAction
{
    public const DISPUTE_WINDOW_HOURS = 48;

    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, User $reporter, BookingNonCompletionReason $reason, ?string $notes): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => ['Only a confirmed booking can be reported as non-completed.'],
            ]);
        }

        // An admin already overturned a report on this booking; don't allow re-filing.
        if ($booking->non_completion_review_status === 'overturned') {
            throw ValidationException::withMessages([
                'status' => ['A previous report on this booking was reviewed and overturned by an admin.'],
            ]);
        }

        // Only reportable once the service was actually due to happen, and
        // only before it has meaningfully progressed — a tracker already at
        // Editing/Delivered/Completed means the photographer clearly showed up.
        if (in_array($booking->service_status, [ServiceTrackerStatus::Editing, ServiceTrackerStatus::Delivered], true)
            || $booking->status === BookingStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['This booking has already progressed past the point of a no-show report.'],
            ]);
        }

        $eventStart = \Illuminate\Support\Carbon::parse("{$booking->event_date->format('Y-m-d')} {$booking->start_time}");
        if ($eventStart->isFuture()) {
            throw ValidationException::withMessages([
                'status' => ['This booking\'s scheduled time has not started yet.'],
            ]);
        }

        // A client can only report the photographer; the photographer can
        // only report the client — prevents self-serving reports.
        $isClient = $reporter->id === $booking->client_id;
        $isPhotographer = $reporter->id === $booking->photographer_id;
        if (($isClient && $reason !== BookingNonCompletionReason::PhotographerNoShow)
            || ($isPhotographer && $reason !== BookingNonCompletionReason::ClientNoShow)
            || (! $isClient && ! $isPhotographer)) {
            throw ValidationException::withMessages([
                'reason' => ['You can only report the other party\'s non-attendance.'],
            ]);
        }

        $booking->status = BookingStatus::Cancelled;
        $booking->non_completion_reason = $reason;
        $booking->non_completion_reported_at = now();
        $booking->non_completion_reported_by = $reporter->id;
        $booking->non_completion_dispute_deadline_at = now()->addHours(self::DISPUTE_WINDOW_HOURS);
        $booking->non_completion_reported_by = $reporter->id;
        $booking->non_completion_dispute_deadline_at = now()->addHours(self::DISPUTE_WINDOW_HOURS);
        if ($notes) {
            $booking->cancellation_reason = $notes;
        }
        $booking->save();

        $fresh = $booking->fresh();
        $other = $isClient ? $fresh->photographer : $fresh->client;
        $other->notify(new \App\Notifications\Booking\NonCompletionNotification($fresh, 'reported'));

        $this->activityLogger->execute(
            causer: $reporter,
            subject: $fresh,
            action: 'booking.non_completion_reported',
            description: "Booking #{$fresh->id} marked not completed: {$reason->value}",
            metadata: ['reason' => $reason->value, 'notes' => $notes],
        );

        return $fresh;
    }
}