<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Accommodate Other Reservation" (booking-availability rule, part 2).
 *
 * When a client with a paid/confirmed booking cancels, the date/time is
 * freed up but the system does NOT automatically reassign it to whichever
 * other client requested it first — the photographer explicitly chooses
 * who, if anyone, to accommodate from the list of clients who previously
 * lost that same slot (Booking::accommodationCandidates()).
 *
 * Reuses the existing Pending/Confirmed status system: accommodating a
 * candidate puts it in exactly the state AcceptBookingAction would have
 * left it in (Confirmed, payment_status Pending) so the client proceeds
 * through the normal payment flow. The slot only becomes blocked again once
 * that payment is actually confirmed (SlotConflictService, unchanged).
 */
class AccommodateBookingAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
        protected \App\Services\Photographer\Booking\BookingDeadlineService $deadlines,
    ) {
    }

        /**
     * @param  Booking  $cancelledBooking  The previously PAID booking that was cancelled and released the slot.
     * @param  Booking  $candidate  The waitlisted booking the photographer chooses to offer the slot to.
     */
    public function execute(Booking $cancelledBooking, Booking $candidate): Booking
    {
        if ($cancelledBooking->status !== BookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'booking' => ['Only a cancelled booking can have its slot offered to another client.'],
            ]);
        }

        $accommodated = DB::transaction(function () use ($cancelledBooking, $candidate) {
            // Same lock order as payment verification / cancellation approval: photographer
            // row first, then the booking. Two offers or an offer + a payment verification
            // for the same slot can never run at the same time.
            User::where('id', $candidate->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            // Must still be a waiting booking for THIS slot — re-derived under the lock,
            // never trusted from the request. Expired / cancelled / declined / already
            // confirmed bookings are not candidates, so they are never reactivated.
            $isValidCandidate = $cancelledBooking->accommodationCandidates()
                ->where('id', $locked->id)
                ->exists();

            if (! $isValidCandidate) {
                throw ValidationException::withMessages([
                    'candidate_booking_id' => ['This booking is not an eligible candidate for this slot.'],
                ]);
            }

            // Only ONE unpaid offer per slot: never push several waitlisted clients back to Confirmed.
            if ($this->slotConflictService->hasOutstandingOffer($locked)) {
                throw ValidationException::withMessages([
                    'candidate_booking_id' => ['This schedule has already been offered to another client who has not paid yet. Wait until that client pays or the offer lapses.'],
                ]);
            }

            $this->slotConflictService->assertNoPaidConflict($locked);

            // A payment reference the client submitted while waiting is kept as it is, so it
            // is not orphaned — the photographer can verify it. Otherwise a fresh payment
            // window starts now (this throws if the event is too close to collect a payment).
            $paymentAlreadySubmitted = $locked->payment_status === BookingPaymentStatus::PendingVerification;

            $locked->update([
                'status' => BookingStatus::Confirmed,
                'payment_status' => $paymentAlreadySubmitted
                    ? BookingPaymentStatus::PendingVerification
                    : BookingPaymentStatus::Pending,
                'hold_expires_at' => $paymentAlreadySubmitted ? null : $this->deadlines->paymentDeadline($locked),
                'superseded_by_booking_id' => null,
                   'slot_offered_at' => now(),
   'slot_offer_accepted_at' => $paymentAlreadySubmitted ? now() : null,
            ]);

               \App\Models\BookingSlotOffer::create([
       'booking_id' => $locked->id,
       'released_booking_id' => $cancelledBooking->id,
       'status' => $paymentAlreadySubmitted ? 'accepted' : 'offered',
       'offered_at' => now(),
       'expires_at' => $locked->hold_expires_at,
       'responded_at' => $paymentAlreadySubmitted ? now() : null,
   ]);

   return $locked->fresh();
        });

        $accommodated->client->notify(new \App\Notifications\Booking\BookingAccommodatedNotification($accommodated));

        $this->activityLogger->execute(
            causer: $accommodated->photographer,
            subject: $accommodated,
            action: 'booking.accommodated',
            description: "Booking #{$accommodated->id} was offered the schedule released by booking #{$cancelledBooking->id} ({$accommodated->client->name}).",
        );

        return $accommodated;
    }
}