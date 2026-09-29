<?php

namespace Database\Seeders;

use App\Models\ClientProfile;
use App\Models\FavoritePhotographer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DEMO/TESTING ONLY. Creates the fixed test client (client@example.test) and
 * fills their account with variety:
 *
 *  - Bookings: for every distinct status / payment status / service tracker
 *    combination that DemoSeeder produced, up to 2 bookings from DIFFERENT
 *    photographers are handed over to this client (payments and reviews on
 *    those bookings move with them, so the data stays consistent).
 *  - Favorites: 6 photographers.
 *
 * BookingStatesShowcaseSeeder ALSO uses this client for its ~45 edge-case
 * states (refunds, disputes, reschedules, schedules, extensions).
 *
 * Safe to re-run. Run after DemoSeeder (DatabaseSeeder already does).
 */
class TestClientSeeder extends Seeder
{
    private const PER_COMBINATION = 1;

    private const MAX_ADOPTED = 12;

    public function run(): void
    {
        $email = env('TEST_CLIENT_EMAIL', 'client@example.test');
        $password = env('TEST_CLIENT_PASSWORD', 'password');

        $client = User::firstOrCreate(
            ['email' => $email],
            User::factory()->raw([
                'email' => $email,
                'name' => 'Test Client',
                'password' => bcrypt($password),
            ])
        );

        ClientProfile::firstOrCreate(
            ['user_id' => $client->id],
            ClientProfile::factory()->raw()
        );

        $adopted = $this->adoptVariedBookings($client);
        $favorites = $this->addFavorites($client);

        $this->command->info("Seeded test client: {$email} / {$password} ({$adopted} bookings from other photographers, {$favorites} favorites)");
    }

    private function adoptVariedBookings(User $client): int
    {
        // One row per (status, payment_status, service_status) combination
        // that exists in the data, skipping the STATE: edge-case bookings.
        $rows = DB::table('bookings')
            ->where('client_id', '!=', $client->id)
            ->where(fn ($q) => $q->whereNull('special_requests')->orWhere('special_requests', 'not like', 'STATE:%'))
            ->orderBy('id')
            ->get(['id', 'photographer_id', 'status', 'payment_status', 'service_status']);

        $groups = $rows->groupBy(fn ($r) => $r->status.'|'.$r->payment_status.'|'.($r->service_status ?? '-'));

        $ids = [];
        foreach ($groups as $group) {
            // Different photographers per combination, so the client's list is not all one studio.
            $ids = array_merge($ids, $group->unique('photographer_id')->take(self::PER_COMBINATION)->pluck('id')->all());
        }

        $ids = array_slice($ids, 0, self::MAX_ADOPTED);

        if ($ids === []) {
            $this->command->warn('No bookings to hand over. Run DemoSeeder first, then re-run TestClientSeeder.');

            return 0;
        }

        DB::transaction(function () use ($ids, $client) {
            DB::table('bookings')->whereIn('id', $ids)->update(['client_id' => $client->id]);
            DB::table('payments')->whereIn('booking_id', $ids)->update(['client_id' => $client->id]);
            DB::table('reviews')->whereIn('booking_id', $ids)->update(['client_id' => $client->id]);
        });

        return count($ids);
    }

    private function addFavorites(User $client): int
    {
        $photographerIds = DB::table('users')
            ->where('account_type', \App\Enums\AccountType::Photographer->value)
            ->orderBy('id')
            ->limit(6)
            ->pluck('id');

        foreach ($photographerIds as $id) {
            FavoritePhotographer::firstOrCreate(['client_id' => $client->id, 'photographer_id' => $id]);
        }

        return $photographerIds->count();
    }
}