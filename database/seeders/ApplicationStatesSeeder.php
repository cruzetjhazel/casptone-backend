<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\PhotographerType;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEMO/TESTING ONLY. One photographer application for every status the admin
 * can meet besides "approved" (the other seeders already create those):
 *
 *   draft              - started but never submitted
 *   pending_review     - submitted, waiting for the admin
 *   revision_requested - admin asked for changes
 *   rejected           - rejected, applicant may apply again
 *   rejected (final)   - rejected, applicant may NOT apply again
 *
 * Log in as any of them (password: "password") to see the application status
 * page the photographer gets; log in as admin to review them.
 * Safe to re-run: it resets each applicant's application.
 *
 *   php artisan db:seed --class=ApplicationStatesSeeder
 */
class ApplicationStatesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('ApplicationStatesSeeder only runs in local/testing.');

            return;
        }

        $admin = User::where('account_type', AccountType::Administrator)->first();

        $applicants = [
            [
                'key' => 'draft', 'name' => 'Mia Santos Photography',
                'type' => PhotographerType::Freelancer, 'coverage' => 'bulan_only',
                'status' => [
                    'status' => 'draft',
                    'submitted_at' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'government_id_path' => null,
                    'selfie_with_id_path' => null,
                    'business_permit_path' => null,
                    'additional_document_paths' => null,
                ],
            ],
            [
                'key' => 'pending', 'name' => 'Bulan Bay Studio',
                'type' => PhotographerType::Studio, 'coverage' => 'bulan_nearby',
                'status' => [
                    'status' => 'pending_review',
                    'submitted_at' => now()->subDays(2),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ],
            ],
            [
                'key' => 'revision', 'name' => 'Kuya Jun Photos',
                'type' => PhotographerType::Freelancer, 'coverage' => 'anywhere_sorsogon',
                'status' => [
                    'status' => 'revision_requested',
                    'submitted_at' => now()->subDays(5),
                    'reviewed_by' => $admin?->id,
                    'reviewed_at' => now()->subDays(3),
                    'revision_notes' => 'Your government ID photo is blurry. Please upload a clearer photo and add your business permit.',
                ],
            ],
            [
                'key' => 'rejected', 'name' => 'Sorsogon Lens Studio',
                'type' => PhotographerType::Studio, 'coverage' => 'travel_outside_sorsogon',
                'status' => [
                    'status' => 'rejected',
                    'submitted_at' => now()->subDays(10),
                    'reviewed_by' => $admin?->id,
                    'reviewed_at' => now()->subDays(8),
                    'rejection_reason' => 'The selfie does not match the photo on the government ID. You may fix this and apply again.',
                    'can_reapply' => true,
                ],
            ],
            [
                'key' => 'rejected-final', 'name' => 'Rex Captures',
                'type' => PhotographerType::Freelancer, 'coverage' => 'travel_outside_bicol',
                'status' => [
                    'status' => 'rejected',
                    'submitted_at' => now()->subDays(20),
                    'reviewed_by' => $admin?->id,
                    'reviewed_at' => now()->subDays(18),
                    'rejection_reason' => 'The documents submitted were found to be fake. This decision is final.',
                    'can_reapply' => false,
                ],
            ],
        ];

        foreach ($applicants as $a) {
            $email = "applicant.{$a['key']}@example.test";

            $user = User::firstOrCreate(
                ['email' => $email],
                User::factory()->photographer()->raw([
                    'email' => $email,
                    'name' => $a['name'],
                    'password' => bcrypt('password'),
                ])
            );

            PhotographerApplication::where('user_id', $user->id)->delete();

            $factory = PhotographerApplication::factory();

            if ($a['type'] === PhotographerType::Studio) {
                $factory = $factory->studio();
            }

            $factory->create(array_merge([
                'user_id' => $user->id,
                'photographer_type' => $a['type'],
                'business_name' => $a['name'],
                'location' => 'Bulan, Sorsogon',
                'coverage_area' => $a['coverage'],
                'revision_notes' => null,
                'rejection_reason' => null,
                'can_reapply' => true,
            ], $a['status']));
        }

        $this->command?->info('Seeded 5 photographer applications (draft, pending review, revision requested, rejected, rejected final).');
    }
}