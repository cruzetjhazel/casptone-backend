<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PhotographerPaymentReferenceStatus;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\PhotographerPaymentReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccommodateBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();
        PhotographerPaymentConfig::factory()->for($user)->create();

        return $user;
    }

    protected function payInFull(Booking $booking, string $reference = 'GC-REF-ACC'): void
    {
        PhotographerPaymentReference::create([
            'photographer_id' => $booking->photographer_id,
            'reference_number' => $reference,
            'amount_received' => $booking->total_price,
            'payment_date' => now()->format('Y-m-d'),
            'status' => PhotographerPaymentReferenceStatus::Available,
        ]);

        Sanctum::actingAs($booking->client);
        $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => $reference,
            'payer_name' => 'Test Client',
            'amount' => $booking->total_price,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertCreated();
    }

    protected function setUpCancelledWinnerWithRival(): array
    {
        $photographer = $this->approvedPhotographer();
        $date = now()->addDays(10)->format('Y-m-d');

        $winner = Booking::factory()->accepted()->create([
            'photographer_id' => $photographer->id,
            'event_date' => $date,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'total_price' => 5000,
        ]);
        $rival = Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'event_date' => $date,
            'start_time' => '09:30',
            'end_time' => '11:30',
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
        ]);

        $this->payInFull($winner);
        $this->assertEquals('cancelled', $rival->fresh()->status->value);
        $this->assertEquals($winner->id, $rival->fresh()->superseded_by_booking_id);

        Sanctum::actingAs($winner->client);
        $this->postJson("/api/client/bookings/{$winner->id}/request-cancellation", [
            'reason' => 'Change of plans',
        ])->assertOk();

        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/bookings/{$winner->id}/cancellation/approve")->assertOk();

        return [$photographer, $winner->fresh(), $rival->fresh()];
    }

    public function test_paying_client_auto_declines_rival_and_records_who_it_lost_to(): void
    {
        [, $winner, $rival] = $this->setUpCancelledWinnerWithRival();

        $this->assertEquals('cancelled', $rival->status->value);
        $this->assertEquals($winner->id, $rival->superseded_by_booking_id);
        $this->assertStringContainsString('Automatically declined', $rival->cancellation_reason);
    }

    public function test_rival_appears_as_an_accommodation_candidate_once_winner_cancels(): void
    {
        [$photographer, $winner, $rival] = $this->setUpCancelledWinnerWithRival();

        $this->assertEquals('cancelled', $winner->status->value);

        Sanctum::actingAs($photographer);
        $response = $this->getJson("/api/photographer/bookings/{$winner->id}/accommodation-candidates");

        $response->assertOk();
        $this->assertEquals([$rival->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_photographer_can_accommodate_the_rival(): void
    {
        [$photographer, $winner, $rival] = $this->setUpCancelledWinnerWithRival();

        Sanctum::actingAs($photographer);
        $response = $this->postJson("/api/photographer/bookings/{$winner->id}/accommodate", [
            'candidate_booking_id' => $rival->id,
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'confirmed');

        $fresh = $rival->fresh();
        $this->assertEquals('confirmed', $fresh->status->value);
        $this->assertEquals('pending', $fresh->payment_status->value);
        $this->assertNull($fresh->superseded_by_booking_id);
        $this->assertNull($fresh->cancellation_reason);

        $this->assertDatabaseHas('bookings', ['id' => $fresh->id, 'status' => 'confirmed', 'payment_status' => 'pending']);
    }

    public function test_accommodated_client_can_then_pay_and_the_slot_blocks_again(): void
    {
        [$photographer, $winner, $rival] = $this->setUpCancelledWinnerWithRival();

        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/bookings/{$winner->id}/accommodate", [
            'candidate_booking_id' => $rival->id,
        ])->assertOk();

        $this->payInFull($rival->fresh(), 'GC-REF-ACC-2');

        $this->assertEquals('confirmed', $rival->fresh()->status->value);
        $this->assertEquals('fully_paid', $rival->fresh()->payment_status->value);
    }

    public function test_photographer_cannot_accommodate_a_booking_that_is_not_a_candidate(): void
    {
        [$photographer, $winner] = $this->setUpCancelledWinnerWithRival();
        $unrelated = Booking::factory()->create(['photographer_id' => $photographer->id]);

        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/bookings/{$winner->id}/accommodate", [
            'candidate_booking_id' => $unrelated->id,
        ])->assertStatus(422);
    }

    public function test_another_photographer_cannot_view_or_use_accommodation_candidates(): void
    {
        [, $winner, $rival] = $this->setUpCancelledWinnerWithRival();
        $otherPhotographer = $this->approvedPhotographer();

        Sanctum::actingAs($otherPhotographer);
        $this->getJson("/api/photographer/bookings/{$winner->id}/accommodation-candidates")->assertStatus(403);
        $this->postJson("/api/photographer/bookings/{$winner->id}/accommodate", [
            'candidate_booking_id' => $rival->id,
        ])->assertStatus(403);
    }

    public function test_ordinary_rejection_does_not_produce_accommodation_candidates(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'status' => BookingStatus::Pending,
        ]);

        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/bookings/{$booking->id}/reject", ['reason' => 'Not available'])->assertOk();

        $cancelled = Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'event_date' => $booking->fresh()->event_date,
            'start_time' => $booking->fresh()->start_time,
            'end_time' => $booking->fresh()->end_time,
            'status' => BookingStatus::Cancelled,
        ]);

        Sanctum::actingAs($photographer);
        $response = $this->getJson("/api/photographer/bookings/{$cancelled->id}/accommodation-candidates");

        $response->assertOk();
        $this->assertEquals([], $response->json('data'));
    }
}