<?php

namespace App\Enums;

enum BookingExtensionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
}