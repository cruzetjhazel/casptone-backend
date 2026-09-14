<?php

namespace App\Actions\Photographer\Availability;

use App\Models\BookingHour;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CreateBookingHourAction
{
    public function execute(User $user, array $data): BookingHour
    {
        $this->assertNoOverlap($user, $data['day_of_week'], $data['start_time'], $data['end_time']);

        return BookingHour::create([
            'user_id' => $user->id,
            'day_of_week' => $data['day_of_week'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
        ]);
    }

    public function assertNoOverlap(User $user, int $dayOfWeek, string $start, string $end, ?int $ignoreId = null): void
    {
        $overlaps = $user->bookingHours()
            ->where('day_of_week', $dayOfWeek)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_time' => ['This period overlaps an existing booking-hours period for this day.'],
            ]);
        }
    }
}