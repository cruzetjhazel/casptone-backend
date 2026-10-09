<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingModificationRequestedNotification extends Notification
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
            'type' => 'booking.modification_requested',
            'booking_id' => $this->booking->id,
            'message' => "{$this->booking->client->name} asked to update the details of their booking.",
        ];
    }
}