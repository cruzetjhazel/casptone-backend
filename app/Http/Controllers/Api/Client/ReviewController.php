<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Booking;
use App\Models\Review;
use App\Traits\ApiResponses;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $reviews = Review::where('client_id', auth()->id())
            ->with(['booking', 'client', 'photographer'])
            ->latest()
            ->get();

        return $this->success(ReviewResource::collection($reviews));
    }

    public function store(StoreReviewRequest $request)
    {
        $booking = Booking::findOrFail($request->validated('booking_id'));

        if ($booking->client_id !== auth()->id()) {
            abort(403, 'You can only review your own bookings.');
        }

        // §7.24: reviewable only once the booking is Completed.
        if (! $booking->isReviewable()) {
            throw ValidationException::withMessages([
'booking_id' => ['This booking is not eligible for a review.'],
            ]);
        }

        // §7.24: only one review per booking (also enforced by the DB unique constraint).
        if ($booking->review) {
            throw ValidationException::withMessages([
                'booking_id' => ['You have already reviewed this booking.'],
            ]);
        }

        $review = Review::create([
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'photographer_id' => $booking->photographer_id,
            'rating' => $request->validated('rating'),
            'comment' => $request->validated('comment'),
        ]);

        $review->load(['booking', 'client', 'photographer']);

        return $this->success(new ReviewResource($review), 'Review submitted.', 201);
    }
}