<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** The offered client accepted the released schedule and can now pay. Sent to the photographer. */
class SlotOfferAcceptedNotification extends Notification
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
        return [
            'type' => 'booking.offer_accepted',
            'booking_id' => $this->booking->id,
            'message' => "{$this->booking->client->name} accepted the offered schedule for booking #{$this->booking->id} and can now pay.",
        ];
    }
}