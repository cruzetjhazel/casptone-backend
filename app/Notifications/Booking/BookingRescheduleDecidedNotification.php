<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Enums\CancellationDecision;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingRescheduleDecidedNotification extends Notification
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
            'type' => 'booking.reschedule_decided',
            'booking_id' => $this->booking->id,
            'message' => $this->decision === CancellationDecision::Approved
                ? 'Your reschedule request was approved. Your booking is now on '
                    .Carbon::parse($this->booking->event_date)->format('M j, Y')
                    .' at '.Carbon::parse($this->booking->start_time)->format('g:i A').'.'
                : 'Your reschedule request was declined. The booking keeps its original date and time.',
        ];
    }
}