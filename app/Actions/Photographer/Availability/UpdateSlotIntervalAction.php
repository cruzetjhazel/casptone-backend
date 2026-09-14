<?php

namespace App\Actions\Photographer\Availability;

use App\Models\User;

class UpdateSlotIntervalAction
{
    public function execute(User $user, int $minutes): User
    {
        $user->update(['slot_interval_minutes' => $minutes]);

        return $user->fresh();
    }
}