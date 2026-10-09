<?php

namespace App\Actions\Review;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Review;
use Illuminate\Validation\ValidationException;

class ReportReviewAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Review $review, string $reason): Review
    {
        if ($review->isReported()) {
            throw ValidationException::withMessages([
                'reason' => ['This review has already been reported and is awaiting admin review.'],
            ]);
        }

        // Deliberately does NOT hide, archive, or delete the review — the
        // photographer has no ability to suppress feedback, only to flag it
        // for an administrator to investigate (Module 12).
        $review->report_reason = $reason;
        $review->reported_at = now();
        $review->save();

        $fresh = $review->fresh();

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'review.reported',
            description: "Reported review on booking #{$fresh->booking_id}",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }
}