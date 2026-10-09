<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingReschedule extends Model
{
    protected $fillable = [
        'booking_id', 'type',
        'original_event_date', 'original_start_time', 'original_end_time',
        'new_event_date', 'new_start_time', 'new_end_time',
        'reason', 'requested_by', 'approved_by', 'requested_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'original_event_date' => 'date:Y-m-d',
            'new_event_date' => 'date:Y-m-d',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}