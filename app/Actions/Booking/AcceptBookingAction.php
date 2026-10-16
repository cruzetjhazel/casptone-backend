<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptBookingAction
{
        public function __construct(
        protected LogActivityAction $activityLogger,
        protected \App\Services\Photographer\Booking\BookingDeadlineService $deadlines,
        protected SlotConflictService $conflicts,
    ) {
    }

    public function execute(Booking $booking): Booking
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only pending booking requests can be accepted.'],
            ]);
        }

        if ($booking->isHoldExpired()) {
            throw ValidationException::withMessages([
                'status' => ['The response window for this request has passed, so it can no longer be accepted.'],
            ]);
        }

        $fresh = DB::transaction(function () use ($booking) {
            // Same lock order as payment verification (photographer row, then the booking).
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending booking requests can be accepted.'],
                ]);
            }

               // Budget negotiation: terms waiting for the client block a plain accept;
   // an unanswered budget request is superseded by it.
   \App\Models\BookingBudgetRequest::guardNormalAccept($locked);

   // Accepting does NOT guarantee the schedule. If another booking already holds a
            // PAID slot that overlaps this one, the request is accepted onto the waitlist
            // instead of being sent to payment.
            $holder = $this->conflicts->findPaidConflict($locked);

            if ($holder !== null) {
                $locked->update([
                    'status' => BookingStatus::Waitlisted,
                    'superseded_by_booking_id' => $holder->id,
                    'hold_expires_at' => null,
                    'slot_offered_at' => null,
                ]);
            } else {
                // Throws if the event is too close to collect a payment; the transaction rolls back.
                $paymentDeadline = $this->deadlines->paymentDeadline($locked);

                $locked->update([
                    'status' => BookingStatus::Confirmed,
                    'hold_expires_at' => $paymentDeadline, // client's payment deadline
                ]);
            }

            return $locked->fresh();
        });

        if ($fresh->status === BookingStatus::Waitlisted) {
            $fresh->client->notify(new \App\Notifications\Booking\BookingWaitlistedNotification($fresh));

            $this->activityLogger->execute(
                causer: $fresh->photographer,
                subject: $fresh,
                action: 'booking.accepted_waitlisted',
                description: "Accepted booking #{$fresh->id} for {$fresh->client->name}, but another paid booking holds the schedule — waitlisted.",
            );

            return $fresh;
        }

        $fresh->client->notify(new \App\Notifications\Booking\BookingAcceptedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'booking.accepted',
            description: "Accepted booking #{$fresh->id} for {$fresh->client->name}",
        );

        return $fresh;
    }
}