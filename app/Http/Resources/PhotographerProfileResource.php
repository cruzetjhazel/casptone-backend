<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PhotographerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Same 7-day rule as the client. Null means no waiting period.
        $reactivatedAt = $this->user?->reactivated_at;
        $deactivateAvailableOn = $reactivatedAt
            ? \Illuminate\Support\Carbon::parse($reactivatedAt)->addDays(7)
            : null;
        if ($deactivateAvailableOn && ! $deactivateAvailableOn->isFuture()) {
            $deactivateAvailableOn = null;
        }

        return [
            'id' => $this->id,
            'bio' => $this->bio,
            'style' => $this->style,
            'photographer_type' => $this->user?->photographerApplication?->photographer_type?->value,
            'profile_photo_url' => $this->profile_photo_path ? Storage::disk('public')->url($this->profile_photo_path) : null,
            'cover_photo_url' => $this->cover_photo_path ? Storage::disk('public')->url($this->cover_photo_path) : null,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'website' => $this->website,
            'is_complete' => $this->isComplete(),
            'deactivate_available_on' => $deactivateAvailableOn?->toISOString(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}