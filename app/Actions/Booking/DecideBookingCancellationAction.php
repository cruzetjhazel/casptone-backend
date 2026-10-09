<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Models\Booking;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Notifications\Booking\CancellationRequestRejectedNotification;
use Illuminate\Validation\ValidationException;

class DecideBookingCancellationAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, CancellationDecision $decision): Booking
    {
        if (! $booking->hasPendingCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['There is no pending cancellation request for this booking.'],
            ]);
        }

        $booking->cancellation_decision = $decision;
        $booking->cancellation_decided_at = now();

        if ($decision === CancellationDecision::Approved) {
            $booking->status = BookingStatus::Cancelled;
            $booking->cancelled_by = 'client';
            $booking->hold_expires_at = null;
        }

        $booking->save();

        $fresh = $booking->fresh();

        if ($decision === CancellationDecision::Approved) {
            $fresh->client->notify(new BookingCancelledNotification($fresh));
            $fresh->photographer->notify(new BookingCancelledNotification($fresh));
        } else {
            $fresh->client->notify(new CancellationRequestRejectedNotification($fresh));
        }

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: $decision === CancellationDecision::Approved
                ? 'booking.cancellation_approved'
                : 'booking.cancellation_rejected',
            description: $decision === CancellationDecision::Approved
                ? "Approved cancellation for booking #{$fresh->id}"
                : "Declined cancellation request for booking #{$fresh->id}",
        );

        return $fresh;
    }
}