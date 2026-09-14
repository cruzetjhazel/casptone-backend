<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

class MarkServiceCompletedAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /**
     * Explicit photographer action: Confirmed + service_status=Delivered -> Completed.
     * This is the ONLY path that sets BookingStatus::Completed. There is no
     * time-based, coverage-duration-based, or buffer-based auto-completion
     * anywhere in the system — see RunServiceProgressTransitionsAction and
     * UpdateServiceTrackerStatusAction, neither of which touch `status`
     * once the tracker reaches Delivered.
     */
    public function execute(Booking $booking): Booking
    {
        if (! $booking->canCompleteService()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can only be marked Completed once the service tracker has reached Delivered.'],
            ]);
        }

        $booking->status = BookingStatus::Completed;
        $booking->save();

        $fresh = $booking->fresh();

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'booking.completed',
            description: "Booking #{$fresh->id} marked Completed by the photographer.",
        );

        return $fresh;
    }
}