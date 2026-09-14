<?php
// app/Actions/Photographer/Availability/UpdateBookingHourAction.php

namespace App\Actions\Photographer\Availability;

use App\Models\BookingHour;

class UpdateBookingHourAction
{
    public function __construct(protected CreateBookingHourAction $overlapChecker)
    {
    }

    public function execute(BookingHour $hour, array $data): BookingHour
    {
        $dayOfWeek = $data['day_of_week'] ?? $hour->day_of_week;
        $start = $data['start_time'] ?? $hour->start_time;
        $end = $data['end_time'] ?? $hour->end_time;

        $this->overlapChecker->assertNoOverlap($hour->user, $dayOfWeek, $start, $end, $hour->id);

        $hour->fill(['day_of_week' => $dayOfWeek, 'start_time' => $start, 'end_time' => $end])->save();

        return $hour->fresh();
    }
}