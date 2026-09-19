<?php

namespace App\Actions\Photographer;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\PhotographerApplicationStatus;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterPhotographerAction
{
    public function execute(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'] ?? null,
                'password' => $data['password'],
                'account_type' => AccountType::Photographer,
                'account_status' => AccountStatus::Active,
                'terms_accepted_at' => now(),
            ]);

            PhotographerApplication::create([
                'user_id' => $user->id,
                'photographer_type' => $data['photographer_type'],
                'status' => PhotographerApplicationStatus::Draft,
            ]);

            return $user->load('photographerApplication');
        });
    }
}