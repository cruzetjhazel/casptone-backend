<?php

namespace App\Actions\Photographer\Package;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\PackageStatus;
use App\Models\Package;
use App\Models\User;

class CreatePackageAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(User $user, array $data): Package
    {
        $package = Package::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'included_items' => $data['included_items'] ?? null,
'price' => $data['price'],
            'outdoor_price' => $data['outdoor_price'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'buffer_minutes' => $data['buffer_minutes'] ?? 0,
            'schedule_mode' => $data['schedule_mode'] ?? 'timed',
            'allows_multiple_sessions' => (bool) ($data['allows_multiple_sessions'] ?? false),
            'max_sessions' => ($data['allows_multiple_sessions'] ?? false) ? ($data['max_sessions'] ?? null) : null,
            'status' => PackageStatus::Draft,
        ]);

        $this->activityLogger->execute(
            causer: $user,
            subject: $package,
            action: 'package.created',
            description: "Created package \"{$package->name}\"",
        );

        return $package;
    }
}