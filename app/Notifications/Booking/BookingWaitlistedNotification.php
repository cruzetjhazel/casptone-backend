<?php

namespace App\Notifications\Booking;

use App\Enums\BookingPaymentStatus;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to a client whose accepted booking could not keep the schedule because another
 * booking was paid and confirmed for the same time. The booking is NOT cancelled: it
 * waits, and may be offered the slot if that other booking is cancelled.
 */
class BookingWaitlistedNotification extends Notification
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
        $message = 'Another booking currently holds this schedule. Your reservation may be considered if the slot becomes available.';

        if ($this->booking->payment_status === BookingPaymentStatus::PendingVerification) {
            $message .= ' The payment reference you submitted is kept on record and has not been cancelled.';
        }

        return [
            'type' => 'booking.waitlisted',
            'booking_id' => $this->booking->id,
            'message' => $message,
        ];
    }
}
