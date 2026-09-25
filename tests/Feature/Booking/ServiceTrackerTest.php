<?php

namespace Tests\Feature\Booking;

use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_guest_cannot_update_service_tracker(): void
    {
        $photographer = User::factory()->photographer()->create();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(401);
    }

    public function test_photographer_can_advance_the_service_tracker(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $response = $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ]);

        $response->assertOk()->assertJsonPath('data.service_status', 'event_day');
        $this->assertNotNull($booking->fresh()->service_status_updated_at);
    }

        public function test_service_tracker_walks_through_every_manual_stage_in_order(): void
    {
        $photographer = $this->approvedPhotographer();
        // Confirmed + fully paid: BookingObserver auto-starts the tracker at "upcoming".
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        foreach (['event_day', 'editing', 'delivered'] as $stage) {
            $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
                'service_status' => $stage,
            ])->assertOk()->assertJsonPath('data.service_status', $stage);
        }

        // Delivered does NOT complete the booking on its own.
        $this->assertSame('confirmed', $booking->fresh()->status->value);
    }

    public function test_tracker_stages_cannot_be_skipped(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        // upcoming -> editing skips event_day
        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'editing',
        ])->assertStatus(422);
    }

    public function test_marking_service_completed_after_delivery_completes_the_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::Delivered)
            ->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/complete")->assertOk();

        $this->assertSame('completed', $booking->fresh()->status->value);
    }

    public function test_cannot_mark_completed_before_the_tracker_reaches_delivered(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/complete")->assertStatus(422);

        $this->assertSame('confirmed', $booking->fresh()->status->value);
    }

    public function test_invalid_service_status_is_rejected(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'not_a_real_stage',
        ])->assertStatus(422);
    }

    public function test_service_status_is_required(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [])
            ->assertStatus(422);
    }

    public function test_cannot_manage_tracker_on_a_pending_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]); // default status: pending
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(422);
    }

    public function test_cannot_manage_tracker_on_an_accepted_but_unconfirmed_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(422);
    }

    public function test_other_photographer_cannot_manage_the_tracker(): void
    {
        $owner = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $owner->id]);

        $other = $this->approvedPhotographer();
        Sanctum::actingAs($other);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(403);
    }

    public function test_unapproved_photographer_cannot_manage_the_tracker(): void
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($photographer)->pendingReview()->create();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(403);
    }

    public function test_client_cannot_manage_the_service_tracker(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(403);
    }
}