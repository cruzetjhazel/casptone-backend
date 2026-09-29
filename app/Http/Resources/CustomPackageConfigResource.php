<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomPackageConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'enabled' => $this->enabled,
            'base_fee' => $this->base_fee,
            'base_hours' => $this->base_hours,
            'buffer_minutes' => $this->buffer_minutes,
            'hourly_rate' => $this->hourly_rate,
            'min_hours' => $this->min_hours,
            'max_hours' => $this->max_hours,
            'allows_multiple_sessions' => (bool) $this->allows_multiple_sessions,
            'max_sessions' => $this->max_sessions,
            'base_fee_mode' => $this->base_fee_mode,
            'pricing_model' => $this->pricing_model ?: ($this->hourly_rate !== null ? 'hourly' : 'fixed'),
            'unit_rate' => $this->unit_rate,
            'coverage_hours' => $this->coverage_hours,
            'max_people' => $this->max_people,
            'updated_at' => $this->updated_at,
        ];
    }
}