<?php

namespace App\Http\Controllers\Api;

use App\Enums\PackageStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Photographer\AvailabilityService;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPhotographerAvailabilityController extends Controller
{
    use ApiResponses;

    // Client-supplied ceiling only — mirrors CustomPackageComponentRequest's
    // duration_minutes bound. The actual reserved time (duration + buffer)
    // is still recomputed and re-validated server-side against the real
    // selected components in CreateBookingAction::resolveCustomPackage();
    // this is just so an availability probe can't request an absurd window.
    private const MAX_CUSTOM_DURATION_MINUTES = 1440;

    public function calendar(Request $request, User $user, AvailabilityService $service)
    {
        $this->guardPublicAccess($user);

        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            ...$this->durationSourceRules(),
        ]);

        $durationMinutes = $this->resolveDurationMinutes($user, $request);

        $start = "{$request->query('month')}-01";
        $end = date('Y-m-t', strtotime($start));

        $summary = $service->getMonthSummary($user, $start, $end, $durationMinutes);

        return $this->success($summary);
    }

    public function slots(Request $request, User $user, AvailabilityService $service)
    {
        $this->guardPublicAccess($user);

        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            ...$this->durationSourceRules(),
        ]);

        $durationMinutes = $this->resolveDurationMinutes($user, $request);

        $slots = $service->getAvailableStartTimes($user, $request->query('date'), $durationMinutes);

        return $this->success(['date' => $request->query('date'), 'start_times' => $slots]);
    }

    /**
     * A caller identifies the session they're checking availability for via
     * EITHER package_id (fixed) OR custom_duration_minutes (custom) — never
     * both, never neither.
     */
    protected function durationSourceRules(): array
    {
        return [
            'package_id' => ['required_without:custom_duration_minutes', 'nullable', 'integer'],
            'custom_duration_minutes' => [
                'required_without:package_id', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CUSTOM_DURATION_MINUTES,
            ],
        ];
    }

    /**
     * Fixed-package path is byte-for-byte what it was before this change:
     * resolvePackage() + duration_minutes + buffer_minutes off the Package
     * row. Custom path is new: the client sends only their chosen coverage
     * duration, and the buffer is always resolved from the photographer's
     * own CustomPackageConfig — never accepted from the request — for the
     * same reason CreateBookingAction never trusts a client-supplied buffer.
     */
    protected function resolveDurationMinutes(User $user, Request $request): int
    {
        if ($request->filled('package_id')) {
            $package = $this->resolvePackage($user, $request->integer('package_id'));

            return $package->duration_minutes + $package->buffer_minutes;
        }

        $config = $user->customPackageConfig;

        if (! $config || ! $config->enabled) {
            throw ValidationException::withMessages([
                'custom_duration_minutes' => ['Custom packages are not enabled for this photographer.'],
            ]);
        }

        return $request->integer('custom_duration_minutes') + (int) ($config->buffer_minutes ?? 0);
    }

    protected function guardPublicAccess(User $user): void
    {
    if (! $user->isPhotographer() || ! $user->isApprovedPhotographer() || $user->account_status !== \App\Enums\AccountStatus::Active) {
            throw new NotFoundHttpException('Photographer not found.');
        }
    }

    protected function resolvePackage(User $user, int $packageId)
    {
        $package = $user->packages()->where('status', PackageStatus::Published)->find($packageId);

        abort_if(! $package, 404, 'Package not found for this photographer.');

        return $package;
    }
}