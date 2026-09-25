<?php

namespace Tests\Feature\Payment;

use App\Enums\PhotographerPaymentReferenceStatus;
use App\Models\Booking;
use App\Models\PhotographerPaymentConfig;
use App\Models\PhotographerPaymentReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmitPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function acceptedBooking(float $totalPrice = 10000): Booking
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerPaymentConfig::factory()->for($photographer)->create();
        $client = User::factory()->create();

        return Booking::factory()->accepted()->create([
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'total_price' => $totalPrice,
        ]);
    }

    protected function registerReference(Booking $booking, string $reference, float $amount): PhotographerPaymentReference
    {
        return PhotographerPaymentReference::create([
            'photographer_id' => $booking->photographer_id,
            'reference_number' => $reference,
            'amount_received' => $amount,
            'payment_date' => now()->format('Y-m-d'),
            'status' => PhotographerPaymentReferenceStatus::Available,
        ]);
    }

    public function test_client_can_submit_full_payment_and_booking_is_confirmed(): void
    {
        $booking = $this->acceptedBooking(10000);
        $this->registerReference($booking, 'GC-REF-0001', 10000);
        Sanctum::actingAs($booking->client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-0001',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.booking.status', 'confirmed')
            ->assertJsonPath('data.matching_status', 'matched');
        $this->assertEquals('confirmed', $booking->fresh()->status->value);
        $this->assertEquals('fully_paid', $booking->fresh()->payment_status->value);
    }

    public function test_client_can_submit_half_payment_leaving_a_remaining_balance(): void
    {
        $booking = $this->acceptedBooking(10000);
        $this->registerReference($booking, 'GC-REF-0002', 5000);
        Sanctum::actingAs($booking->client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'half',
            'reference_number' => 'GC-REF-0002',
            'payer_name' => 'Jane Doe',
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertCreated()->assertJsonPath('data.matching_status', 'matched');
        $fresh = $booking->fresh();
        $this->assertEquals('confirmed', $fresh->status->value);
        $this->assertEquals('partially_paid', $fresh->payment_status->value);
        $this->assertEquals(5000.0, $fresh->remainingBalance());
    }

    public function test_amount_must_match_the_selected_plan(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-0003',
            'payer_name' => 'Jane Doe',
            'amount' => 8000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(422);
    }

    public function test_payment_is_pending_verification_when_no_matching_reference_exists(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        // No photographer-side reference was ever registered for this code.
        $response = $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-UNKNOWN',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertCreated()->assertJsonPath('data.matching_status', 'not_matched');
        $fresh = $booking->fresh();
        $this->assertEquals('confirmed', $fresh->status->value); // "accepted" is Confirmed now
$this->assertNull($fresh->service_status); // unmatched payment must not settle or start the tracker
        $this->assertEquals('pending_verification', $fresh->payment_status->value);
    }

    public function test_already_used_reference_does_not_match_again(): void
    {
        $first = $this->acceptedBooking(10000);
        $this->registerReference($first, 'GC-REF-REUSED', 10000);
        Sanctum::actingAs($first->client);
        $this->postJson("/api/client/bookings/{$first->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-REUSED',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertCreated()->assertJsonPath('data.matching_status', 'matched');

        // Same photographer, same reference, a different booking.
        $second = $this->acceptedBooking(10000);
        Booking::query()->whereKey($second->id)->update(['photographer_id' => $first->photographer_id]);
        $second = $second->fresh();
        Sanctum::actingAs($second->client);

        $response = $this->postJson("/api/client/bookings/{$second->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-REUSED',
            'payer_name' => 'John Roe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reference_number']);
        $this->assertNotSame('fully_paid', $second->fresh()->payment_status?->value);
    }

    public function test_cannot_pay_for_a_booking_that_is_not_accepted(): void
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerPaymentConfig::factory()->for($photographer)->create();
        $booking = Booking::factory()->create([ // default/pending status
            'photographer_id' => $photographer->id,
            'total_price' => 10000,
        ]);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-0004',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(422);
    }

    public function test_cannot_submit_payment_twice_for_the_same_booking(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-0005',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertCreated();

        $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-0006',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(422);
    }

    public function test_another_client_cannot_submit_payment_for_someone_elses_booking(): void
    {
        $booking = $this->acceptedBooking(10000);
        $otherClient = User::factory()->create();
        Sanctum::actingAs($otherClient);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-0007',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(403);
    }

    public function test_photographer_cannot_submit_a_client_payment(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->photographer);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => 'full',
            'reference_number' => 'GC-REF-0008',
            'payer_name' => 'Jane Doe',
            'amount' => 10000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(403);
    }
}