<?php

namespace App\Actions\Photographer\Package;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\PackageScheduleMode;
use App\Models\Package;
use Illuminate\Validation\ValidationException;

class UpdatePackageAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Package $package, array $data): Package
    {
        if (! $package->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Archived packages cannot be edited. Restore it first.'],
            ]);
        }

        if (array_key_exists('allows_multiple_sessions', $data) && ! $data['allows_multiple_sessions']) {
            $data['max_sessions'] = null;
        }

        $package->fill(collect($data)->only([
            'name', 'description', 'included_items', 'price', 'outdoor_price', 'duration_minutes', 'buffer_minutes',
            'schedule_mode', 'allows_multiple_sessions', 'max_sessions',
        ])->toArray());

        if (($package->schedule_mode ?? PackageScheduleMode::Timed) === PackageScheduleMode::Timed
            && $package->duration_minutes === null) {
            throw ValidationException::withMessages([
                'duration_minutes' => ['A package with a set schedule needs a duration. Enter one, or switch it to open-ended.'],
            ]);
        }

        $package->save();

        $fresh = $package->fresh();

        $this->activityLogger->execute(
            causer: $fresh->user,
            subject: $fresh,
            action: 'package.updated',
            description: "Updated package \"{$fresh->name}\"",
        );

        return $fresh;
    }
}