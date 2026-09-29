<?php

namespace App\Actions\Auth;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\ClientProfile;
use App\Models\User;

class RegisterClientAction
{
    public function execute(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'password' => $data['password'],
            'account_type' => AccountType::Client,
            'account_status' => AccountStatus::Active,
            'terms_accepted_at' => now(),
        ]);

        $address = collect([$data['address'] ?? null, $data['city'] ?? null, $data['province'] ?? null])
            ->filter(fn ($part) => filled($part))
            ->implode(', ');

        if ($address !== '') {
            ClientProfile::create(['user_id' => $user->id, 'address' => $address]);
        }

        return $user;
    }
}