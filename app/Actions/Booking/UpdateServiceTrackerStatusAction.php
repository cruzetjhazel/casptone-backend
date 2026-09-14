<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Notifications\Booking\ServiceTrackerUpdatedNotification;
use Illuminate\Validation\ValidationException;

class UpdateServiceTrackerStatusAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /**
     * The manual forward-transitions this endpoint allows.
     * Upcoming -> Event Day is now photographer-driven too (previously
     * handled by RunServiceProgressTransitionsAction on a schedule — see
     * routes/console.php, which no longer calls it). Completed is still
     * separate, via MarkServiceCompletedAction.
     */
    private const ALLOWED_TRANSITIONS = [
        'upcoming' => ServiceTrackerStatus::EventDay,
        'event_day' => ServiceTrackerStatus::Editing,
        'editing' => ServiceTrackerStatus::Delivered,
    ];

    public function execute(Booking $booking, ServiceTrackerStatus $status): Booking
    {
        if (! $booking->canManageServiceTracker()) {
            throw ValidationException::withMessages([
                'status' => ['The service tracker is only available for a Confirmed booking.'],
            ]);
        }

        $expected = self::ALLOWED_TRANSITIONS[$booking->service_status?->value] ?? null;

        if ($expected === null || $status !== $expected) {
            throw ValidationException::withMessages([
                'service_status' => ['Invalid service tracker transition from the current stage.'],
            ]);
        }

        $booking->service_status = $status;
        $booking->service_status_updated_at = now();

        // Delivered is the final tracker stage, but it does NOT complete the
        // booking on its own — BookingStatus only moves to Completed when the
        // photographer explicitly clicks "Mark Service as Completed"
        // (see MarkServiceCompletedAction). Service tracker and booking
        // status are intentionally separate concepts.
        $booking->save();

        $fresh = $booking->fresh();
        $fresh->client->notify(new ServiceTrackerUpdatedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'booking.service_tracker_updated',
            description: "Updated service tracker for booking #{$fresh->id} to {$status->value}",
        );

        return $fresh;
    }
}