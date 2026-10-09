<?php

namespace App\Http\Resources;

use App\Enums\PackageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class FavoritePackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $package = $this->package;
        $photographer = $package->user;
        $application = $photographer?->photographerApplication;
        $profile = $photographer?->photographerProfile;

        return [
            'id' => $this->id,
            'package_id' => $package->id,
            'photographer_id' => $photographer?->id,
            'photographer_name' => $application?->business_name ?? $photographer?->name,
            'profile_photo_url' => $profile?->profile_photo_path
                ? Storage::disk('public')->url($profile->profile_photo_path)
                : null,
            'name' => $package->name,
            'description' => $package->description,
            'included_items' => $package->included_items ?? [],
            'price' => $package->price,
            'outdoor_price' => $package->outdoor_price,
            'duration_minutes' => $package->duration_minutes,
            // false once the photographer archives or unpublishes the package
            'is_available' => $package->status === PackageStatus::Published,
            'favorited_at' => $this->created_at,
        ];
    }
}