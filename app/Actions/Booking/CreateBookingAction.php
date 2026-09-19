<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\AddOnStatus;
use App\Enums\BookingLocationType;
use App\Enums\BookingStatus;
use App\Enums\PackageStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Photographer\AvailabilityService;
use App\Services\Photographer\BookabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBookingAction
{
    public function __construct(
        protected BookabilityService $bookabilityService,
        protected AvailabilityService $availabilityService,
        protected LogActivityAction $activityLogger,
    ) {
    }

    public function execute(User $client, array $data): Booking
    {
        $photographer = User::findOrFail($data['photographer_id']);

        if (! $this->bookabilityService->isBookable($photographer)) {
            throw ValidationException::withMessages([
                'photographer_id' => ['This photographer is not currently bookable.'],
            ]);
        }

        $isCustom = (bool) ($data['is_custom_package'] ?? false);
        $customHours = null;

        if ($isCustom) {
            [$neededMinutes, $subtotal, $packageId, $packageSnapshot, $customSnapshot, $customHours] = $this->resolveCustomPackage($photographer, $data);
        } else {
            [$neededMinutes, $subtotal, $packageId, $packageSnapshot, $customSnapshot] = $this->resolveFixedPackage($photographer, $data);
        }

        [$addOnsSnapshot, $addOnsTotal] = $this->resolveAddOns($photographer, $data['add_on_ids'] ?? []);
        $subtotal += $addOnsTotal;

        $platformFee = (float) config('platform.fee', 30.00);
        $totalPrice = $subtotal + $platformFee;

        $this->assertSlotIsAvailable($photographer, $data['event_date'], $data['start_time'], $neededMinutes);

        $endTime = Carbon::parse($data['start_time'])->addMinutes($neededMinutes)->format('H:i');

        // Business rule (revised): a slot is only ever truly "taken" once a
        // booking for it has a CONFIRMED payment (partially or fully paid).
        // Multiple clients are allowed to request — and even be accepted
        // for — the same overlapping slot at the same time; this lets a
        // photographer keep a backup request alive in case the first client
        // never pays or cancels. Whichever booking gets its payment
        // confirmed first wins the slot; see ReleaseConflictingBookingsAction,
        // which auto-declines the other unpaid requests for that slot at
        // that point. We only need to guard against *paid* conflicts here.
        //
        // The check-then-insert below is wrapped in a transaction with a
        // row lock on the photographer so two clients paying/requesting at
        // the exact same instant can't both slip past assertNoConflict()
        // before either row commits (the race condition this replaced).
        $booking = DB::transaction(function () use (
            $client, $photographer, $packageId, $isCustom, $packageSnapshot,
            $customSnapshot, $customHours, $addOnsSnapshot, $data, $subtotal,
            $platformFee, $totalPrice, $endTime
        ) {
            $photographer = User::where('id', $photographer->id)->lockForUpdate()->firstOrFail();

            $this->assertNoConflict($photographer, $data['event_date'], $data['start_time'], $endTime);

            return Booking::create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'package_id' => $packageId,
                'is_custom_package' => $isCustom,
                'package_snapshot' => $packageSnapshot,
                'custom_package_snapshot' => $customSnapshot,
                'custom_hours' => $customHours,
                'add_ons_snapshot' => $addOnsSnapshot,
                'event_type' => $data['event_type'],
                'custom_event_type' => $data['custom_event_type'] ?? null,
                'event_date' => $data['event_date'],
                'start_time' => $data['start_time'],
                'end_time' => $endTime,
                'location_type' => $data['location_type'],
                'province_id' => $data['province_id'] ?? null,
                'city_municipality_id' => $data['city_municipality_id'] ?? null,
                'barangay_id' => $data['barangay_id'] ?? null,
                'event_address' => $data['event_address'] ?? null,
                'guest_count' => $data['guest_count'] ?? null,
                'special_requests' => $data['special_requests'] ?? null,
                'subtotal' => $subtotal,
                'platform_fee' => $platformFee,
                'total_price' => $totalPrice,
                'status' => BookingStatus::Pending,
                // 48 hours for the photographer to accept/reject before the
                // request auto-expires (see ExpireStaleBookingHoldsAction) —
                // long enough for a small/solo operator to reasonably check
                // their phone, short enough that a client isn't left hanging
                // for the client to reasonably check on their phone.
                'hold_expires_at' => now()->addHours(48),
            ]);
        });

        $photographer->notify(new \App\Notifications\Booking\NewBookingRequestNotification($booking));
        $client->notify(new \App\Notifications\Booking\BookingRequestSubmittedNotification($booking));

        $this->activityLogger->execute(
            causer: $client,
            subject: $booking,
            action: 'booking.created',
            description: "Created booking #{$booking->id} with {$photographer->name}",
        );

        return $booking;
    }

    protected function resolveFixedPackage(User $photographer, array $data): array
    {
        $package = $photographer->packages()->where('status', PackageStatus::Published)->find($data['package_id'] ?? null);

        if (! $package) {
            throw ValidationException::withMessages([
                'package_id' => ['This package is not available for this photographer.'],
            ]);
        }

        $neededMinutes = $package->duration_minutes + $package->buffer_minutes;

        $snapshot = [
            'name' => $package->name,
            'description' => $package->description,
            'price' => (string) $package->price,
            'duration_minutes' => $package->duration_minutes,
            'buffer_minutes' => $package->buffer_minutes,
        ];

        return [$neededMinutes, (float) $package->price, $package->id, $snapshot, null];
    }

    protected function resolveCustomPackage(User $photographer, array $data): array
    {
        $config = $photographer->customPackageConfig;

        if (! $config || ! $config->enabled) {
            throw ValidationException::withMessages([
                'is_custom_package' => ['Custom packages are not enabled for this photographer.'],
            ]);
        }

        $componentIds = $data['custom_component_ids'] ?? [];
        $components = $photographer->customPackageComponents()
            ->where('status', AddOnStatus::Active)
            ->whereIn('id', $componentIds)
            ->get();

        if (count($componentIds) !== $components->count()) {
            throw ValidationException::withMessages([
                'custom_component_ids' => ['One or more selected custom options are invalid.'],
            ]);
        }

        $bufferMinutes = (int) ($config->buffer_minutes ?? 0);

        // Sliding-hours pricing mode: the photographer set an hourly_rate
        // (and optionally min/max hours) on their custom package config,
        // and the client dragged the frontend slider to pick a coverage
        // length instead of choosing a discrete duration option. Coverage
        // duration and price both scale directly off the client's chosen
        // hours here, so there's no separate duration-component requirement
        // to check — every other (non-duration) component still applies as
        // a flat add-on on top.
        if ($config->hourly_rate !== null && array_key_exists('custom_hours', $data) && $data['custom_hours'] !== null) {
            $minHours = $config->min_hours ?? 1;
            $maxHours = $config->max_hours ?? 12;
            $hours = (int) $data['custom_hours'];

            if ($hours < $minHours || $hours > $maxHours) {
                throw ValidationException::withMessages([
                    'custom_hours' => ["Coverage hours must be between {$minHours} and {$maxHours} for this photographer."],
                ]);
            }

            $flatComponents = $components->filter(fn ($c) => $c->duration_minutes === null);
            $subtotal = (float) ($config->base_fee ?? 0)
                + ((float) $config->hourly_rate * $hours)
                + (float) $flatComponents->sum('price_addition');

            $snapshot = [
                'base_fee' => (string) ($config->base_fee ?? 0),
                'hourly_rate' => (string) $config->hourly_rate,
                'hours' => $hours,
                'duration_minutes' => $hours * 60,
                'buffer_minutes' => $bufferMinutes,
                'components' => $flatComponents->map(fn ($c) => [
                    'label' => $c->label,
                    'type' => $c->type->value,
                    'price_addition' => (string) $c->price_addition,
                ])->values()->toArray(),
            ];

            return [$hours * 60 + $bufferMinutes, $subtotal, null, null, $snapshot, $hours];
        }

        $subtotal = (float) ($config->base_fee ?? 0) + (float) $components->sum('price_addition');

        // The client must have selected EXACTLY ONE component carrying a real
        // coverage duration (see the 2026_09_06_211623_add_duration_to_custom_
        // package_table migration). We never guess a duration from the
        // photographer's fixed packages — a wrong guess would reserve less
        // time than the client actually booked and let a second client
        // double-book the remainder (this replaced an earlier fallback that
        // did exactly that; do not reintroduce it).
        //
        // Both zero and more-than-one duration components are rejected here:
        // zero means the calendar can't safely reserve any time at all, and
        // more than one is ambiguous (a photographer accidentally marking two
        // components as durations, or a tampered/duplicated request) — silently
        // taking the first one would let the wrong duration reserve the slot.
        $durationComponents = $components->filter(fn ($c) => $c->duration_minutes !== null);

        if ($durationComponents->count() === 0) {
            throw ValidationException::withMessages([
                'custom_component_ids' => ['Please select a photography coverage duration for your custom package.'],
            ]);
        }

        if ($durationComponents->count() > 1) {
            throw ValidationException::withMessages([
                'custom_component_ids' => ['Please select only one photography coverage duration option.'],
            ]);
        }

        $durationComponent = $durationComponents->first();

        $snapshot = [
            'base_fee' => (string) ($config->base_fee ?? 0),
            'duration_minutes' => $durationComponent->duration_minutes,
            'buffer_minutes' => $bufferMinutes,
            'components' => $components->map(fn ($c) => [
                'label' => $c->label,
                'type' => $c->type->value,
                'price_addition' => (string) $c->price_addition,
            ])->values()->toArray(),
        ];

        return [$durationComponent->duration_minutes + $bufferMinutes, $subtotal, null, null, $snapshot, null];
    }

    protected function resolveAddOns(User $photographer, array $addOnIds): array
    {
        if (empty($addOnIds)) {
            return [[], 0.0];
        }

        $addOns = $photographer->addOns()->where('status', AddOnStatus::Active)->whereIn('id', $addOnIds)->get();

        if (count($addOnIds) !== $addOns->count()) {
            throw ValidationException::withMessages([
                'add_on_ids' => ['One or more selected add-ons are invalid or unavailable.'],
            ]);
        }

        $snapshot = $addOns->map(fn ($a) => ['name' => $a->name, 'price' => (string) $a->price])->values()->toArray();

        return [$snapshot, (float) $addOns->sum('price')];
    }

    protected function assertSlotIsAvailable(User $photographer, string $date, string $startTime, int $neededMinutes): void
    {
        $slots = $this->availabilityService->getAvailableStartTimes($photographer, $date, $neededMinutes);

        if (! in_array($startTime, $slots, true)) {
            throw ValidationException::withMessages([
                'start_time' => ['This date and time is not available for booking.'],
            ]);
        }
    }

    /**
     * Only blocks against bookings whose payment is actually confirmed
     * (partially or fully paid). Pending requests and accepted-but-unpaid
     * bookings do NOT block a new request for the same slot — if the other
     * client cancels or never pays, this slot was never truly unavailable.
     * Call this from inside a transaction with the photographer row locked
     * (see CreateBookingAction::execute and the payment-confirmation
     * actions) so the check is atomic with whatever write follows it.
     */
    protected function assertNoConflict(User $photographer, string $date, string $start, string $end): void
    {
        $conflict = $photographer->bookingsAsPhotographer()
            ->where('event_date', $date)
            ->where('status', \App\Enums\BookingStatus::Confirmed)
            ->whereIn('payment_status', [
                \App\Enums\BookingPaymentStatus::PartiallyPaid,
                \App\Enums\BookingPaymentStatus::FullyPaid,
            ])
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_time' => ['This time slot is already booked and paid for.'],
            ]);
        }
    }
}