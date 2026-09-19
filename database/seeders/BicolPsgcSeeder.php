<?php

namespace Database\Seeders;

use App\Models\LocationBarangay;
use App\Models\LocationCityMunicipality;
use App\Models\LocationProvince;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BicolPsgcSeeder extends Seeder
{
    public function run(): void
    {
        $path = __DIR__ . '/data/bicol_psgc_data.json';

        if (! file_exists($path)) {
            $this->command->error("PSGC data file not found at {$path}");
            return;
        }

        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            // Provinces — idempotent via psgc_code
            foreach ($data['provinces'] as $p) {
                LocationProvince::updateOrCreate(
                    ['psgc_code' => $p['psgc_code']],
                    ['name' => $p['name'], 'region_code' => $p['region_code']]
                );
            }

            // Map province psgc_code -> local id
            $provinceIdByCode = LocationProvince::pluck('id', 'psgc_code');

            foreach ($data['cities_municipalities'] as $c) {
                LocationCityMunicipality::updateOrCreate(
                    ['psgc_code' => $c['psgc_code']],
                    [
                        'province_id' => $provinceIdByCode[$c['province_code']],
                        'name' => $c['name'],
                        'type' => $c['type'],
                    ]
                );
            }

            $cmIdByCode = LocationCityMunicipality::pluck('id', 'psgc_code');

            // Barangays — chunk the insert/update for memory & speed on 3,471 rows
            foreach (array_chunk($data['barangays'], 500) as $chunk) {
                foreach ($chunk as $b) {
                    LocationBarangay::updateOrCreate(
                        ['psgc_code' => $b['psgc_code']],
                        [
                            'city_municipality_id' => $cmIdByCode[$b['city_municipality_code']],
                            'name' => $b['name'],
                        ]
                    );
                }
            }
        });

        // Count guard — matches PSA's published Bicol (Region V) totals
        $counts = [
            'provinces' => LocationProvince::count(),
            'cities_municipalities' => LocationCityMunicipality::count(),
            'barangays' => LocationBarangay::count(),
        ];

        $expected = ['provinces' => 6, 'cities_municipalities' => 114, 'barangays' => 3471];

        foreach ($expected as $key => $count) {
            if ($counts[$key] !== $count) {
                $this->command->warn("Expected {$count} {$key}, found {$counts[$key]}.");
            }
        }

        $this->command->info('Bicol PSGC location data seeded: ' . json_encode($counts));
    }
}