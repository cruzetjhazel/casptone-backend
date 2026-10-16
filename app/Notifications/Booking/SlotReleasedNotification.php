<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to the photographer when a paid booking is cancelled and other clients are
 * waiting for that same schedule. Nothing is offered automatically.
 */
class SlotReleasedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Booking $cancelledBooking, protected int $waitingCount)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $clients = $this->waitingCount === 1 ? '1 waitlisted client' : "{$this->waitingCount} waitlisted clients";

        return [
            'type' => 'booking.slot_released',
            // The cancelled booking: its page has the "Accommodate Other Reservation" button.
            'booking_id' => $this->cancelledBooking->id,
            'message' => "A paid booking was cancelled and its schedule is available again. {$clients} can be offered the slot — open the cancelled booking and choose \"Accommodate Other Reservation\".",
        ];
    }
}
