<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingSlotOffer;
use App\Models\User;
use App\Notifications\Booking\SlotOfferAcceptedNotification;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The offered client explicitly accepts the released schedule. Only after this can they pay.
 *
 * Under the photographer-row then booking-row lock it re-checks that the offer is still live,
 * that its deadline has not passed (accepting does NOT extend it) and that no other booking has
 * been PAID for the slot in the meantime. If one has, the booking goes back to the waitlist
 * (the offer is withdrawn) and the client gets a 422 explaining why.
 */
class AcceptSlotOfferAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
    ) {
    }

    public function execute(Booking $booking): Booking
    {
        $lostSlot = false;

        $fresh = DB::transaction(function () use ($booking, &$lostSlot) {
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isAwaitingOfferAcceptance()) {
                throw ValidationException::withMessages([
                    'booking' => ['There is no schedule offer waiting for your acceptance on this booking.'],
                ]);
            }

            if ($locked->hold_expires_at !== null && $locked->hold_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'booking' => ['The deadline to accept this offer has passed.'],
                ]);
            }

            $holder = $this->slotConflictService->findPaidConflict($locked);

            if ($holder !== null) {
                $locked->update([
                    'status' => BookingStatus::Waitlisted,
                    'superseded_by_booking_id' => $holder->id,
                    'hold_expires_at' => null,
                    'slot_offered_at' => null,
                    'slot_offer_accepted_at' => null,
                ]);
                BookingSlotOffer::closeOpenFor($locked, BookingSlotOffer::WITHDRAWN);
                $lostSlot = true;

                return $locked->fresh();
            }

            $locked->update(['slot_offer_accepted_at' => now()]);
            BookingSlotOffer::markAccepted($locked);

            return $locked->fresh();
        });

        if ($lostSlot) {
            $this->activityLogger->execute(
                causer: $fresh->client,
                subject: $fresh,
                action: 'booking.offer_accept_failed',
                description: "Booking #{$fresh->id} could not accept the offered schedule: another booking was paid for it first. Returned to the waitlist.",
            );

            // The transaction already committed the move back to the waitlist.
            throw ValidationException::withMessages([
                'booking' => ['Sorry, another booking was paid for this schedule before you accepted. Your booking is back on the waitlist.'],
            ]);
        }

        $fresh->photographer->notify(new SlotOfferAcceptedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.offer_accepted',
            description: "Client accepted the offered schedule for booking #{$fresh->id}.",
        );

        return $fresh;
    }
}