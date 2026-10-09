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

        if ($booking->hasPendingNoShowReport()) {
            throw ValidationException::withMessages([
                'status' => ['A no-show report on this booking is waiting for admin review. It cannot be marked Completed until it is resolved.'],
            ]);
        }

        $blockedUntil = $booking->completionBlockedUntil();

        if ($blockedUntil !== null) {
            throw ValidationException::withMessages([
                'status' => ['This booking can be marked Completed after '.$blockedUntil->format('M j, Y g:i A').', once the client\'s 48-hour window to report a problem has ended.'],
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