<?php

namespace Database\Seeders;

use App\Models\LocationBarangay;
use App\Models\LocationCityMunicipality;
use App\Models\LocationProvince;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Gives seeded bookings a real PSGC location (province / city-municipality /
 * barangay) that fits the photographer's coverage area:
 *
 *   bulan_only              -> Bulan
 *   bulan_nearby            -> Bulan + the municipalities around it
 *   anywhere_sorsogon       -> any municipality in Sorsogon
 *   travel_outside_sorsogon -> anywhere in Bicol (Region V)
 *   travel_outside_bicol    -> anywhere in Bicol (Region V)
 *
 * Studio bookings always use Bulan (where the seeded studios are based).
 * Run after BicolPsgcSeeder and after the booking seeders. Safe to re-run:
 * it only touches bookings that have no barangay yet.
 */
class BookingLocationSeeder extends Seeder
{
    /** Municipalities bordering Bulan. Edit this list if you define "nearby" differently. */
    private const BULAN_NEARBY = ['Bulan', 'Magallanes', 'Juban', 'Irosin', 'Matnog'];

    /** Used when a photographer has no coverage_area set. */
    private const DEFAULT_COVERAGE = 'bulan_nearby';

    public function run(): void
    {
        $sorsogon = LocationProvince::where('name', 'Sorsogon')->first();

        if (! $sorsogon) {
            $this->command->error('Sorsogon not found. Run BicolPsgcSeeder first.');
            return;
        }

        $allCities = LocationCityMunicipality::all();
        $sorsogonCities = $allCities->where('province_id', $sorsogon->id);
        $bulan = $sorsogonCities->firstWhere('name', 'Bulan');

        if (! $bulan) {
            $this->command->error('Bulan not found in the location tables.');
            return;
        }

        $pools = [
            'bulan_only' => collect([$bulan]),
            'bulan_nearby' => $sorsogonCities->whereIn('name', self::BULAN_NEARBY)->values(),
            'anywhere_sorsogon' => $sorsogonCities->values(),
            'travel_outside_sorsogon' => $allCities->values(),
            'travel_outside_bicol' => $allCities->values(),
        ];

        $barangaysByCity = LocationBarangay::all()->groupBy('city_municipality_id');
        $provinceNames = LocationProvince::pluck('name', 'id');
        $coverageByUser = DB::table('photographer_applications')->pluck('coverage_area', 'user_id');

        $updated = 0;

        DB::table('bookings')
            ->whereNull('barangay_id')
            ->chunkById(200, function ($rows) use (
                $bulan, $pools, $barangaysByCity, $provinceNames, $coverageByUser, &$updated
            ) {
                foreach ($rows as $b) {
                    $coverage = $coverageByUser[$b->photographer_id] ?? self::DEFAULT_COVERAGE;
                    $pool = $b->location_type === 'studio'
                        ? collect([$bulan])
                        : ($pools[$coverage] ?? $pools[self::DEFAULT_COVERAGE]);

                    $city = $pool->random();
                    $barangay = ($barangaysByCity[$city->id] ?? collect())->random();

                    DB::table('bookings')->where('id', $b->id)->update([
                        'province_id' => $city->province_id,
                        'city_municipality_id' => $city->id,
                        'barangay_id' => $barangay->id,
                        'event_address' => sprintf(
                            'Purok %d, Brgy. %s, %s, %s',
                            random_int(1, 7),
                            $barangay->name,
                            $city->name,
                            $provinceNames[$city->province_id]
                        ),
                    ]);

                    $updated++;
                }
            });

        $this->command->info("Booking locations seeded for {$updated} bookings.");
    }
}