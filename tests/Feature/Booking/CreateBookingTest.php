<?php

namespace Tests\Feature\Booking;

use App\Models\AddOn;
use App\Models\AvailabilityWindow;
use App\Models\CustomPackageComponent;
use App\Models\CustomPackageConfig;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerProfile;
use App\Models\PhotographerPortfolioImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreateBookingTest extends TestCase
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
        'date' => now()->addDays(10)->format('Y-m-d'),
        'start_time' => '09:00',
        'end_time' => '18:00', // was 17:00 — 480 min duration + 30 min buffer needs 510 min of room
    ]);

    return $user;
}

    protected function validPayload(User $photographer): array
{
    $package = $photographer->packages()->first();

    return [
        'photographer_id' => $photographer->id,
        'package_id' => $package?->id, // was $package->id
        'event_type' => 'wedding',
        'event_date' => now()->addDays(10)->format('Y-m-d'),
        'start_time' => '09:00',
        'location_type' => 'studio',
    ];
}

    public function test_client_can_create_a_booking_for_a_bookable_photographer(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/client/bookings', $this->validPayload($photographer));

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('bookings', ['client_id' => $client->id, 'photographer_id' => $photographer->id]);
    }

    public function test_guest_cannot_create_a_booking(): void
    {
        $photographer = $this->bookablePhotographer();

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(401);
    }

    public function test_photographer_account_cannot_create_a_booking(): void
    {
        $photographer = $this->bookablePhotographer();
        $anotherPhotographer = User::factory()->photographer()->create();
        Sanctum::actingAs($anotherPhotographer);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(403);
    }

    public function test_booking_is_rejected_for_a_non_bookable_photographer(): void
    {
        // Approved but no packages / incomplete profile -> not bookable
        $photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($photographer)->approved()->create();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(422);
    }

    public function test_booking_rejected_when_start_time_not_in_available_slots(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['start_time'] = '20:00'; // outside 09:00-17:00 window

        $this->postJson('/api/client/bookings', $payload)->assertStatus(422);
    }

    public function test_conflicting_time_is_rejected(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertCreated();

        $otherClient = User::factory()->create();
        Sanctum::actingAs($otherClient);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(422);
    }

    public function test_package_must_belong_to_the_selected_photographer(): void
    {
        $photographer = $this->bookablePhotographer();
        $otherPhotographer = $this->bookablePhotographer();
        $foreignPackage = $otherPhotographer->packages()->first();

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['package_id'] = $foreignPackage->id;

        $this->postJson('/api/client/bookings', $payload)->assertStatus(422);
    }

    public function test_add_ons_are_snapshotted_and_priced(): void
    {
        $photographer = $this->bookablePhotographer();
        $addOn = AddOn::factory()->for($photographer)->create(['price' => 1500]);
        $package = $photographer->packages()->first();

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['add_on_ids'] = [$addOn->id];

        $response = $this->postJson('/api/client/bookings', $payload);

        $response->assertCreated();
        $this->assertEquals($package->price + 1500, $response->json('data.total_price'));
    }

    public function test_custom_package_booking_uses_configured_pricing(): void
    {
        $photographer = $this->bookablePhotographer();
        CustomPackageConfig::factory()->for($photographer)->create(['enabled' => true, 'base_fee' => 2000]);
        $component = CustomPackageComponent::factory()->for($photographer)->create(['price_addition' => 1000]);

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        unset($payload['package_id']);
        $payload['is_custom_package'] = true;
        $payload['custom_component_ids'] = [$component->id];

        $response = $this->postJson('/api/client/bookings', $payload);

        $response->assertCreated();
        $this->assertEquals(3000, $response->json('data.total_price'));
    }

    public function test_event_address_required_for_non_studio_location(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['location_type'] = 'client_location';

        $this->postJson('/api/client/bookings', $payload)->assertStatus(422);
    }

    public function test_coverage_area_notice_is_true_for_non_studio_locations(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['location_type'] = 'outdoor_location';
        $payload['event_address'] = '123 Test St';

        $response = $this->postJson('/api/client/bookings', $payload);

        $response->assertCreated()->assertJsonPath('data.coverage_area_notice', true);
    }
}