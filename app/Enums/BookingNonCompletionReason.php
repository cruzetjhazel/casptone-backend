<?php

namespace App\Enums;

enum BookingNonCompletionReason: string
{
    // Client reports this one.
    case PhotographerNoShow = 'photographer_no_show';
    // Photographer reports this one.
    case ClientNoShow = 'client_no_show';
}