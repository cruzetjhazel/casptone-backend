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
    ) {
    }

    /**
     * @param  Booking  $cancelledBooking  The previously paid/confirmed booking that was cancelled and freed the slot.
     * @param  Booking  $candidate  The other client's request the photographer is choosing to accommodate.
     */
    public function execute(Booking $cancelledBooking, Booking $candidate): Booking
    {
        if ($cancelledBooking->status !== BookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'booking' => ['Only a cancelled booking can have its slot accommodated to another client.'],
            ]);
        }

        // Must actually be one of the clients who lost this exact slot —
        // re-derives the same query the candidates list uses rather than
        // trusting an arbitrary candidate id from the request.
        $isValidCandidate = $cancelledBooking->accommodationCandidates()
            ->where('id', $candidate->id)
            ->exists();

        if (! $isValidCandidate) {
            throw ValidationException::withMessages([
                'candidate_booking_id' => ['This booking is not an eligible candidate for this slot.'],
            ]);
        }

        $accommodated = DB::transaction(function () use ($candidate) {
            // Same atomicity guard as AcceptBookingAction/payment confirmation:
            // lock the photographer row so a brand-new client can't win the
            // slot through the normal create/pay flow in the same instant
            // the photographer is accommodating someone else for it.
            User::where('id', $candidate->photographer_id)->lockForUpdate()->firstOrFail();

            // assertNoPaidConflict() checks for any OTHER Confirmed +
            // paid booking overlapping this same photographer/slot,
            // excluding $candidate itself — exactly what we need before
            // reviving it.
            $this->slotConflictService->assertNoPaidConflict($candidate);

            $candidate->update([
                'status' => BookingStatus::Confirmed,
                'payment_status' => BookingPaymentStatus::Pending,
                'hold_expires_at' => null,
                'superseded_by_booking_id' => null,
                'cancellation_reason' => null,
                'cancellation_requested_at' => null,
                'cancellation_decision' => null,
                'cancellation_decided_at' => null,
            ]);

            return $candidate->fresh();
        });

        $accommodated->client->notify(new \App\Notifications\Booking\BookingAccommodatedNotification($accommodated));

        $this->activityLogger->execute(
            causer: $accommodated->photographer,
            subject: $accommodated,
            action: 'booking.accommodated',
            description: "Booking #{$accommodated->id} accommodated for {$accommodated->client->name} after booking #{$cancelledBooking->id} was cancelled.",
        );

        return $accommodated;
    }
}