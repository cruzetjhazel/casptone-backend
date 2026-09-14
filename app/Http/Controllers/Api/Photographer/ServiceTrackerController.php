<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Actions\Booking\MarkServiceCompletedAction;
use App\Actions\Booking\UpdateServiceTrackerStatusAction;
use App\Enums\ServiceTrackerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateServiceTrackerStatusRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Traits\ApiResponses;

class ServiceTrackerController extends Controller
{
    use ApiResponses;

    public function update(UpdateServiceTrackerStatusRequest $request, Booking $booking, UpdateServiceTrackerStatusAction $action)
    {
        $this->authorize('manageServiceTracker', $booking);

        $status = ServiceTrackerStatus::from($request->validated('service_status'));
        $booking = $action->execute($booking, $status);

        return $this->success(new BookingResource($booking), 'Service tracker updated.');
    }

    /**
     * Explicit photographer action: BookingStatus Confirmed -> Completed,
     * once the service tracker has reached Delivered. Reuses the same
     * `manageServiceTracker` authorization as the tracker update above —
     * same actor, same booking-ownership rule.
     */
    public function complete(Booking $booking, MarkServiceCompletedAction $action)
    {
        $this->authorize('manageServiceTracker', $booking);

        $booking = $action->execute($booking);

        return $this->success(new BookingResource($booking), 'Booking marked as completed.');
    }
}