<?php

namespace App\Actions\Payment;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * Records the onsite remaining balance for a Half-Payment booking (§8.9).
 * The system records the transaction; it does not process the payment itself.
 */
class RecordOnsitePaymentAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, array $data): Payment
    {
        if (! $booking->isEligibleForOnsitePayment()) {
            throw ValidationException::withMessages([
                'booking' => ['This booking has no pending onsite balance.'],
            ]);
        }

        $remaining = $booking->remainingBalance();

        if ((float) $data['amount'] <= 0 || (float) $data['amount'] > $remaining) {
            throw ValidationException::withMessages([
                'amount' => ['The amount must be greater than zero and no more than the remaining balance of '.number_format($remaining, 2).'.'],
            ]);
        }

        $amount = round((float) $data['amount'], 2);
        $isFullyPaid = $amount >= round($remaining, 2);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'photographer_id' => $booking->photographer_id,
            'type' => PaymentType::Onsite,
            'method' => 'cash',
            'plan' => $booking->payment_plan?->value,
            'amount' => $amount,
            'reference_number' => null,
            'payment_date' => $data['payment_date'],
            'notes' => $data['notes'] ?? null,
        ]);

        $booking->update([
            'payment_status' => $isFullyPaid
                ? BookingPaymentStatus::FullyPaid
                : BookingPaymentStatus::PartiallyPaid,
        ]);

        $freshBooking = $booking->fresh();
        $freshPayment = $payment->fresh();

        $freshBooking->client->notify(new \App\Notifications\Payment\OnsitePaymentRecordedNotification($freshPayment));
        if ($isFullyPaid) {
            $freshBooking->client->notify(new \App\Notifications\Payment\FullPaymentCompletedNotification($freshBooking));
            $freshBooking->photographer->notify(new \App\Notifications\Payment\FullPaymentCompletedNotification($freshBooking));
        }

        $this->activityLogger->execute(
            causer: $freshBooking->photographer,
            subject: $freshPayment,
            action: 'payment.onsite_recorded',
            description: "Recorded onsite payment for booking #{$freshBooking->id}",
            metadata: [
                'amount' => $amount,
                'remaining_balance' => $freshBooking->remainingBalance(),
            ],
        );

        return $freshPayment;
    }
}