<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The expiry check on reads is throttled; make sure no earlier test left the lock behind.
        Cache::forget('bookings:expire-stale:throttle');
    }

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    protected function overduePendingRequest(User $photographer, array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'photographer_id' => $photographer->id,
            'status' => BookingStatus::Pending,
            'hold_expires_at' => now()->subHour(),
        ], $overrides));
    }

    public function test_photographer_booking_list_expires_an_overdue_pending_request(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->overduePendingRequest($photographer);
        Sanctum::actingAs($photographer);

        $this->getJson('/api/photographer/bookings')
            ->assertOk()
            ->assertJsonFragment(['id' => $booking->id, 'status' => 'expired']);

        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
    }

    public function test_photographer_booking_detail_expires_an_overdue_pending_request(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->overduePendingRequest($photographer);
        Sanctum::actingAs($photographer);

        $this->getJson("/api/photographer/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_client_sees_the_same_expired_status(): void
    {
        $photographer = $this->approvedPhotographer();
        $client = User::factory()->create();
        $booking = $this->overduePendingRequest($photographer, ['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->getJson("/api/client/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_a_pending_request_inside_its_window_is_not_expired(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->overduePendingRequest($photographer, ['hold_expires_at' => now()->addHours(5)]);
        Sanctum::actingAs($photographer);

        $this->getJson('/api/photographer/bookings')->assertOk();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_an_accepted_booking_with_an_overdue_payment_deadline_expires(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->overduePendingRequest($photographer, [
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Pending,
        ]);
        Sanctum::actingAs($photographer);

        $this->getJson('/api/photographer/bookings')->assertOk();

        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
    }

    public function test_cannot_accept_a_request_whose_response_window_has_passed(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->overduePendingRequest($photographer);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertStatus(422);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_cannot_decline_a_request_whose_response_window_has_passed(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->overduePendingRequest($photographer);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/reject", ['reason' => 'Too late'])
            ->assertStatus(422);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }
}