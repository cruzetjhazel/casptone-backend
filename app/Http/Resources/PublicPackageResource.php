<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'included_items' => $this->included_items,
            'price' => $this->price,
            'outdoor_price' => $this->outdoor_price,
            'duration_minutes' => $this->duration_minutes,
            'schedule_mode' => $this->schedule_mode?->value ?? 'timed',
            'allows_multiple_sessions' => (bool) $this->allows_multiple_sessions,
            'max_sessions' => $this->max_sessions,
        ];
    }
}