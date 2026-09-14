<?php

namespace App\Observers;

use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;

class BookingObserver
{
    /**
     * Automatically advances a Confirmed booking's service tracker from its
     * pre-payment null state to Upcoming, the moment payment_status settles
     * (partially_paid or fully_paid) — regardless of which action caused the
     * save (online payment verification, onsite payment recording, GCash
     * reference approval, etc). Not time-based; purely payment-driven, per
     * spec. No-ops once service_status has already moved past null, so it
     * never regresses a booking that's further along the tracker.
     */
    public function saved(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Confirmed || $booking->service_status !== null) {
            return;
        }

        if (! $booking->isPaymentSettled()) {
            return;
        }

        $booking->service_status = ServiceTrackerStatus::Upcoming;
        $booking->service_status_updated_at = now();
        $booking->saveQuietly();
    }
}