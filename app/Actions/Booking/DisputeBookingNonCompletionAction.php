<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\ReportRequestedAction;
use App\Enums\ReportSeverity;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Models\Booking;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Booking\NonCompletionNotification;
use Illuminate\Validation\ValidationException;

class DisputeBookingNonCompletionAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, User $disputer, string $reason): Booking
    {
        if ($booking->status !== BookingStatus::Cancelled || ! $booking->non_completion_reason || ! $booking->non_completion_dispute_deadline_at) {
            throw ValidationException::withMessages(['status' => ['This booking has no no-show report to dispute.']]);
        }

        $isParty = in_array($disputer->id, [$booking->client_id, $booking->photographer_id], true);
        if (! $isParty || $disputer->id === $booking->non_completion_reported_by) {
            throw ValidationException::withMessages(['status' => ['Only the reported party can dispute this.']]);
        }

        if ($booking->non_completion_disputed_at) {
            throw ValidationException::withMessages(['status' => ['This report has already been disputed.']]);
        }

        if (now()->greaterThan($booking->non_completion_dispute_deadline_at)) {
            throw ValidationException::withMessages(['status' => ['The dispute period for this report has ended.']]);
        }

        $booking->non_completion_disputed_at = now();
        $booking->non_completion_dispute_reason = $reason;
        $booking->non_completion_review_status = 'pending_admin';
        $booking->save();

        // Surfaces on the existing admin Reports page.
        Report::create([
            'reporter_id' => $disputer->id,
            'target_type' => ReportTargetType::Booking,
            'reference_id' => (string) $booking->id,
            'reason' => 'No-show dispute',
            'severity' => ReportSeverity::Medium,
            'details' => "Disputed a {$booking->non_completion_reason} report on booking #{$booking->id}: {$reason}",
            'requested_action' => ReportRequestedAction::Investigate,
            'status' => ReportStatus::Submitted,
        ]);

        $fresh = $booking->fresh();
        $reporter = User::find($fresh->non_completion_reported_by);
        $reporter?->notify(new NonCompletionNotification($fresh, 'disputed'));

        $this->activityLogger->execute(
            causer: $disputer,
            subject: $fresh,
            action: 'booking.non_completion_disputed',
            description: "Disputed the no-show report on booking #{$fresh->id}",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }
}