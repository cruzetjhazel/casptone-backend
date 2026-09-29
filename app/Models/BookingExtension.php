<?php

namespace App\Models;

use App\Enums\BookingExtensionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingExtension extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'booking_schedule_id', 'requested_hours', 'hourly_rate',
        'additional_charge', 'status', 'requested_at', 'decided_at',
        'decline_reason', 'new_end_time',
    ];

    protected function casts(): array
    {
        return [
            'requested_hours' => 'integer',
            'hourly_rate' => 'decimal:2',
            'additional_charge' => 'decimal:2',
            'status' => BookingExtensionStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BookingSchedule::class, 'booking_schedule_id');
    }
}
