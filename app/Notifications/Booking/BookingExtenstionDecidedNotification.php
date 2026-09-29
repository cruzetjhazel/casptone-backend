<?php

namespace App\Notifications\Booking;

use App\Enums\BookingExtensionStatus;
use App\Models\Booking;
use App\Models\BookingExtension;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingExtensionDecidedNotification extends Notification
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
        $approved = $this->extension->status === BookingExtensionStatus::Approved;

        return [
            'type' => $approved ? 'booking.extension_approved' : 'booking.extension_declined',
            'booking_id' => $this->booking->id,
            'extension_id' => $this->extension->id,
            'message' => $approved
                ? "Your request for {$this->extension->requested_hours} additional hour(s) was approved. Additional charge: ₱" . number_format((float) $this->extension->additional_charge, 2) . ". New coverage end: {$this->extension->new_end_time}."
                : 'Your request for additional coverage was declined by the photographer.',
        ];
    }
}
