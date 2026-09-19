<?php

namespace App\Services\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Validation\ValidationException;

/**
 * Business rule: a date/time slot is only ever truly "taken" once a booking
 * for it has a CONFIRMED payment (partially or fully paid). Until then,
 * multiple overlapping Pending or Confirmed-but-unpaid bookings are allowed
 * to coexist for the same photographer/slot — see CreateBookingAction for
 * why (backup requests in case a client cancels or never pays).
 *
 * This service is the single place that (a) checks whether a *paid* conflict
 * already exists before letting a payment confirm a booking, and (b) once a
 * booking's payment IS confirmed, automatically declines every other
 * unpaid request that overlaps the same slot, so a photographer never ends
 * up needing to remember to clean those up by hand.
 *
 * Callers (SubmitPaymentAction, ManuallyVerifyPaymentAction) must run both
 * methods inside a DB transaction with the photographer row locked
 * (`User::lockForUpdate()`), the same way CreateBookingAction does, so two
 * payments confirming at the same instant can't both win the same slot.
 */
class SlotConflictService
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /**
     * Call this immediately before marking $booking's payment as confirmed.
     * Throws if some OTHER booking for the same photographer/slot already
     * has a confirmed payment — meaning this booking lost the race and its
     * payment needs manual resolution (refund/reassignment) rather than
     * being used to confirm a slot that's already gone.
     */
    public function assertNoPaidConflict(Booking $booking): void
    {
        $conflict = $booking->photographer
            ->bookingsAsPhotographer()
            ->where('id', '!=', $booking->id)
            ->where('event_date', $booking->event_date)
            ->where('status', BookingStatus::Confirmed)
            ->whereIn('payment_status', [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid])
            ->where('start_time', '<', $booking->end_time)
            ->where('end_time', '>', $booking->start_time)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'booking' => [
                    'This time slot was already paid for and confirmed by another client while this payment was pending. '
                    .'This payment needs manual review — it was NOT applied. Please contact the client about a refund or a new slot.',
                ],
            ]);
        }
    }

    /**
     * Call this right after $booking's payment is confirmed. Finds every
     * other Pending or Confirmed-but-unpaid booking overlapping the same
     * photographer/slot and auto-cancels them, notifying each affected
     * client with a clear reason instead of leaving them hanging on a
     * request that can no longer be accepted.
     */
    public function releaseConflictingBookings(Booking $confirmedBooking): int
    {
        $rivals = $confirmedBooking->photographer
            ->bookingsAsPhotographer()
            ->with('client')
            ->where('id', '!=', $confirmedBooking->id)
            ->where('event_date', $confirmedBooking->event_date)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            ->where('start_time', '<', $confirmedBooking->end_time)
            ->where('end_time', '>', $confirmedBooking->start_time)
            ->get()
            // Safety net: never auto-cancel something that is itself
            // already paid-confirmed — assertNoPaidConflict() should have
            // stopped us getting here, but a booking can't be silently
            // cancelled out from under a paying client.
            ->reject(fn (Booking $b) => in_array($b->payment_status, [
                BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid,
            ], true));

        foreach ($rivals as $rival) {
            $rival->update([
                'status' => BookingStatus::Cancelled,
                'payment_status' => BookingPaymentStatus::Cancelled,
                'cancellation_reason' => 'Automatically declined — another client\'s payment for this same date/time was confirmed first.',
            ]);

            $rival->client->notify(new \App\Notifications\Booking\BookingCancelledNotification($rival->fresh()));

            $this->activityLogger->execute(
                causer: null,
                subject: $rival,
                action: 'booking.auto_declined_slot_taken',
                description: "Booking #{$rival->id} auto-declined — booking #{$confirmedBooking->id} was confirmed and paid for the same slot.",
            );
        }

        return $rivals->count();
    }
}