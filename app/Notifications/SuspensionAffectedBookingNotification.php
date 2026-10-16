<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Notifications\Notification;

class SuspensionAffectedBookingNotification extends Notification
{
    public function __construct(
        private Booking $booking,
        private string $outcome,        // 'cancelled' | 'review'
        private string $suspendedRole,  // 'client' | 'photographer'
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $ref = 'BK-'.str_pad((string) $this->booking->id, 4, '0', STR_PAD_LEFT);
        $who = $this->suspendedRole === 'photographer' ? 'photographer' : 'client';

        if ($this->outcome === 'cancelled') {
            $title = 'Booking cancelled';
            $description = "Booking {$ref} was cancelled because the {$who}'s account was suspended. "
                .'If you already paid, an administrator will review your payment.';
        } else {
            $title = 'Booking under review';
            $description = "The {$who}'s account for booking {$ref} was suspended. "
                .'An administrator is reviewing this booking and will contact you if anything changes.';
        }

        return [
            'type' => 'booking',
            'title' => $title,
            'description' => $description,
            'booking_id' => $this->booking->id,
        ];
    }
}