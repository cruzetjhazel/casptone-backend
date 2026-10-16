<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Actions\Booking\RespondToBudgetRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetRequestController extends Controller
{
    use ApiResponses;

    public function respond(Request $request, Booking $booking, RespondToBudgetRequestAction $action)
    {
        // Same ability as accepting / rejecting a request: own booking + approved photographer.
        $this->authorize('respond', $booking);

        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'counter', 'decline'])],
            'proposed_price' => ['required_if:action,counter', 'nullable', 'numeric', 'gt:0', 'max:99999999.99'],
            'inclusions' => ['required_if:action,counter', 'nullable', 'array', 'min:1', 'max:'.BookingBudgetRequest::MAX_INCLUSIONS],
            'inclusions.*.name' => ['required', 'string', 'max:120'],
            'inclusions.*.details' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required_if:action,decline', 'nullable', 'string', 'max:1000'],
        ]);

        $booking = $action->execute($booking, $request->user(), $data);

        return $this->success(new BookingResource($booking), match ($data['action']) {
            'accept' => 'Budget accepted. The client must confirm the terms before they can pay.',
            'counter' => 'Counter offer sent to the client.',
            default => 'Budget request declined.',
        });
    }
}