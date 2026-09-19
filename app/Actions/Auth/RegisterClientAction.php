<?php

namespace App\Actions\Auth;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\User;

class RegisterClientAction
{
    public function execute(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'password' => $data['password'],
            'account_type' => AccountType::Client,
            'account_status' => AccountStatus::Active,
            'terms_accepted_at' => now(),
        ]);
    }
}