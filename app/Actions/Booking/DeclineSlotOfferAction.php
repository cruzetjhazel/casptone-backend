<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\Booking\SlotOfferEndedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The client turns down a released schedule that the photographer offered them.
 * The booking returns to the waitlist (never cancelled), so the photographer can
 * offer the slot to another waitlisted client. Only possible while nothing has been
 * paid or submitted yet; once a payment reference exists, the photographer reviews it.
 */
class DeclineSlotOfferAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking): Booking
    {
           $releasedId = null;
   $fresh = DB::transaction(function () use ($booking, &$releasedId) {
            // Same lock order as every other slot-changing action: photographer row, then booking.
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Confirmed || $locked->slot_offered_at === null) {
                throw ValidationException::withMessages([
                    'booking' => ['There is no schedule offer to decline for this booking.'],
                ]);
            }

            if ($locked->payment_status !== BookingPaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'booking' => ['A payment was already submitted for this offer, so it can no longer be declined. The photographer will review your payment.'],
                ]);
            }

            $locked->update([
                'status' => BookingStatus::Waitlisted,
                'hold_expires_at' => null,
                'slot_offered_at' => null,
                'slot_offer_accepted_at' => null,
                'superseded_by_booking_id' => null,
            ]);

                $offer = \App\Models\BookingSlotOffer::closeOpenFor($locked, \App\Models\BookingSlotOffer::DECLINED);
    $releasedId = $offer?->released_booking_id;

    return $locked->fresh();
        });

        $fresh->photographer->notify(new SlotOfferEndedNotification(
            $fresh,
            'declined',
            "{$fresh->client->name} declined the offered schedule. The slot is passed to the next waitlisted client automatically; this client is not re-offered it unless you offer it manually."
        ));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.offer_declined',
            description: "Client declined the offered schedule for booking #{$fresh->id}; returned to the waitlist.",
        );

    // Pass the slot to the next client. The decliner is never re-offered automatically.
    if ($releasedId !== null && ($released = Booking::find($releasedId))) {
        app(OfferReleasedSlotAction::class)->execute($released);
    }

    return $fresh;
    }
}