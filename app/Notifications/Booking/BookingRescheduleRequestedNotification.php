<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingRescheduleRequestedNotification extends Notification
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
            'type' => 'booking.reschedule_requested',
            'booking_id' => $this->booking->id,
            'message' => "{$this->booking->client->name} asked to move their booking to "
                .Carbon::parse($this->booking->requested_event_date)->format('M j, Y')
                .' at '.Carbon::parse($this->booking->requested_start_time)->format('g:i A').'.',
        ];
    }
}