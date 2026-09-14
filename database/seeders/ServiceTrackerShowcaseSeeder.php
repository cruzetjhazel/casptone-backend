<?php

namespace Database\Seeders;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PackageStatus;
use App\Enums\PaymentPlan;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\ClientProfile;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEMO/TESTING ONLY. Produces one dedicated photographer + client with a
 * known, fixed login, and exactly 5 bookings — one per Service Tracker
 * stage (Upcoming, Event Day, Editing, Delivered, Completed) — so every
 * stage can be pulled up on demand during a live panel demo without
 * waiting on real time-based transitions or replaying the whole booking
 * lifecycle by hand.
 *
 * This does NOT add any new production capability: every booking is
 * created directly at its target state via BookingFactory::withServiceStatus(),
 * bypassing (not replacing) CreateBookingAction, AcceptBookingAction,
 * UpdateServiceTrackerStatusAction, RunServiceProgressTransitionsAction,
 * and MarkServiceCompletedAction. Those remain the only ways a real
 * booking's service_status changes in production.
 *
 * Same environment guard as DemoSeeder — never runs outside local/testing.
 */
class ServiceTrackerShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('ServiceTrackerShowcaseSeeder only runs in local/testing.');

            return;
        }

        $photographerEmail = env('SHOWCASE_PHOTOGRAPHER_EMAIL', 'showcase.photographer@example.test');
        $photographerPassword = env('SHOWCASE_PHOTOGRAPHER_PASSWORD', 'password');
        $clientEmail = env('SHOWCASE_CLIENT_EMAIL', 'showcase.client@example.test');
        $clientPassword = env('SHOWCASE_CLIENT_PASSWORD', 'password');

        $photographer = User::firstOrCreate(
            ['email' => $photographerEmail],
            User::factory()->photographer()->raw([
                'email' => $photographerEmail,
                'name' => 'Showcase Studio',
                'password' => bcrypt($photographerPassword),
            ])
        );

        PhotographerApplication::firstOrCreate(
            ['user_id' => $photographer->id],
            PhotographerApplication::factory()->approved()->raw([
                'user_id' => $photographer->id,
                'business_name' => 'Showcase Studio',
            ])
        );

        PhotographerProfile::firstOrCreate(
            ['user_id' => $photographer->id],
            PhotographerProfile::factory()->complete()->raw(['user_id' => $photographer->id])
        );

        $client = User::firstOrCreate(
            ['email' => $clientEmail],
            User::factory()->raw([
                'email' => $clientEmail,
                'name' => 'Showcase Client',
                'password' => bcrypt($clientPassword),
            ])
        );

        ClientProfile::firstOrCreate(
            ['user_id' => $client->id],
            ClientProfile::factory()->raw(['user_id' => $client->id])
        );

        $package = Package::firstOrCreate(
            ['user_id' => $photographer->id, 'name' => 'Showcase Package'],
            Package::factory()->published()->raw([
                'user_id' => $photographer->id,
                'name' => 'Showcase Package',
            ])
        );

        // Clear out any previous run's showcase bookings so re-seeding is idempotent.
        Booking::where('client_id', $client->id)
            ->where('photographer_id', $photographer->id)
            ->where('special_requests', 'like', 'SHOWCASE:%')
            ->delete();

        $stages = [
            ['label' => 'Upcoming', 'status' => ServiceTrackerStatus::Upcoming, 'days' => 14],
            ['label' => 'Event Day', 'status' => ServiceTrackerStatus::EventDay, 'days' => 0],
            ['label' => 'Editing', 'status' => ServiceTrackerStatus::Editing, 'days' => -3],
            ['label' => 'Delivered', 'status' => ServiceTrackerStatus::Delivered, 'days' => -7],
        ];

        foreach ($stages as $stage) {
            Booking::factory()->withServiceStatus($stage['status'])->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'package_id' => $package->id,
                'package_snapshot' => ['name' => $package->name, 'price' => (float) $package->price],
                'event_type' => 'wedding',
                'event_date' => now()->addDays($stage['days'])->format('Y-m-d'),
                'start_time' => '09:00',
                'end_time' => '13:00',
                'subtotal' => $package->price,
                'total_price' => $package->price,
                'special_requests' => "SHOWCASE: {$stage['label']} stage",
            ]);
        }

        // Fifth booking: BookingStatus already Completed (Delivered + the
        // explicit "Mark Service as Completed" action already applied).
        Booking::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'package_id' => $package->id,
            'package_snapshot' => ['name' => $package->name, 'price' => (float) $package->price],
            'event_type' => 'wedding',
            'event_date' => now()->subDays(20)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '13:00',
            'subtotal' => $package->price,
            'total_price' => $package->price,
            'status' => BookingStatus::Completed,
            'payment_plan' => PaymentPlan::Full,
            'payment_status' => BookingPaymentStatus::FullyPaid,
            'service_status' => ServiceTrackerStatus::Delivered,
            'service_status_updated_at' => now()->subDays(20),
            'special_requests' => 'SHOWCASE: Completed stage',
        ]);

        $this->command->info(sprintf(
            'Service tracker showcase seeded — photographer: %s / %s, client: %s / %s',
            $photographerEmail,
            $photographerPassword,
            $clientEmail,
            $clientPassword,
        ));
    }
}