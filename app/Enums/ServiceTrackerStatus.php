<?php

namespace App\Enums;

enum ServiceTrackerStatus: string
{
    case Upcoming = 'upcoming';
    case EventDay = 'event_day';
    case Editing = 'editing';
    case Delivered = 'delivered';
    case Completed = 'completed';

    public static function ordered(): array
    {
        return [self::Upcoming, self::EventDay, self::Editing, self::Delivered, self::Completed];
    }
}