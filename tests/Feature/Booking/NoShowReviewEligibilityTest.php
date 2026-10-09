<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingNonCompletionReason;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NoShowReviewEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    /** A cancelled booking carrying a no-show reason, as the report action leaves it. */
    protected function noShowBooking(User $client, User $photographer, BookingNonCompletionReason $reason): Booking
    {
        return Booking::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'status' => BookingStatus::NoShow,
            'hold_expires_at' => null,
            'event_date' => now()->subDays(2)->format('Y-m-d'),
            'non_completion_reason' => $reason,
            'non_completion_reported_at' => now()->subDay(),
            'non_completion_dispute_deadline_at' => now()->addDay(),
        ]);
    }

    public function test_photographer_can_report_a_client_no_show(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create([
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:30',
        ]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'client_no_show',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'confirmed');

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Confirmed, $fresh->status);
        $this->assertSame('pending_admin', $fresh->non_completion_review_status);
        $this->assertSame(BookingNonCompletionReason::ClientNoShow, $fresh->non_completion_reason);
        $this->assertFalse($fresh->isReviewable());
    }

    public function test_client_cannot_review_a_client_no_show_booking(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->noShowBooking($client, $photographer, BookingNonCompletionReason::ClientNoShow);
        Sanctum::actingAs($client);

        $this->postJson('/api/client/reviews', [
            'booking_id' => $booking->id,
            'rating' => 1,
            'comment' => 'I never attended, but here is a rating anyway.',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('reviews', ['booking_id' => $booking->id]);
    }

    public function test_client_cannot_review_after_an_admin_overturns_the_report(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->noShowBooking($client, $photographer, BookingNonCompletionReason::ClientNoShow);
        // What ResolveNonCompletionDisputeAction does on 'overturned'.
        $booking->update(['status' => BookingStatus::Confirmed, 'non_completion_reason' => null]);
        Sanctum::actingAs($client);

        $this->postJson('/api/client/reviews', [
            'booking_id' => $booking->id,
            'rating' => 5,
            'comment' => 'Not completed yet.',
        ])->assertStatus(422);
    }

    public function test_client_can_review_a_photographer_no_show_booking(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->noShowBooking($client, $photographer, BookingNonCompletionReason::PhotographerNoShow);
        Sanctum::actingAs($client);

        $this->postJson('/api/client/reviews', [
            'booking_id' => $booking->id,
            'rating' => 1,
            'comment' => 'The photographer never showed up.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('reviews', ['booking_id' => $booking->id, 'client_id' => $client->id]);
    }

    public function test_client_can_still_review_a_completed_booking(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->completed()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
        ]);
        Sanctum::actingAs($client);

        $this->postJson('/api/client/reviews', [
            'booking_id' => $booking->id,
            'rating' => 5,
            'comment' => 'Great session.',
        ])->assertStatus(201);
    }

    public function test_is_reviewable_matrix(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();

        $clientNoShow = $this->noShowBooking($client, $photographer, BookingNonCompletionReason::ClientNoShow);
        $photographerNoShow = $this->noShowBooking($client, $photographer, BookingNonCompletionReason::PhotographerNoShow);
        $plainCancelled = Booking::factory()->rejected()->create(['client_id' => $client->id]);

        $this->assertFalse($clientNoShow->isReviewable());
        $this->assertTrue($photographerNoShow->isReviewable());
        $this->assertFalse($plainCancelled->isReviewable());
    }
}