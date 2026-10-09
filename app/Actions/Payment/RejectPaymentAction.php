<?php

namespace App\Actions\Payment;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\PaymentMatchingStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RejectPaymentAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Payment $payment, User $verifier, string $notes): Payment
    {
        if (! $payment->isAwaitingManualReview()) {
            throw ValidationException::withMessages([
                'payment' => ['Only payments awaiting photographer confirmation can be rejected.'],
            ]);
        }

        $payment->update([
            'matching_status' => PaymentMatchingStatus::Rejected,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'verification_action' => 'rejected',
            'verification_notes' => $notes,
        ]);

        // Reopen the booking for a fresh payment submission attempt.
        $payment->booking->update(['payment_status' => BookingPaymentStatus::Pending]);

        $freshPayment = $payment->fresh();
        $freshPayment->client->notify(new \App\Notifications\Payment\PaymentRejectedNotification($freshPayment));

        $this->activityLogger->execute(
            causer: $verifier,
            subject: $freshPayment,
            action: 'payment.rejected',
            description: "Rejected payment for booking #{$freshPayment->booking_id}",
            metadata: ['notes' => $notes],
        );

        return $freshPayment;
    }
}