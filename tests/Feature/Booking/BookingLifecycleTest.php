<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_photographer_can_accept_a_pending_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_photographer_can_reject_a_pending_booking_with_reason(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/reject", ['reason' => 'Unavailable'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $photographer = User::factory()->photographer()->create();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/reject", [])->assertStatus(422);
    }

    public function test_cannot_accept_an_already_accepted_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertStatus(422);
    }

    public function test_photographer_cannot_respond_to_another_photographers_booking(): void
    {
        $owner = User::factory()->photographer()->create();
        $booking = Booking::factory()->create(['photographer_id' => $owner->id]);

        $other = User::factory()->photographer()->create();
        Sanctum::actingAs($other);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertStatus(403);
    }

    public function test_client_cannot_accept_or_reject_bookings(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertStatus(403);
    }

    public function test_client_can_view_their_own_booking(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->getJson("/api/client/bookings/{$booking->id}")->assertOk();
    }

    public function test_client_cannot_view_another_clients_booking(): void
    {
        $booking = Booking::factory()->create();
        $otherClient = User::factory()->create();
        Sanctum::actingAs($otherClient);

        $this->getJson("/api/client/bookings/{$booking->id}")->assertStatus(403);
    }
    public function test_unapproved_photographer_cannot_accept_a_booking(): void
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($photographer)->pendingReview()->create();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertStatus(403);
    }
}