<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/auth/register-client', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'phone_number' => '09171234567',
            'password' => 'Str0ngPass!23',
            'password_confirmation' => 'Str0ngPass!23',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user' => ['id', 'email', 'account_type'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'account_type' => 'client']);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Str0ngPass!23']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Str0ngPass!23',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_login_fails_with_incorrect_password(): void
    {
        $user = User::factory()->create(['password' => 'Str0ngPass!23']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_suspended_account_cannot_login(): void
    {
        $user = User::factory()->suspended()->create(['password' => 'Str0ngPass!23']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Str0ngPass!23',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_guest_cannot_access_protected_route(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401)->assertJsonPath('success', false);
    }
}