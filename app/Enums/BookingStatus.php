<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NoShow = 'no_show';
    // Accepted by the photographer, but another PAID booking holds the same schedule.
    // Recoverable: it may be offered the slot if that booking is cancelled.
    case Waitlisted = 'waitlisted';
}