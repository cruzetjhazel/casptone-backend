<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomPackageConfig extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'enabled', 'base_fee', 'base_hours', 'buffer_minutes', 'hourly_rate', 'outdoor_hourly_rate', 'min_hours', 'max_hours', 'allows_multiple_sessions', 'max_sessions', 'base_fee_mode', 'pricing_model', 'unit_rate', 'coverage_hours', 'max_people'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'base_fee' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'outdoor_hourly_rate' => 'decimal:2',
            'base_hours' => 'integer',
            'min_hours' => 'integer',
            'max_hours' => 'integer',
            'allows_multiple_sessions' => 'boolean',
            'max_sessions' => 'integer',
            'unit_rate' => 'decimal:2',
            'coverage_hours' => 'integer',
            'max_people' => 'integer',
            // Applied to every custom-package booking this photographer
            // receives — mirrors Package.buffer_minutes, which exists
            // per fixed package instead. See CreateBookingAction::
            // resolveCustomPackage().
            'buffer_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}