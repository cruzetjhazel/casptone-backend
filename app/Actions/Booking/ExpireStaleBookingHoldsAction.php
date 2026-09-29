<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;

class ExpireStaleBookingHoldsAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(): int
    {
        return $this->expireUndecidedRequests() + $this->expireUnpaidApprovals();
    }

    /** Photographer neither approved nor rejected within the window (or the event already started). */
    protected function expireUndecidedRequests(): int
    {
        $now = now();

        $bookings = Booking::with('client')
            ->where('status', BookingStatus::Pending)
            ->where(function ($q) use ($now) {
                $q->where('hold_expires_at', '<=', $now)
                  ->orWhereRaw("CONCAT(event_date, ' ', start_time) < ?", [$now->format('Y-m-d H:i:s')]);
            })
            ->get();

        foreach ($bookings as $booking) {
            $booking->update([
                'status' => BookingStatus::Expired,
                'cancellation_reason' => 'Request expired — the photographer did not respond in time.',
                'hold_expires_at' => null,
            ]);

            $this->activityLogger->execute(
                causer: null,
                subject: $booking,
                action: 'booking.expired',
                description: "Booking #{$booking->id} expired — no photographer decision within the response window.",
            );
        }

        return $bookings->count();
    }

    /**
     * Approved but the client never paid by the deadline. Only rows still
     * at payment_status = pending are touched, so a payment awaiting
     * verification (pending_verification) or already paid is never expired.
     * whereNotNull keeps older approved bookings that have no deadline safe.
     */
    protected function expireUnpaidApprovals(): int
    {
        $bookings = Booking::with('client')
            ->where('status', BookingStatus::Confirmed)
            ->where('payment_status', BookingPaymentStatus::Pending)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->get();

        foreach ($bookings as $booking) {
            $booking->update([
                'status' => BookingStatus::Expired,
                'cancellation_reason' => 'Booking expired — the reservation payment was not completed in time.',
                'hold_expires_at' => null,
            ]);

            $this->activityLogger->execute(
                causer: null,
                subject: $booking,
                action: 'booking.expired_unpaid',
                description: "Booking #{$booking->id} expired — reservation payment not received before the deadline.",
            );
        }

        return $bookings->count();
    }
}