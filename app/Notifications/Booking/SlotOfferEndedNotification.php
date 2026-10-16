<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A released-schedule offer ended without payment: the client declined it, or the
 * payment deadline passed. The booking goes BACK to the waitlist (it is not cancelled
 * and not expired), so the photographer can offer the slot to someone else.
 */
class SlotOfferEndedNotification extends Notification
{
    use Queueable;

    /** @param string $reason 'declined' | 'lapsed' */
    public function __construct(
        protected Booking $booking,
        protected string $reason,
        protected string $message,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking.offer_'.$this->reason,
            'booking_id' => $this->booking->id,
            'message' => $this->message,
        ];
    }
}