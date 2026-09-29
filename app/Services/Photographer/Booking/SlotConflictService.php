<?php

namespace App\Services\Photographer\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Carbon;
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
 * A booking can hold several photography sessions (its own first schedule
 * plus any additional ones). Every session with a known end time is checked
 * separately — against both other bookings' first schedules and their
 * additional sessions. A session whose duration hasn't been confirmed
 * (end_time null) is skipped: we never guess a window for it.
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
     * Other bookings of the same photographer that have ANY session (first or
     * additional) overlapping the given window, limited to $statuses.
     */
    protected function overlappingBookings(Booking $except, string $date, string $start, string $end, array $statuses)
    {
        return $except->photographer->bookingsAsPhotographer()
            ->where('id', '!=', $except->id)
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($date, $start, $end) {
                $q->where(fn ($p) => $p->where('event_date', $date)
                        ->where('start_time', '<', $end)
                        ->where('end_time', '>', $start))
                  ->orWhereHas('schedules', fn ($s) => $s->where('event_date', $date)
                        ->where('start_time', '<', $end)
                        ->where('end_time', '>', $start));
            });
    }

    /**
     * Call this immediately before marking $booking's payment as confirmed.
     * Throws if some OTHER booking for the same photographer already has a
     * confirmed payment overlapping ANY of this booking's sessions — meaning
     * this booking lost the race and its payment needs manual resolution
     * (refund/reassignment) rather than being used to confirm a slot that's
     * already gone.
     */
    public function assertNoPaidConflict(Booking $booking): void
    {
        foreach ($booking->allSchedules() as $schedule) {
            // No confirmed duration = nothing to range-check (never guessed).
            if ($schedule->end_time === null) {
                continue;
            }

            $conflict = $this->overlappingBookings(
                $booking,
                Carbon::parse($schedule->event_date)->toDateString(),
                $schedule->start_time,
                $schedule->end_time,
                [BookingStatus::Confirmed],
            )->whereIn('payment_status', [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid])
             ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'booking' => [
                        'One of this booking\'s sessions was already paid for and confirmed by another client while this payment was pending. '
                        .'This payment needs manual review — it was NOT applied. Please contact the client about a refund or a new slot.',
                    ],
                ]);
            }
        }
    }

    /**
     * Call this right after $booking's payment is confirmed. Finds every
     * other Pending or Confirmed-but-unpaid booking overlapping ANY of this
     * booking's sessions and auto-cancels them, notifying each affected
     * client with a clear reason instead of leaving them hanging on a
     * request that can no longer be accepted.
     */
    public function releaseConflictingBookings(Booking $confirmedBooking): int
    {
        $rivals = collect();

        foreach ($confirmedBooking->allSchedules() as $schedule) {
            if ($schedule->end_time === null) {
                continue;
            }

            $rivals = $rivals->merge(
                $this->overlappingBookings(
                    $confirmedBooking,
                    Carbon::parse($schedule->event_date)->toDateString(),
                    $schedule->start_time,
                    $schedule->end_time,
                    [BookingStatus::Pending, BookingStatus::Confirmed],
                )->with('client')->get()
            );
        }

        $rivals = $rivals
            ->unique('id')
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
                // Lets the photographer bring this client back via
                // "Accommodate Other Reservation" if $confirmedBooking's
                // reservation later falls through — see
                // Booking::accommodationCandidates() and AccommodateBookingAction.
                'superseded_by_booking_id' => $confirmedBooking->id,
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