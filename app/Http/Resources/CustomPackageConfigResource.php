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
            'buffer_minutes' => $this->buffer_minutes,
            'hourly_rate' => $this->hourly_rate,
            'min_hours' => $this->min_hours,
            'max_hours' => $this->max_hours,
            'updated_at' => $this->updated_at,
        ];
    }
}