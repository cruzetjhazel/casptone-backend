<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionInactivityTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user, ?\DateTimeInterface $lastUsed): string
    {
        $new = $user->createToken('api');
        $new->accessToken->forceFill(['last_used_at' => $lastUsed])->save();

        return $new->plainTextToken;
    }

    public function test_token_used_within_24_hours_is_accepted_and_refreshed(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user, now()->subHours(23));

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        // Activity slides the window forward.
        $this->assertTrue($user->tokens()->first()->last_used_at->gt(now()->subMinute()));
    }

    public function test_token_idle_for_more_than_24_hours_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user, now()->subHours(25));

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_never_used_token_is_measured_from_its_creation_time(): void
    {
        $user = User::factory()->create();
        $new = $user->createToken('api');
        $new->accessToken->forceFill(['last_used_at' => null, 'created_at' => now()->subHours(25)])->save();

        $this->withToken($new->plainTextToken)->getJson('/api/auth/me')->assertUnauthorized();
    }
}