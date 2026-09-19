<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicCustomPackageConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'enabled' => $this->enabled,
            'base_fee' => $this->base_fee,
            'hourly_rate' => $this->hourly_rate,
            'min_hours' => $this->min_hours,
            'max_hours' => $this->max_hours,
        ];
    }
}