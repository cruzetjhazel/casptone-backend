<?php

namespace Tests\Feature\Client;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_profile(): void
    {
        $this->getJson('/api/client/profile')->assertStatus(401);
    }

    public function test_client_can_view_their_profile(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/client/profile');

        $response->assertOk()->assertJsonPath('data.name', 'Jane Doe')->assertJsonPath('data.email', $user->email);
    }

    public function test_client_can_update_their_profile(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/client/profile', [
            'name' => 'Updated Name',
            'birthday' => '1995-05-10',
            'gender' => 'Female',
            'address' => '123 Test St',
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertOk()->assertJsonPath('data.name', 'Updated Name');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name']);
        $this->assertDatabaseHas('client_profiles', ['user_id' => $user->id, 'gender' => 'Female']);
    }

    public function test_email_cannot_be_updated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/client/profile', ['email' => 'changed@example.com'])->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    public function test_client_can_change_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        Sanctum::actingAs($user);

        $this->postJson('/api/client/change-password', [
            'current_password' => 'OldPassword!23',
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword!45', $user->fresh()->password));
    }

    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        Sanctum::actingAs($user);

        $this->postJson('/api/client/change-password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertStatus(422);
    }
}