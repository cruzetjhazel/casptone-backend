<?php

namespace App\Actions\Photographer\Availability;

use App\Models\BookingHour;

class DeleteBookingHourAction
{
    public function execute(BookingHour $hour): void
    {
        $hour->delete();
    }
}