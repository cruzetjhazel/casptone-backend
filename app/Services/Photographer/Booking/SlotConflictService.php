<?php

namespace App\Services\Photographer\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

/**
 * Business rule: a date/time slot is only ever truly "taken" once a booking for it has a
 * CONFIRMED payment (partially or fully paid). Until then, multiple overlapping Pending or
 * Confirmed-but-unpaid bookings are allowed to coexist for the same photographer/slot (backup
 * requests in case a client cancels or never pays) and they do NOT block the public calendar.
 *
 * This service is the single place that (a) checks whether a *paid* conflict already exists
 * before a payment is allowed to confirm a booking, and (b) once a booking's payment IS
 * confirmed, moves every other accepted/waitlisted booking for the same slot onto the WAITLIST.
 * Nothing is ever cancelled automatically.
 *
 * "Overlap" uses the same rule as the public calendar: a booking occupies each of its sessions
 * PLUS the photographer's buffer time after it (see Booking::occupiedWindows()). A booking can
 * hold several sessions; every session with a known end time is checked separately. A session
 * whose duration hasn't been confirmed (end_time null) is skipped: we never guess a window.
 *
 * Callers must run these methods inside a DB transaction with the photographer row locked
 * (`User::lockForUpdate()`), so two payments confirming at the same instant can't both win.
 */
class SlotConflictService
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /**
     * The OTHER booking (same photographer) that already holds a paid, confirmed slot
     * overlapping ANY of $booking's sessions, or null. A booking with a pending
     * cancellation request still holds its slot: it is only released once the
     * cancellation is APPROVED (status becomes Cancelled).
     */
    public function findPaidConflict(Booking $booking): ?Booking
    {
        return $booking->overlappingBookings([BookingStatus::Confirmed])
            ->first(fn (Booking $other) => in_array($other->payment_status, [
                BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid,
            ], true));
    }

    /**
     * Call this immediately before marking $booking's payment as confirmed. Throws if some
     * OTHER booking already has a confirmed payment overlapping ANY of this booking's
     * sessions — this booking lost the race and its payment needs manual resolution
     * (refund / another slot). The payment record itself is never discarded.
     */
    public function assertNoPaidConflict(Booking $booking): void
    {
        if ($this->findPaidConflict($booking) !== null) {
            throw ValidationException::withMessages([
                'booking' => [
                    'One of this booking\'s sessions was already paid for and confirmed by another client. '
                    .'This payment was NOT applied and the booking stays on the waitlist. '
                    .'Please contact the client about a refund or a new slot.',
                ],
            ]);
        }
    }

    /**
     * True while the released slot has been offered to a booking that has not paid yet
     * (offered = Confirmed + slot_offered_at set, payment still pending or under review).
     * Only ONE such offer may be outstanding per slot, so two waitlisted clients are never
     * both pushed back to Confirmed for the same time.
     */
    public function hasOutstandingOffer(Booking $booking): bool
    {
        return $booking->overlappingBookings([BookingStatus::Confirmed])
            ->contains(fn (Booking $other) => $other->slot_offered_at !== null
                && in_array($other->payment_status, [
                    BookingPaymentStatus::Pending, BookingPaymentStatus::PendingVerification,
                ], true));
    }

    /**
     * Call this right after $holder's payment is confirmed. Every other ACCEPTED booking
     * (Confirmed, not yet paid) or already-Waitlisted booking that overlaps ANY of the
     * holder's sessions is moved to / kept on the WAITLIST — never cancelled:
     *  - its payment_status and any submitted Payment rows are left exactly as they were;
     *  - its payment window is cleared, so it cannot expire while waiting;
     *  - superseded_by_booking_id records which booking currently holds the slot.
     * Still-Pending requests are left alone (the photographer has not decided on them;
     * AcceptBookingAction waitlists them if they are accepted while the slot is held).
     */
    public function waitlistConflictingBookings(Booking $holder): int
    {
        $rivals = $holder->overlappingBookings([BookingStatus::Confirmed, BookingStatus::Waitlisted])
            // Safety net: never touch a booking that is itself paid-confirmed.
            ->reject(fn (Booking $b) => in_array($b->payment_status, [
                BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid,
            ], true))
            ->values();

        $rivals->load('client');

        foreach ($rivals as $rival) {
               $wasWaitlisted = $rival->status === BookingStatus::Waitlisted;
   \App\Models\BookingSlotOffer::closeOpenFor($rival, \App\Models\BookingSlotOffer::WITHDRAWN);

            $rival->update([
                'status' => BookingStatus::Waitlisted,
                'superseded_by_booking_id' => $holder->id,
                'hold_expires_at' => null,
                'slot_offered_at' => null,
                'slot_offer_accepted_at' => null,
            ]);

            // Already waiting (behind a booking that was since cancelled): just re-point it.
            if ($wasWaitlisted) {
                continue;
            }

            $rival->client->notify(new \App\Notifications\Booking\BookingWaitlistedNotification($rival->fresh()));

            $this->activityLogger->execute(
                causer: null,
                subject: $rival,
                action: 'booking.waitlisted',
                description: "Booking #{$rival->id} waitlisted — booking #{$holder->id} was paid and confirmed for the same slot.",
            );
        }

        return $rivals->count();
    }

    /**
     * Call after a booking that HELD a paid slot is cancelled. Tells the photographer how
     * many waitlisted clients can now be offered the slot. Nothing is offered automatically.
     */
    public function notifySlotReleased(Booking $cancelled): int
    {
        $count = $cancelled->accommodationCandidates()->count();

        if ($count > 0) {
            $cancelled->photographer->notify(new \App\Notifications\Booking\SlotReleasedNotification($cancelled, $count));

            $this->activityLogger->execute(
                causer: null,
                subject: $cancelled,
                action: 'booking.slot_released',
                description: "Schedule of booking #{$cancelled->id} released; {$count} waitlisted booking(s) can be offered it.",
            );
        }

        return $count;
    }
}