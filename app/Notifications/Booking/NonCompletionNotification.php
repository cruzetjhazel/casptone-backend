<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NonCompletionNotification extends Notification
{
    use Queueable;

    /** @param 'reported'|'disputed'|'upheld'|'overturned' $event */
    public function __construct(protected Booking $booking, protected string $event)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        [$title, $description] = match ($this->event) {
            'reported' => [
                'No-Show Reported',
                "Booking #{$this->booking->id} was reported as a no-show. You can dispute it within 48 hours.",
            ],
            'disputed' => [
                'No-Show Report Disputed',
                "Your no-show report on booking #{$this->booking->id} was disputed and sent to an admin for review.",
            ],
            'upheld' => [
                'No-Show Report Upheld',
                "An admin upheld the no-show report on booking #{$this->booking->id}.",
            ],
            'overturned' => [
                'No-Show Report Overturned',
                "An admin overturned the no-show report on booking #{$this->booking->id}. The booking is active again.",
            ],
        };

        return [
            'type' => 'booking',
            'title' => $title,
            'description' => $description,
            'booking_id' => (string) $this->booking->id,
            'action' => null,
        ];
    }
}