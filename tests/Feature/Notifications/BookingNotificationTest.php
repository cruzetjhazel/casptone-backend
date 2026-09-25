<?php

namespace Tests\Feature\Notifications;

use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function bookablePhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();
        PhotographerProfile::factory()->for($user)->complete()->create();
        PhotographerPortfolioImage::factory()->for($user)->count(6)->create();
        Package::factory()->for($user)->published()->create();
        \App\Models\PhotographerPaymentConfig::factory()->for($user)->create();
        AvailabilityWindow::factory()->for($user)->create([
            'date' => now()->addDays(10)->format('Y-m-d'), 'start_time' => '09:00', 'end_time' => '18:00',
        ]);

        return $user;
    }
    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_creating_a_booking_notifies_client_and_photographer(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson('/api/client/bookings', [
            'photographer_id' => $photographer->id,
            'package_id' => $photographer->packages()->first()->id,
            'event_type' => 'wedding',
            'event_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '09:00',
            'location_type' => 'studio',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $client->id, 'type' => \App\Notifications\Booking\BookingRequestSubmittedNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $photographer->id, 'type' => \App\Notifications\Booking\NewBookingRequestNotification::class]);
    }

    public function test_accepting_a_booking_notifies_the_client(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $booking->client_id,
            'type' => \App\Notifications\Booking\BookingAcceptedNotification::class,
        ]);
    }

    public function test_rejecting_a_booking_notifies_the_client(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/reject", ['reason' => 'Unavailable'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $booking->client_id,
            'type' => \App\Notifications\Booking\BookingRejectedNotification::class,
        ]);
    }

    public function test_approved_cancellation_notifies_both_parties(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($booking->client);
        $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'x'])->assertOk();

        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/approve")->assertOk();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->client_id, 'type' => \App\Notifications\Booking\BookingCancelledNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $photographer->id, 'type' => \App\Notifications\Booking\BookingCancelledNotification::class]);
    }
}