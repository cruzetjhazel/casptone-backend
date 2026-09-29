<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\PhotographerApplicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotographerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_can_register_and_receives_a_draft_application(): void
    {
        $response = $this->postJson('/api/auth/register-photographer', [
            'name' => 'Alex Freelancer',
            'email' => 'alex@gmail.com',
            'phone_number' => '09171234567',
            'password' => 'Str0ngPass!23',
            'password_confirmation' => 'Str0ngPass!23',
            'photographer_type' => 'freelancer',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'alex@gmail.com',
            'account_type' => AccountType::Photographer->value,
        ]);

        $this->assertDatabaseHas('photographer_applications', [
            'photographer_type' => 'freelancer',
            'status' => PhotographerApplicationStatus::Draft->value,
        ]);
    }

    public function test_photographer_type_is_required(): void
    {
        $response = $this->postJson('/api/auth/register-photographer', [
            'name' => 'Alex',
            'email' => 'alex2@gmail.com',
            'password' => 'Str0ngPass!23',
            'password_confirmation' => 'Str0ngPass!23',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        \App\Models\User::factory()->create(['email' => 'dupe@gmail.com']);

        $response = $this->postJson('/api/auth/register-photographer', [
            'name' => 'Alex',
            'email' => 'dupe@gmail.com',
            'password' => 'Str0ngPass!23',
            'password_confirmation' => 'Str0ngPass!23',
            'photographer_type' => 'studio',
        ]);

        $response->assertStatus(422);
    }
}