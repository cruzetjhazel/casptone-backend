<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Enums\CancellationDecision;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingModificationDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Booking $booking, protected CancellationDecision $decision)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking.modification_decided',
            'booking_id' => $this->booking->id,
            'message' => $this->decision === CancellationDecision::Approved
                ? 'Your request to update the booking details was approved and the booking has been updated.'
                : 'Your request to update the booking details was declined. The booking stays as it was.',
        ];
    }
}