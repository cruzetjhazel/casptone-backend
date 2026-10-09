<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification
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
            'type' => 'booking.cancelled',
            'booking_id' => $this->booking->id,
            'message' => match ($this->booking->cancelled_by) {
                'photographer' => 'The photographer cancelled this booking.',
                'client' => 'The client cancelled this booking.',
                'admin' => 'An administrator cancelled this booking.',
                default => 'This booking has been cancelled.',
            },
        ];
    }
}