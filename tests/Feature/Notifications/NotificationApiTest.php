<?php

namespace Tests\Feature\Notifications;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\Booking\BookingAcceptedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_notifications(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_user_can_list_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $user->id]);
        $user->notify(new BookingAcceptedNotification($booking));
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_cannot_see_another_users_notifications(): void
    {
        $owner = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $owner->id]);
        $owner->notify(new BookingAcceptedNotification($booking));

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $response = $this->getJson('/api/notifications');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $owner->id]);
        $owner->notify(new BookingAcceptedNotification($booking));
        $notificationId = $owner->notifications()->first()->id;

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->postJson("/api/notifications/{$notificationId}/read")->assertStatus(404);
    }

    public function test_user_can_mark_their_own_notification_as_read(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $user->id]);
        $user->notify(new BookingAcceptedNotification($booking));
        $notificationId = $user->notifications()->first()->id;
        Sanctum::actingAs($user);

        $this->postJson("/api/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);
    }

    public function test_mark_all_as_read(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $user->id]);
        $user->notify(new BookingAcceptedNotification($booking));
        $user->notify(new BookingAcceptedNotification($booking));
        Sanctum::actingAs($user);

        $this->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_repeated_get_requests_do_not_create_notifications(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications');
        $this->getJson('/api/notifications');
        $this->getJson('/api/notifications');

        $this->assertSame(0, $user->notifications()->count());
    }
}