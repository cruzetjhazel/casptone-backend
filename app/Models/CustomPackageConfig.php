<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomPackageConfig extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'enabled', 'base_fee', 'buffer_minutes', 'hourly_rate', 'min_hours', 'max_hours'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'base_fee' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'min_hours' => 'integer',
            'max_hours' => 'integer',
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