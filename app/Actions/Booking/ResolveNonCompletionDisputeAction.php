<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\ReportStatus;
use App\Models\Booking;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Booking\NonCompletionNotification;
use Illuminate\Validation\ValidationException;

class ResolveNonCompletionDisputeAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /** @param 'upheld'|'overturned' $decision */
    public function execute(Booking $booking, User $admin, string $decision, ?string $notes): Booking
    {
        if ($booking->non_completion_review_status !== 'pending_admin') {
            throw ValidationException::withMessages(['status' => ['This booking has no dispute awaiting review.']]);
        }

        $booking->non_completion_review_status = $decision;
        $booking->non_completion_admin_notes = $notes;
        $booking->non_completion_resolved_at = now();

        if ($decision === 'overturned') {
            $booking->status = BookingStatus::Confirmed;
            $booking->non_completion_reason = null;
        }
        $booking->save();

        Report::where('target_type', 'booking')
            ->where('reference_id', (string) $booking->id)
            ->where('reason', 'No-show dispute')
            ->whereIn('status', [ReportStatus::Submitted->value, ReportStatus::UnderReview->value])
            ->update(['status' => ReportStatus::Resolved->value, 'resolved_at' => now()]);

        $fresh = $booking->fresh();
        foreach ([$fresh->client, $fresh->photographer] as $party) {
            $party?->notify(new NonCompletionNotification($fresh, $decision));
        }

        $this->activityLogger->execute(
            causer: $admin,
            subject: $fresh,
            action: 'booking.non_completion_'.$decision,
            description: "Admin {$decision} the no-show report on booking #{$fresh->id}",
            metadata: ['notes' => $notes],
        );

        return $fresh;
    }
}