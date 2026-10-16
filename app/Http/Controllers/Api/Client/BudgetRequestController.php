<?php

namespace App\Http\Controllers\Api\Client;

use App\Actions\Booking\AcceptBudgetTermsAction;
use App\Actions\Booking\RejectBudgetTermsAction;
use App\Actions\Booking\SubmitBudgetRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class BudgetRequestController extends Controller
{
    use ApiResponses;

    private function assertOwner(Request $request, Booking $booking): void
    {
        abort_unless($request->user()->isClient() && $booking->client_id === $request->user()->id, 403);
    }

    public function store(Request $request, Booking $booking, SubmitBudgetRequestAction $action)
    {
        $this->assertOwner($request, $booking);

        $data = $request->validate([
            'budget_amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = $action->execute($booking, $request->user(), (float) $data['budget_amount'], $data['note'] ?? null);

        return $this->success(new BookingResource($booking), 'Your budget was sent to the photographer.', 201);
    }

    public function accept(Request $request, Booking $booking, AcceptBudgetTermsAction $action)
    {
        $this->assertOwner($request, $booking);

        $booking = $action->execute($booking, $request->user());

        return $this->success(
            new BookingResource($booking),
            $booking->status === \App\Enums\BookingStatus::Waitlisted
                ? 'Terms accepted, but another paid booking already holds this schedule, so you are on the waitlist.'
                : 'Terms accepted. You can now pay to confirm your booking.'
        );
    }

    public function reject(Request $request, Booking $booking, RejectBudgetTermsAction $action)
    {
        $this->assertOwner($request, $booking);

        $booking = $action->execute($booking, $request->user());

        return $this->success(new BookingResource($booking), 'You turned down the photographer\'s terms.');
    }
}