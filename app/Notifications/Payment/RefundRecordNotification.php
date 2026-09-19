<?php

namespace App\Notifications\Payment;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RefundRecordedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Payment $payment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment.refund_recorded',
            'payment_id' => $this->payment->id,
            'booking_id' => $this->payment->booking_id,
            'refund_status' => $this->payment->refund_status->value,
            'refund_amount' => $this->payment->refund_amount,
            'message' => match ($this->payment->refund_status->value) {
                'pending' => 'A refund is being reviewed for your booking.',
                'partial' => 'A partial refund of ₱'.number_format((float) $this->payment->refund_amount, 2).' has been recorded for your booking.',
                'full' => 'A full refund of ₱'.number_format((float) $this->payment->refund_amount, 2).' has been recorded for your booking.',
                default => 'There is an update on your refund request.',
            },
        ];
    }
}