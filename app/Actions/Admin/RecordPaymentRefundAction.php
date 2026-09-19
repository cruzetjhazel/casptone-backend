<?php

namespace App\Actions\Admin;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only: records that a refund was decided on for a Payment (§16 —
 * client protection / photographer no-show). This does NOT move money —
 * it records the decision so there's an auditable record, matching how the
 * rest of the payment system (GCash reference matching) already works by
 * recording rather than processing transactions. The actual refund (e.g. a
 * manual GCash send back to the client) happens outside the platform.
 */
class RecordPaymentRefundAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Payment $payment, User $admin, string $status, ?float $amount, ?string $notes): Payment
    {
        $refundStatus = RefundStatus::from($status);

        if (in_array($refundStatus, [RefundStatus::Partial, RefundStatus::Full], true)) {
            if ($amount === null || $amount <= 0) {
                throw ValidationException::withMessages([
                    'refund_amount' => ['A refund amount greater than zero is required for this status.'],
                ]);
            }

            if (round($amount, 2) > round((float) $payment->amount, 2)) {
                throw ValidationException::withMessages([
                    'refund_amount' => ['The refund amount cannot exceed the amount actually paid ('.number_format((float) $payment->amount, 2).').'],
                ]);
            }
        }

        $payment->update([
            'refund_status' => $refundStatus,
            'refund_amount' => in_array($refundStatus, [RefundStatus::Partial, RefundStatus::Full], true) ? round($amount, 2) : null,
            'refund_notes' => $notes,
            'refunded_by' => $admin->id,
            'refunded_at' => in_array($refundStatus, [RefundStatus::Partial, RefundStatus::Full], true) ? now() : null,
        ]);

        $fresh = $payment->fresh(['booking.client', 'booking.photographer']);

        if (in_array($refundStatus, [RefundStatus::Pending, RefundStatus::Partial, RefundStatus::Full], true)) {
            $fresh->booking->client->notify(new \App\Notifications\Payment\RefundRecordedNotification($fresh));
        }

        $this->activityLogger->execute(
            causer: $admin,
            subject: $fresh,
            action: 'payment.refund_recorded',
            description: "Recorded refund status '{$refundStatus->value}' for payment #{$fresh->id} (booking #{$fresh->booking_id})",
            metadata: array_filter(['amount' => $amount, 'notes' => $notes]),
        );

        return $fresh;
    }
}