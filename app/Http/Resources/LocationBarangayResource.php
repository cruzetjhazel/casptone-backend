<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationBarangayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'psgc_code' => $this->psgc_code,
            'city_municipality_id' => $this->city_municipality_id,
            'name' => $this->name,
        ];
    }
}