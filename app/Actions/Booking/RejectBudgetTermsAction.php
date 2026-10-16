<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;
use App\Models\User;
use App\Notifications\Booking\BudgetNegotiationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The client turns the photographer's terms down. The booking stays Pending at the listed price. */
class RejectBudgetTermsAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, User $client): Booking
    {
        $fresh = DB::transaction(function () use ($booking) {
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $request = BookingBudgetRequest::openFor($locked, lock: true);

            if ($request === null || $request->status !== BookingBudgetRequest::PROPOSED) {
                throw ValidationException::withMessages([
                    'status' => ['There are no terms from the photographer waiting for your answer.'],
                ]);
            }

            $request->update([
                'status' => BookingBudgetRequest::REJECTED,
                'open_booking_id' => null,
                'responded_at' => now(),
            ]);

            return $locked->fresh();
        });

        $fresh->photographer->notify(new BudgetNegotiationNotification(
            $fresh,
            'booking.budget_rejected',
            "{$fresh->client->name} turned down your terms for booking #{$fresh->id}. The request is still pending at the listed price.",
        ));

        $this->activityLogger->execute(
            causer: $client,
            subject: $fresh,
            action: 'booking.budget_rejected',
            description: "Client rejected the negotiated terms for booking #{$fresh->id}.",
        );

        return $fresh;
    }
}