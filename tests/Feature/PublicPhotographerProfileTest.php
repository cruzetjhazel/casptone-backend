<?php

namespace Tests\Feature;

use App\Models\PhotographerApplication;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPhotographerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_exposes_only_intended_fields(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();
        PhotographerProfile::factory()->for($user)->complete()->create();
        PhotographerPortfolioImage::factory()->for($user)->count(6)->create();

        $response = $this->getJson("/api/photographers/{$user->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'photographer_type', 'business_name', 'location',
                    'coverage_area', 'services', 'starting_price', 'style', 'bio',
                    'profile_photo_url', 'cover_photo_url', 'social_links', 'portfolio',
                ],
            ]);
    }

    public function test_public_profile_does_not_expose_verification_or_internal_data(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create([
            'government_id_path' => 'verification-documents/1/gov.jpg',
        ]);
        PhotographerProfile::factory()->for($user)->complete()->create();

        $response = $this->getJson("/api/photographers/{$user->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.government_id_path');
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.reviewed_by');
        $response->assertJsonMissingPath('data.rejection_reason');
    }

    public function test_unapproved_photographer_has_no_public_profile(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->pendingReview()->create();
        PhotographerProfile::factory()->for($user)->complete()->create();

        $this->getJson("/api/photographers/{$user->id}")->assertStatus(404);
    }

    public function test_client_account_has_no_public_photographer_profile(): void
    {
        $client = User::factory()->create();

        $this->getJson("/api/photographers/{$client->id}")->assertStatus(404);
    }
}