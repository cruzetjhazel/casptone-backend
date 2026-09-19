<?php

namespace App\Actions\Photographer\CustomPackage;

use App\Models\CustomPackageConfig;
use App\Models\User;

class UpdateCustomPackageConfigAction
{
    public function execute(User $user, array $data): CustomPackageConfig
    {
        // The studio UI saves enabled/base_fee and buffer_minutes as separate
        // requests (see StudioPackages.tsx's saveBaseFee vs. the buffer field).
        // Falling back to 0 whenever buffer_minutes is omitted would silently
        // wipe out an already-configured buffer on every unrelated save, so we
        // fall back to whatever is already stored instead.
        $existingBuffer = $user->customPackageConfig?->buffer_minutes ?? 0;
        $existing = $user->customPackageConfig;

        return CustomPackageConfig::updateOrCreate(
            ['user_id' => $user->id],
            [
                'enabled' => $data['enabled'],
                'base_fee' => $data['base_fee'] ?? null,
                'buffer_minutes' => $data['buffer_minutes'] ?? $existingBuffer,
                // Same "don't silently wipe out an already-configured value"
                // rule as buffer_minutes above — the studio UI may save
                // these fields in a separate request from enabled/base_fee.
                'hourly_rate' => array_key_exists('hourly_rate', $data) ? $data['hourly_rate'] : $existing?->hourly_rate,
                'min_hours' => array_key_exists('min_hours', $data) ? $data['min_hours'] : $existing?->min_hours,
                'max_hours' => array_key_exists('max_hours', $data) ? $data['max_hours'] : $existing?->max_hours,
            ]
        );
    }
}