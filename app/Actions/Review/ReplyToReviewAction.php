<?php

namespace App\Actions\Review;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Review;
use Illuminate\Validation\ValidationException;

class ReplyToReviewAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Review $review, string $reply): Review
    {
        if ($review->hasReply()) {
            throw ValidationException::withMessages([
                'reply' => ['You have already replied to this review. Replies cannot be edited.'],
            ]);
        }

        $review->reply = $reply;
        $review->replied_at = now();
        $review->save();

        $fresh = $review->fresh();

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'review.replied',
            description: "Replied to review on booking #{$fresh->booking_id}",
        );

        return $fresh;
    }
}