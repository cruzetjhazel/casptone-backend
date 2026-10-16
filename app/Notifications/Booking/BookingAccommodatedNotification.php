<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent when a photographer uses "Accommodate Other Reservation" to bring
 * this client's previously auto-declined request back to life after the
 * client who originally won the slot cancelled. Distinct from
 * BookingAcceptedNotification only in wording — the booking is in the same
 * Confirmed-awaiting-payment state either way.
 */
class BookingAccommodatedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Booking $booking)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

        public function toArray(object $notifiable): array
    {
        $paymentSubmitted = $this->booking->payment_status === \App\Enums\BookingPaymentStatus::PendingVerification;

        return [
            'type' => 'booking.accommodated',
            'booking_id' => $this->booking->id,
            'message' => $paymentSubmitted
                ? 'The schedule is available again. Your submitted payment is now waiting for the photographer to confirm it.'
                : 'The schedule is available again. Open your booking and accept the offer before the reservation deadline, then you can pay.',
        ];
    }
}