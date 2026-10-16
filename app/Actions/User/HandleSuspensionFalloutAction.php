<?php

namespace App\Actions\User;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMatchingStatus;
use App\Enums\RefundStatus;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\SuspensionAffectedBookingNotification;
use Illuminate\Support\Facades\DB;

class HandleSuspensionFalloutAction
{
    /** @return array{cancelled:int, needs_review:int} */
    public function execute(User $suspended, User $admin): array
    {
        $cancelled = 0;
        $needsReview = 0;
        $log = app(LogActivityAction::class);

        DB::transaction(function () use ($suspended, $admin, $log, &$cancelled, &$needsReview) {
            $bookings = Booking::query()
                ->where(fn ($q) => $q->where('client_id', $suspended->id)->orWhere('photographer_id', $suspended->id))
                ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
                ->with(['client', 'photographer', 'payments'])
                ->get();

            foreach ($bookings as $booking) {
                $suspendedIsClient = $booking->client_id === $suspended->id;
                $role = $suspendedIsClient ? 'client' : 'photographer';
                $other = $suspendedIsClient ? $booking->photographer : $booking->client;

                if ($booking->status === BookingStatus::Pending) {
                    $reason = "The {$role}'s account was suspended.";

                    $booking->update([
                        'status' => BookingStatus::Cancelled,
                        'payment_status' => BookingPaymentStatus::Cancelled,
                        'cancellation_reason' => $reason,
                        'cancelled_by' => 'admin',
                    ]);

                    // Money is only recorded, never held: flag any submitted payment
                    // so the admin settles it from Admin Payments > Record Refund.
                    foreach ($booking->payments as $payment) {
                        $alreadyHandled = $payment->refund_status !== null && $payment->refund_status !== RefundStatus::None;
                        $rejected = $payment->matching_status === PaymentMatchingStatus::Rejected;
                        if ($alreadyHandled || $rejected) {
                            continue;
                        }
                        $payment->update([
                            'refund_status' => RefundStatus::Pending,
                            'refund_notes' => "Booking cancelled because the {$role} account was suspended. Needs admin review.",
                        ]);
                    }

                    $log->execute(
                        causer: $admin,
                        subject: $booking,
                        action: 'booking.cancelled_on_suspension',
                        description: "Booking auto-cancelled because the {$role} ({$suspended->name}, user #{$suspended->id}) was suspended.",
                    );
                    $cancelled++;
                    $outcome = 'cancelled';
                } else {
                    $log->execute(
                        causer: $admin,
                        subject: $booking,
                        action: 'booking.review_on_suspension',
                        description: "Confirmed booking needs admin review: the {$role} ({$suspended->name}, user #{$suspended->id}) was suspended.",
                    );
                    $needsReview++;
                    $outcome = 'review';
                }

                $other?->notify(new SuspensionAffectedBookingNotification($booking, $outcome, $role));
            }
        });

        return ['cancelled' => $cancelled, 'needs_review' => $needsReview];
    }
}