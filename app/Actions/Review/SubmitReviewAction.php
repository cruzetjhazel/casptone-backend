<?php

namespace App\Actions\Review;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Validation\ValidationException;

class SubmitReviewAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, array $data): Review
    {
        if (! $booking->isReviewable()) {
            throw ValidationException::withMessages([
                'booking_id' => ['You can only review a completed booking.'],
            ]);
        }

        if ($booking->review()->exists()) {
            throw ValidationException::withMessages([
                'booking_id' => ['You have already submitted a review for this booking.'],
            ]);
        }

        $review = Review::create([
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'photographer_id' => $booking->photographer_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'],
        ]);

        $this->activityLogger->execute(
            causer: $booking->client,
            subject: $review,
            action: 'review.submitted',
            description: "Submitted a review for booking #{$booking->id}",
            metadata: ['rating' => $data['rating']],
        );

        return $review;
    }
}