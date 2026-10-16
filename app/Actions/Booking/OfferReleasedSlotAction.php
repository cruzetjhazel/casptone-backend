<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingSlotOffer;
use App\Models\User;
use App\Notifications\Booking\BookingAccommodatedNotification;
use App\Services\Photographer\Booking\BookingDeadlineService;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offers a slot that was just released (a booking that HELD a paid slot was cancelled, or an
 * earlier offer was declined / lapsed / withdrawn) to the OLDEST eligible overlapping waitlisted
 * booking. At most one offer is outstanding per slot.
 *
 * Eligible = still Waitlisted, event not past, overlaps the released booking
 * (Booking::accommodationCandidates(), oldest request first) AND
 *  - has not declined or let an offer for THIS released slot lapse,
 *  - is not blocked by an outstanding offer or a paid booking on its own overlapping window,
 *  - still has enough time left for a payment window.
 *
 * Lock order: photographer row, then the candidate booking row (same as every slot action).
 * Call this AFTER the caller's own transaction has committed.
 *
 * Returns the offered booking, or null (offer already outstanding / nobody eligible).
 * The photographer's manual "accommodate" override is unaffected.
 */
class OfferReleasedSlotAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
        protected BookingDeadlineService $deadlines,
    ) {
    }

    /**
     * A booking that itself held an OPEN (unpaid) offer was cancelled: its offer is withdrawn and
     * the slot passes to the next eligible client. A no-op when the booking had no open offer.
     */
    public function passOnOffer(Booking $cancelled): void
    {
        $offer = BookingSlotOffer::closeOpenFor($cancelled, BookingSlotOffer::WITHDRAWN);

        if ($offer?->released_booking_id === null) {
            return;
        }

        $source = Booking::find($offer->released_booking_id);

        if ($source !== null) {
            $this->execute($source);
        }
    }

    public function execute(Booking $released): ?Booking
    {
        $offerAlreadyOutstanding = false;

        $offered = DB::transaction(function () use ($released, &$offerAlreadyOutstanding) {
            User::where('id', $released->photographer_id)->lockForUpdate()->firstOrFail();

            // Re-read under the lock: a stale copy must never decide anything.
            $source = Booking::find($released->id);

            if ($source === null || $source->status !== BookingStatus::Cancelled) {
                return null;
            }

            foreach ($source->accommodationCandidates()->get() as $candidate) {
                $locked = Booking::whereKey($candidate->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== BookingStatus::Waitlisted) {
                    continue;
                }

                $declinedOrLapsedBefore = BookingSlotOffer::where('booking_id', $locked->id)
                    ->where('released_booking_id', $source->id)
                    ->whereIn('status', [BookingSlotOffer::DECLINED, BookingSlotOffer::LAPSED])
                    ->exists();

                if ($declinedOrLapsedBefore) {
                    continue;
                }

                if ($this->slotConflictService->hasOutstandingOffer($locked)) {
                    $offerAlreadyOutstanding = true;

                    continue;
                }

                if ($this->slotConflictService->findPaidConflict($locked) !== null) {
                    continue;
                }

                $paymentAlreadySubmitted = $locked->payment_status === BookingPaymentStatus::PendingVerification;

                try {
                    $deadline = $paymentAlreadySubmitted ? null : $this->deadlines->paymentDeadline($locked);
                } catch (ValidationException) {
                    continue; // event too close to collect a payment: try the next client
                }

                $locked->update([
                    'status' => BookingStatus::Confirmed,
                    'payment_status' => $paymentAlreadySubmitted
                        ? BookingPaymentStatus::PendingVerification
                        : BookingPaymentStatus::Pending,
                    'hold_expires_at' => $deadline,
                    'superseded_by_booking_id' => null,
                    'slot_offered_at' => now(),
                    // A client who already submitted a reference has effectively accepted.
                    'slot_offer_accepted_at' => $paymentAlreadySubmitted ? now() : null,
                ]);

                BookingSlotOffer::create([
                    'booking_id' => $locked->id,
                    'released_booking_id' => $source->id,
                    'status' => $paymentAlreadySubmitted ? BookingSlotOffer::ACCEPTED : BookingSlotOffer::OFFERED,
                    'offered_at' => now(),
                    'expires_at' => $deadline,
                    'responded_at' => $paymentAlreadySubmitted ? now() : null,
                ]);

                return $locked->fresh();
            }

            return null;
        });

        if ($offered === null) {
            // Nobody was offered the slot. If that is NOT because an offer is already outstanding,
            // tell the photographer who is still waiting so they can offer it manually.
            if (! $offerAlreadyOutstanding) {
                $this->slotConflictService->notifySlotReleased($released->fresh() ?? $released);
            }

            return null;
        }

        $offered->client->notify(new BookingAccommodatedNotification($offered));

        $this->activityLogger->execute(
            causer: null,
            subject: $offered,
            action: 'booking.slot_auto_offered',
            description: "Booking #{$offered->id} was automatically offered the schedule released by booking #{$released->id} ({$offered->client->name}).",
        );

        return $offered;
    }
}