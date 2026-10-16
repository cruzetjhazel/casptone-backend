<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_number' => $this->phone_number,
            'account_type' => $this->account_type->value,
            'account_status' => $this->account_status->value,
            'created_at' => $this->created_at,
        ];
    }
}