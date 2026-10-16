<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One notification for every budget-negotiation step.
 * Types: booking.budget_requested, booking.budget_proposed, booking.budget_declined,
 *        booking.budget_accepted, booking.budget_rejected.
 */
class BudgetNegotiationNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Booking $booking,
        protected string $type,
        protected string $message,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'booking_id' => $this->booking->id,
            'message' => $this->message,
        ];
    }
}