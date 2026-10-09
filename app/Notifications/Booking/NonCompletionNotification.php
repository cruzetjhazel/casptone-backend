<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NonCompletionNotification extends Notification
{
    use Queueable;

    /** @param 'reported'|'admin_review'|'disputed'|'admin_disputed'|'upheld'|'overturned' $event */
    public function __construct(protected Booking $booking, protected string $event)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

        public function toDatabase(object $notifiable): array
    {
        $b = $this->booking;
        $isClient = (int) $notifiable->id === (int) $b->client_id;
        $reportedByClient = (int) $b->non_completion_reported_by === (int) $b->client_id;
        $deadline = $b->non_completion_dispute_deadline_at?->format('M j, Y g:i A');
        $until = $deadline ? " (until {$deadline})" : '';

        [$title, $description] = match ($this->event) {
            // Sent to the person who was reported.
            'reported' => [
                'No-Show Reported',
                ($reportedByClient
                    ? "The client reported that you did not attend booking #{$b->id}."
                    : "The photographer reported that you did not attend booking #{$b->id}.")
                    ." The booking is not cancelled. An admin will review the report, and you can still send your side{$until}.",
            ],
            // Sent to admins.
            'admin_review' => [
                'No-Show Report Needs Review',
                'On booking #'.$b->id.', the '.($reportedByClient ? 'client' : 'photographer')
                    ." reported a no-show. You can review it and decide now. The other side can still send their side{$until}.",
            ],
            // Sent to the person who reported.
            'disputed' => [
                'No-Show Report Disputed',
                "Your no-show report on booking #{$b->id} was disputed. An admin will review both sides. The booking stays as it is until then.",
            ],
            'admin_disputed' => [
                'No-Show Dispute Submitted',
                "A dispute was submitted on the no-show report for booking #{$b->id}. Please review both sides.",
            ],
            'upheld' => [
                'No-Show Confirmed',
                $isClient && $b->non_completion_reason?->value === 'photographer_no_show'
                    ? "An admin confirmed the no photographer show on booking #{$b->id}. The booking is now marked as No Show, and you can now leave a review."
                    : "An admin confirmed the no-show report on booking #{$b->id}. The booking is now marked as No Show.",
            ],
            'overturned' => [
                'No-Show Report Dismissed',
                "An admin dismissed the no-show report on booking #{$b->id}. The booking continues as normal.",
            ],
            default => ['No-Show Update', "There is an update on booking #{$b->id}."],
        };

        return [
            'type' => 'booking',
            'title' => $title,
            'description' => $description,
            'booking_id' => (string) $b->id,
            'action' => null,
        ];
    }
}