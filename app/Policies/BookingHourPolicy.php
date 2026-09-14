<?php

namespace App\Policies;

use App\Models\BookingHour;
use App\Models\User;

class BookingHourPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEligibleForBusinessManagement();
    }

    public function create(User $user): bool
    {
        return $user->isEligibleForBusinessManagement();
    }

    /**
     * Handles two call shapes from BookingHourController:
     *  - update($user, $bookingHour) for PATCH booking-hours/{bookingHour} —
     *    ownership check against that specific row.
     *  - update($user, BookingHour::class) for PATCH booking-hours/interval
     *    (there's no single row being edited there, just the user's own
     *    slot_interval_minutes setting) — falls back to the same
     *    business-management check used by create()/viewAny().
     */
    public function update(User $user, BookingHour|string $bookingHour): bool
    {
        if ($bookingHour instanceof BookingHour) {
            return $user->id === $bookingHour->user_id;
        }

        return $user->isEligibleForBusinessManagement();
    }

    public function delete(User $user, BookingHour $bookingHour): bool
    {
        return $user->id === $bookingHour->user_id;
    }
}