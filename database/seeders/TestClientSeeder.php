<?php

namespace Database\Seeders;

use App\Models\ClientProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestClientSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('TEST_CLIENT_EMAIL', 'client@example.test');
        $password = env('TEST_CLIENT_PASSWORD', 'password');

        $client = User::firstOrCreate(
            ['email' => $email],
            User::factory()->raw([
                'email' => $email,
                'name' => 'Test Client',
                'password' => bcrypt($password),
            ])
        );

        ClientProfile::firstOrCreate(
            ['user_id' => $client->id],
            ClientProfile::factory()->raw()
        );

        $this->command->info("Seeded test client: {$email} / {$password}");
    }
}