<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Models\BookingExtension;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingExtensionRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Booking $booking, protected BookingExtension $extension)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking.extension_requested',
            'booking_id' => $this->booking->id,
            'extension_id' => $this->extension->id,
            'message' => "The client requested {$this->extension->requested_hours} additional hour(s) of coverage for booking #{$this->booking->id}. Additional charge: ₱" . number_format((float) $this->extension->additional_charge, 2) . '.',
        ];
    }
}
