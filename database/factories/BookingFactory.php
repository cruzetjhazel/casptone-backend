<?php

namespace Database\Factories;

use App\Enums\BookingLocationType;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentPlan;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $package = Package::factory()->published();

        return [
            'client_id' => User::factory(),
            'photographer_id' => User::factory()->photographer(),
            'package_id' => $package,
            'is_custom_package' => false,
            'package_snapshot' => ['name' => 'Basic Wedding Package', 'price' => 10000],
            'add_ons_snapshot' => [],
            'event_type' => 'wedding',
            'event_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:30',
            'location_type' => BookingLocationType::Studio,
            'subtotal' => 10000,
            'total_price' => 10000,
            'status' => BookingStatus::Pending,
            'hold_expires_at' => now()->addHours(24),
        ];
    }

    public function accepted(): static
    {
        // Mirrors AcceptBookingAction: Pending -> Confirmed is the real
        // outcome. There's no separate 'accepted' status on this enum.
        return $this->state(fn () => ['status' => BookingStatus::Confirmed, 'hold_expires_at' => null]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Confirmed,
            'payment_plan' => PaymentPlan::Full,
            'payment_status' => BookingPaymentStatus::FullyPaid,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Completed,
            'payment_plan' => PaymentPlan::Full,
            'payment_status' => BookingPaymentStatus::FullyPaid,
            // 'completed' was dropped from chk_booking_service_status by
            // 2026_09_07_082733_fix_service_status_check_constraint_table;
            // Delivered is the current terminal state.
            'service_status' => ServiceTrackerStatus::Delivered,
            'service_status_updated_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        // Mirrors RejectBookingAction: Pending -> Cancelled + rejection_reason.
        // There's no separate 'rejected' status on this enum.
        return $this->state(fn () => [
            'status' => BookingStatus::Cancelled,
            'rejection_reason' => 'Not available for this date.',
            'hold_expires_at' => null,
        ]);
    }
    /**
 * Demo/testing helper: puts a Confirmed, fully-paid booking directly into
 * any service tracker stage (Upcoming, EventDay, Editing, Delivered).
 * Not used by production code paths — those always derive service_status
 * through BookingObserver, RunServiceProgressTransitionsAction, or
 * UpdateServiceTrackerStatusAction. This just lets seeders/tests skip
 * straight to a stage instead of replaying the whole lifecycle.
 */
public function withServiceStatus(ServiceTrackerStatus $status): static
{
    return $this->state(fn () => [
        'status' => BookingStatus::Confirmed,
        'payment_plan' => PaymentPlan::Full,
        'payment_status' => BookingPaymentStatus::FullyPaid,
        'service_status' => $status,
        'service_status_updated_at' => now(),
    ]);
}



}