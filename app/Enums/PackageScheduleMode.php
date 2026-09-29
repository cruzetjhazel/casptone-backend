<?php

namespace App\Enums;

enum PackageScheduleMode: string
{
    case Timed = 'timed';
    case Open = 'open';
}