<?php

namespace Tests\Feature\Notifications;

use App\Models\Booking;
use App\Models\PhotographerPaymentConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentNotificationTest extends TestCase
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

    /** The client submits a GCash reference; returns the new payment's id. */
    protected function submit(Booking $booking, string $reference, string $plan = 'full', float $amount = 10000): int
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/payments", [
            'plan' => $plan, 'reference_number' => $reference, 'payer_name' => 'Jane Doe',
            'amount' => $amount, 'payment_date' => now()->format('Y-m-d'),
        ])->assertCreated()->json('data.id');
    }

    public function test_submitted_payment_notifies_both_parties_and_does_not_confirm_the_booking(): void
    {
        $booking = $this->acceptedBooking(10000);
        $this->submit($booking, '2000000000001');

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\PaymentPendingVerificationNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->photographer_id, 'type' => \App\Notifications\Payment\PaymentNeedsReviewNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Booking\BookingConfirmedNotification::class]);
    }

    public function test_manual_verification_of_a_full_payment_confirms_booking_and_notifies_everyone(): void
    {
        $booking = $this->acceptedBooking(10000);
        $paymentId = $this->submit($booking, '2000000000002');

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/verify")->assertOk();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\PaymentManuallyVerifiedNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Booking\BookingConfirmedNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->photographer_id, 'type' => \App\Notifications\Booking\BookingConfirmedNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\FullPaymentCompletedNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->photographer_id, 'type' => \App\Notifications\Payment\FullPaymentCompletedNotification::class]);
        $this->assertEquals('confirmed', $booking->fresh()->status->value);
    }

    public function test_manual_verification_of_a_half_payment_notifies_remaining_balance_not_full_payment(): void
    {
        $booking = $this->acceptedBooking(10000);
        $paymentId = $this->submit($booking, '2000000000003', 'half', 5000);

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/verify")->assertOk();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\RemainingBalanceNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\FullPaymentCompletedNotification::class]);
    }

    public function test_payment_rejection_notifies_client_and_does_not_confirm(): void
    {
        $booking = $this->acceptedBooking(10000);
        $paymentId = $this->submit($booking, '2000000000004');

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/reject", ['notes' => 'No funds received.'])->assertOk();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\PaymentRejectedNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Booking\BookingConfirmedNotification::class]);
    }

    public function test_onsite_payment_notifies_full_payment_completed(): void
    {
        $booking = $this->acceptedBooking(10000);
        $booking->update(['payment_plan' => 'half', 'payment_status' => 'partially_paid']);
        \App\Models\Payment::create([
            'booking_id' => $booking->id, 'client_id' => $booking->client_id, 'photographer_id' => $booking->photographer_id,
            'type' => 'online', 'method' => 'gcash', 'plan' => 'half', 'amount' => 5000,
            'reference_number' => 'GC-PRE', 'payer_name' => 'Jane Doe', 'payment_date' => now()->format('Y-m-d'),
            'matching_status' => 'matched',
        ]);
        $booking->update(['status' => 'confirmed']);

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 5000, 'payment_date' => now()->format('Y-m-d'),
        ])->assertOk();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\OnsitePaymentRecordedNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Payment\FullPaymentCompletedNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->photographer_id, 'type' => \App\Notifications\Payment\FullPaymentCompletedNotification::class]);
    }
}