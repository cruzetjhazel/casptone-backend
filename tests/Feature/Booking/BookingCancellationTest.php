<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_client_can_request_cancellation(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", [
            'reason' => 'Change of plans',
        ]);

        $response->assertOk();
        $this->assertNotNull($booking->fresh()->cancellation_requested_at);
    }

    public function test_cancellation_requires_a_reason(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", [])->assertStatus(422);
    }

    public function test_client_cannot_request_cancellation_on_another_clients_booking(): void
    {
        $booking = Booking::factory()->accepted()->create();
        $otherClient = User::factory()->create();
        Sanctum::actingAs($otherClient);

        $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'x'])
            ->assertStatus(403);
    }

    public function test_photographer_can_approve_a_cancellation_request(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        $booking->update(['cancellation_reason' => 'x', 'cancellation_requested_at' => now()]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/approve");

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_photographer_can_reject_a_cancellation_request(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        $booking->update(['cancellation_reason' => 'x', 'cancellation_requested_at' => now()]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/reject");

        $response->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('rejected', $booking->fresh()->cancellation_decision->value);
    }

    public function test_cannot_decide_a_cancellation_that_was_not_requested(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/approve")->assertStatus(422);
    }

    public function test_other_photographer_cannot_decide_a_cancellation(): void
    {
        $owner = User::factory()->photographer()->create();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $owner->id]);
        $booking->update(['cancellation_reason' => 'x', 'cancellation_requested_at' => now()]);

        $other = User::factory()->photographer()->create();
        Sanctum::actingAs($other);

        $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/approve")->assertStatus(403);
    }
    public function test_unapproved_photographer_cannot_decide_a_cancellation(): void
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($photographer)->pendingReview()->create();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        $booking->update(['cancellation_reason' => 'x', 'cancellation_requested_at' => now()]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/approve")->assertStatus(403);
    }
}