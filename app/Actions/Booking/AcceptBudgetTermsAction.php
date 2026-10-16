<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;
use App\Models\User;
use App\Notifications\Booking\BookingWaitlistedNotification;
use App\Notifications\Booking\BudgetNegotiationNotification;
use App\Services\Photographer\Booking\BookingDeadlineService;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The client accepts the photographer's proposed terms. This is the ONLY moment the booking's
 * price changes. It then follows exactly the same rules as AcceptBookingAction:
 *
 *   - no paid booking holds the slot -> Confirmed, payment Pending, payment deadline starts
 *     (the booking counts as paid only after the photographer verifies a payment);
 *   - another booking was paid meanwhile -> Waitlisted. The negotiated terms are KEPT but the
 *     booking cannot be paid; the normal waitlist / offer flow takes over.
 *
 * Lock order: photographer row, booking row, request row.
 */
class AcceptBudgetTermsAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected BookingDeadlineService $deadlines,
        protected SlotConflictService $conflicts,
    ) {
    }

    public function execute(Booking $booking, User $client): Booking
    {
        $fresh = DB::transaction(function () use ($booking) {
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['These terms can no longer be accepted because the booking is no longer pending.'],
                ]);
            }

            $request = BookingBudgetRequest::openFor($locked, lock: true);

            if ($request === null || $request->status !== BookingBudgetRequest::PROPOSED) {
                throw ValidationException::withMessages([
                    'status' => ['There are no terms from the photographer waiting for your answer.'],
                ]);
            }

            if ($request->isStale() || $locked->isHoldExpired()) {
                throw ValidationException::withMessages([
                    'status' => ['These terms have expired and can no longer be accepted.'],
                ]);
            }

            $terms = [
                'subtotal' => $request->proposed_subtotal,
                'total_price' => $request->proposed_total,
            ];

            $holder = $this->conflicts->findPaidConflict($locked);

            if ($holder !== null) {
                $locked->update($terms + [
                    'status' => BookingStatus::Waitlisted,
                    'superseded_by_booking_id' => $holder->id,
                    'hold_expires_at' => null,
                    'slot_offered_at' => null,
                ]);
            } else {
                // Throws (rolling everything back) if the event is too close to collect a payment.
                $deadline = $this->deadlines->paymentDeadline($locked);

                $locked->update($terms + [
                    'status' => BookingStatus::Confirmed,
                    'hold_expires_at' => $deadline,
                ]);
            }

            $request->update([
                'status' => BookingBudgetRequest::ACCEPTED,
                'open_booking_id' => null,
                'responded_at' => now(),
            ]);

            return $locked->fresh();
        });

        $waitlisted = $fresh->status === BookingStatus::Waitlisted;

        if ($waitlisted) {
            $fresh->client->notify(new BookingWaitlistedNotification($fresh));
        }

        $fresh->photographer->notify(new BudgetNegotiationNotification(
            $fresh,
            'booking.budget_accepted',
            $waitlisted
                ? "{$fresh->client->name} accepted your terms for booking #{$fresh->id}, but another paid booking holds the schedule, so it is on the waitlist."
                : "{$fresh->client->name} accepted your terms for booking #{$fresh->id}. They can now pay ₱".number_format((float) $fresh->total_price, 2).'.',
        ));

        $this->activityLogger->execute(
            causer: $client,
            subject: $fresh,
            action: 'booking.budget_accepted',
            description: "Client accepted the negotiated terms for booking #{$fresh->id}".($waitlisted ? ' (waitlisted: slot already taken).' : '.'),
        );

        return $fresh;
    }
}