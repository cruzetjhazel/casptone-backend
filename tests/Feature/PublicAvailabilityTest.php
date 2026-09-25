<?php

namespace Tests\Feature;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\AvailabilityWindow;
use App\Models\BlockedDate;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    protected function publishedPackageFor(User $user, array $attributes = []): Package
    {
        return Package::factory()->for($user)->published()->create($attributes);
    }

    protected function createAvailabilityWindow(User $user, string $date, string $startTime = '09:00', string $endTime = '17:00'): void
    {
        AvailabilityWindow::factory()->for($user)->create([
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    protected function createBooking(
        User $photographer,
        string $date,
        string $startTime,
        string $endTime,
        BookingStatus $status,
        ?BookingPaymentStatus $paymentStatus = null
    ): Booking {
        return Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'event_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => $status,
            'payment_status' => $paymentStatus ?? BookingPaymentStatus::Pending,
            'hold_expires_at' => $status === BookingStatus::Pending ? now()->addHours(24) : null,
        ]);
    }

    protected function assertSlotListFor(User $user, Package $package, string $date): array
    {
        $response = $this->getJson("/api/photographers/{$user->id}/availability/slots?date={$date}&package_id={$package->id}");

        $response->assertOk();

        return $response->json('data.start_times');
    }

    public function test_public_slot_generation_respects_duration_and_buffer(): void
    {
        $user = $this->approvedPhotographer();
        $user->forceFill(['slot_interval_minutes' => 30])->save();
        $package = $this->publishedPackageFor($user, [
            'duration_minutes' => 120,
            'buffer_minutes' => 30,
        ]);
        $date = now()->addDays(7)->format('Y-m-d');
        // Same reasoning as the month-calendar test: without at least one
        // BookingHour row, AvailabilityService's "fully open by default"
        // fallback makes the whole day bookable, not just the window below.
        \App\Models\BookingHour::create([
            'user_id' => $user->id,
            'day_of_week' => (\Carbon\Carbon::parse($date)->dayOfWeek + 4) % 7,
            'start_time' => '00:00',
            'end_time' => '23:59',
        ]);
        $this->createAvailabilityWindow($user, $date, '09:00', '13:00');

        $slots = $this->assertSlotListFor($user, $package, $date);

        // 09:00-13:00 window, 150 min needed (120+30) -> last valid start is 10:30 (10:30+150=13:00)
        $this->assertContains('09:00', $slots);
        $this->assertContains('10:30', $slots);
        $this->assertNotContains('11:00', $slots);
    }

    public function test_blocked_period_removes_overlapping_slots(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, [
            'duration_minutes' => 60, 'buffer_minutes' => 0,
        ]);
        $date = now()->addDays(7)->format('Y-m-d');
        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        BlockedDate::factory()->for($user)->create(['date' => $date, 'start_time' => '10:00', 'end_time' => '11:00']);

        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertNotContains('10:00', $slots);
        $this->assertContains('09:00', $slots);
        $this->assertContains('11:00', $slots);
    }

    // Business rule: a Pending request does NOT block the public calendar —
    // multiple clients must be able to request the same date/time before
    // anyone has paid. See AvailabilityService's class docblock.
    public function test_overlapping_pending_booking_does_not_block_availability(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $date = now()->addDays(7)->format('Y-m-d');

        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        $this->createBooking($user, $date, '10:00', '11:00', BookingStatus::Pending);

        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertContains('10:00', $slots);
    }

    // A Confirmed (accepted) booking whose payment hasn't been made yet
    // still must NOT block the calendar — only a confirmed payment does.
    public function test_overlapping_accepted_but_unpaid_booking_does_not_block_availability(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $date = now()->addDays(7)->format('Y-m-d');

        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        $this->createBooking($user, $date, '10:00', '11:00', BookingStatus::Confirmed, BookingPaymentStatus::Pending);

        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertContains('10:00', $slots);
    }

    public function test_overlapping_confirmed_and_partially_paid_booking_blocks_availability(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $date = now()->addDays(7)->format('Y-m-d');

        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        $this->createBooking($user, $date, '10:00', '11:00', BookingStatus::Confirmed, BookingPaymentStatus::PartiallyPaid);

        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertNotContains('10:00', $slots);
    }

    public function test_overlapping_confirmed_and_fully_paid_booking_blocks_availability(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $date = now()->addDays(7)->format('Y-m-d');

        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        $this->createBooking($user, $date, '10:00', '11:00', BookingStatus::Confirmed, BookingPaymentStatus::FullyPaid);

        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertNotContains('10:00', $slots);
    }

    public function test_overlapping_rejected_booking_does_not_block_availability(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $date = now()->addDays(7)->format('Y-m-d');

        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        $this->createBooking($user, $date, '10:00', '11:00', BookingStatus::Cancelled);
        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertContains('10:00', $slots);
    }

    public function test_overlapping_cancelled_booking_does_not_block_availability(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $date = now()->addDays(7)->format('Y-m-d');

        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        $this->createBooking($user, $date, '10:00', '11:00', BookingStatus::Cancelled);

        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertContains('10:00', $slots);
    }

    public function test_overlapping_completed_booking_does_not_block_availability(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);
        $date = now()->addDays(7)->format('Y-m-d');

        $this->createAvailabilityWindow($user, $date, '09:00', '12:00');
        $this->createBooking($user, $date, '10:00', '11:00', BookingStatus::Completed);

        $slots = $this->assertSlotListFor($user, $package, $date);

        $this->assertContains('10:00', $slots);
    }

    public function test_month_calendar_distinguishes_available_partial_and_unavailable(): void
    {
        $user = $this->approvedPhotographer();
        $package = $this->publishedPackageFor($user, ['duration_minutes' => 60, 'buffer_minutes' => 0]);

        // Anchor all three dates to the start of next month so they can
        // never straddle a month boundary, regardless of today's date.
        $availableDate = now()->addMonthNoOverflow()->startOfMonth()->addDays(2);
        $partialDate = $availableDate->copy()->addDay();
        $unavailableDate = $availableDate->copy()->addDays(2);

        // Give this photographer usual hours on an unrelated weekday only,
        // so "no explicit availability for this date" actually means
        // closed for the three test dates below (see AvailabilityService's
        // "fully open by default" fallback, which only applies when NO
        // BookingHour rows exist at all).
        \App\Models\BookingHour::create([
            'user_id' => $user->id,
            'day_of_week' => ($availableDate->dayOfWeek + 4) % 7,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $this->createAvailabilityWindow($user, $availableDate->format('Y-m-d'));
        $this->createAvailabilityWindow($user, $partialDate->format('Y-m-d'));
        BlockedDate::factory()->for($user)->create(['date' => $partialDate->format('Y-m-d'), 'start_time' => '09:00', 'end_time' => '10:00']);
        $this->createBooking($user, $partialDate->format('Y-m-d'), '10:00', '11:00', BookingStatus::Confirmed);
        // unavailableDate has no window at all

        $response = $this->getJson("/api/photographers/{$user->id}/availability/calendar?month={$availableDate->format('Y-m')}&package_id={$package->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('available', $data[$availableDate->format('Y-m-d')]);
        $this->assertSame('partial', $data[$partialDate->format('Y-m-d')]);
        $this->assertSame('unavailable', $data[$unavailableDate->format('Y-m-d')]);
    }

    public function test_public_availability_endpoints_404_for_unapproved_photographer(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->pendingReview()->create();
        $package = Package::factory()->for($user)->published()->create();

        $this->getJson("/api/photographers/{$user->id}/availability/slots?date=".now()->addDay()->format('Y-m-d')."&package_id={$package->id}")
            ->assertStatus(404);
    }

    public function test_package_from_another_photographer_is_rejected(): void
    {
        $user = $this->approvedPhotographer();
        $otherPhotographer = $this->approvedPhotographer();
        $foreignPackage = Package::factory()->for($otherPhotographer)->published()->create();

        $this->getJson("/api/photographers/{$user->id}/availability/slots?date=".now()->addDay()->format('Y-m-d')."&package_id={$foreignPackage->id}")
            ->assertStatus(404);
    }
}