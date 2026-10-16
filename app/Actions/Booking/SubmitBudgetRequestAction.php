<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;
use App\Models\User;
use App\Notifications\Booking\BudgetNegotiationNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Client states a budget instead of walking away. Changes NO price and does not make the booking payable. */
class SubmitBudgetRequestAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, User $client, float $amount, ?string $note): Booking
    {
        try {
            $fresh = DB::transaction(function () use ($booking, $client, $amount, $note) {
                User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
                $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== BookingStatus::Pending || $locked->isHoldExpired()) {
                    throw ValidationException::withMessages([
                        'status' => ['A budget can only be sent while the booking request is still pending and waiting for the photographer.'],
                    ]);
                }

                $subtotal = (float) $locked->subtotal;

                if ($subtotal <= 0 || $amount >= $subtotal) {
                    throw ValidationException::withMessages([
                        'budget_amount' => ['Your budget is not lower than the package price. Please book at the listed price instead.'],
                    ]);
                }

                if (BookingBudgetRequest::openFor($locked, lock: true) !== null) {
                    throw ValidationException::withMessages([
                        'budget_amount' => ['You already have a budget request waiting for an answer on this booking.'],
                    ]);
                }

                if (BookingBudgetRequest::where('booking_id', $locked->id)->count() >= BookingBudgetRequest::MAX_REQUESTS_PER_BOOKING) {
                    throw ValidationException::withMessages([
                        'budget_amount' => ['You have reached the maximum of '.BookingBudgetRequest::MAX_REQUESTS_PER_BOOKING.' budget requests for this booking.'],
                    ]);
                }

                BookingBudgetRequest::create([
                    'booking_id' => $locked->id,
                    'client_id' => $locked->client_id,
                    'photographer_id' => $locked->photographer_id,
                    'budget_amount' => round($amount, 2),
                    'client_note' => $note,
                    'status' => BookingBudgetRequest::REQUESTED,
                    'expires_at' => $locked->hold_expires_at, // follows the photographer's response window
                    'open_booking_id' => $locked->id,
                ]);

                return $locked->fresh();
            });
        } catch (QueryException $e) {
            // The UNIQUE open_booking_id index caught a duplicate that slipped past the lock.
            throw ValidationException::withMessages([
                'budget_amount' => ['You already have a budget request waiting for an answer on this booking.'],
            ]);
        }

        $fresh->photographer->notify(new BudgetNegotiationNotification(
            $fresh,
            'booking.budget_requested',
            "{$fresh->client->name} sent a budget of ₱".number_format($amount, 2)." for booking #{$fresh->id}.",
        ));

        $this->activityLogger->execute(
            causer: $client,
            subject: $fresh,
            action: 'booking.budget_requested',
            description: "Client sent a budget of ₱".number_format($amount, 2)." for booking #{$fresh->id}.",
        );

        return $fresh;
    }
}