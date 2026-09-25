<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Actions\Booking\AcceptBookingAction;
use App\Actions\Booking\AccommodateBookingAction;
use App\Actions\Booking\DecideBookingCancellationAction;
use App\Actions\Booking\DecideBookingRescheduleAction;
use App\Actions\Booking\RejectBookingAction;
use App\Enums\CancellationDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccommodateBookingRequest;
use App\Http\Requests\RejectBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        return $this->success(
            BookingResource::collection($request->user()->bookingsAsPhotographer()->latest()->get())
        );
    }

    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);

        return $this->success(new BookingResource($booking));
    }

    public function accept(Booking $booking, AcceptBookingAction $action)
    {
        $this->authorize('respond', $booking);

        return $this->success(new BookingResource($action->execute($booking)), 'Booking accepted.');
    }

    public function reject(RejectBookingRequest $request, Booking $booking, RejectBookingAction $action)
    {
        $this->authorize('respond', $booking);

        $booking = $action->execute($booking, $request->validated('reason'));

        return $this->success(new BookingResource($booking), 'Booking rejected.');
    }

    public function approveCancellation(Booking $booking, DecideBookingCancellationAction $action)
    {
        $this->authorize('decideCancellation', $booking);

        $booking = $action->execute($booking, CancellationDecision::Approved);

        return $this->success(new BookingResource($booking), 'Cancellation approved.');
    }

    public function rejectCancellation(Booking $booking, DecideBookingCancellationAction $action)
    {
        $this->authorize('decideCancellation', $booking);

        $booking = $action->execute($booking, CancellationDecision::Rejected);

        return $this->success(new BookingResource($booking), 'Cancellation request rejected.');
    }

    public function accommodationCandidates(Booking $booking)
    {
        $this->authorize('accommodate', $booking);

        $candidates = $booking->accommodationCandidates()->latest()->get();

        return $this->success(BookingResource::collection($candidates));
    }

    public function accommodate(AccommodateBookingRequest $request, Booking $booking, AccommodateBookingAction $action)
    {
        $this->authorize('accommodate', $booking);

        $candidate = Booking::findOrFail($request->validated('candidate_booking_id'));
        $this->authorize('accommodate', $candidate);

        $accommodated = $action->execute($booking, $candidate);

        return $this->success(new BookingResource($accommodated), 'Booking accommodated. The client can now proceed with payment.');
    }

    public function approveReschedule(Booking $booking, DecideBookingRescheduleAction $action)
    {
        $this->authorize('decideReschedule', $booking);

        return $this->success(new BookingResource($action->execute($booking, CancellationDecision::Approved)), 'Reschedule approved.');
    }

    public function rejectReschedule(Booking $booking, DecideBookingRescheduleAction $action)
    {
        $this->authorize('decideReschedule', $booking);

        return $this->success(new BookingResource($action->execute($booking, CancellationDecision::Rejected)), 'Reschedule rejected.');
    }
}