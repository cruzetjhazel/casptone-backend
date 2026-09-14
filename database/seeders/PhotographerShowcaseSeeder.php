<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\PhotographerApplicationStatus;
use App\Enums\PhotographerType;
use App\Enums\PackageStatus;
use App\Enums\PortfolioImageStatus;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds 6 specific showcase photographers with complete profiles, images,
 * portfolio, packages, and payment config — separate from DemoSeeder's
 * random photographers.
 *
 * Profile/cover images: user-supplied only, from
 * database/seeders/images/{slug}-{kind}.{ext} (jpg/jpeg/png/webp). If not
 * found, the field is left null with a warning — never auto-generated.
 *
 * Portfolio images: always generated via GD (solid-color placeholders),
 * since these exist purely to satisfy the portfolio-count requirement in
 * ProfileCompletenessService, not to be real photos.
 *
 * Usage:
 *   php artisan db:seed --class=PhotographerShowcaseSeeder
 *
 * Run after DemoSeeder to update the showcase photographers it creates,
 * or run alone to create fresh photographers with showcase profiles.
 */
class PhotographerShowcaseSeeder extends Seeder
{
    private const AVATAR_COLORS = [
        '#6D28D9', '#DB2777', '#2563EB', '#059669', '#D97706',
        '#DC2626', '#0891B2', '#7C3AED', '#EA580C', '#4F46E5',
    ];

    /**
     * @var array<int, array{
     *     slug: string, name: string, type: PhotographerType, location: string,
     *     bio: string, style: array<int, string>, email: string, phone: string,
     *     years_active: int, team_size: int|null, services: array<int, string>,
     *     shooting_types: array<int, string>, price_min: float, price_max: float,
     *     coverage_area: string, facebook: string|null, instagram: string|null,
     *     website: string|null,
     * }>
     */
    private const PROVIDERS = [
        [
            'slug' => 'hh-production',
            'name' => 'HHProduction',
            'type' => PhotographerType::Studio,
            'location' => 'Bulan, Sorsogon',
            'bio' => 'HHProduction is a full-service event photography and videography studio based in Bulan, Sorsogon. Our team specializes in weddings, debuts, and corporate events, blending cinematic storytelling with candid, unscripted moments.',
            'style' => ['Cinematic', 'Documentary'],
            'email' => 'contact@hhproduction.test',
            'phone' => '09171234501',
            'years_active' => 7,
            'team_size' => 5,
            'services' => ['Wedding', 'Corporate', 'Videography'],
            'shooting_types' => ['indoor', 'outdoor'],
            'price_min' => 12000,
            'price_max' => 45000,
            'coverage_area' => 'sorsogon_wide',
            'facebook' => 'https://facebook.com/hhproduction.studio',
            'instagram' => 'https://instagram.com/hhproduction.studio',
            'website' => null,
            'gcash_account_name' => 'Harold H. Hernandez',
            'gcash_account_number' => '09171234511',
            'packages' => [
                ['name' => 'Wedding Cinematic Package', 'description' => 'Full-day wedding coverage with a cinematic same-day-edit video.', 'included_items' => ['10 hours coverage', 'Cinematic same-day-edit video', '500 edited photos', 'Online gallery'], 'price' => 35000, 'duration_minutes' => 600, 'buffer_minutes' => 60],
                ['name' => 'Corporate Event Package', 'description' => 'Half-day coverage for corporate events and conferences.', 'included_items' => ['4 hours coverage', '200 edited photos', 'Same-day highlights reel'], 'price' => 15000, 'duration_minutes' => 240, 'buffer_minutes' => 30],
            ],
        ],
        [
            'slug' => 'kap-studio',
            'name' => 'KAP Studio',
            'type' => PhotographerType::Studio,
            'location' => 'Bulan, Sorsogon',
            'bio' => 'KAP Studio is a boutique photography studio known for clean, fine-art portraiture and elegant wedding coverage. We work closely with every couple and family to design a shoot that feels personal, not templated.',
            'style' => ['Fine Art', 'Traditional'],
            'email' => 'hello@kapstudio.test',
            'phone' => '09171234502',
            'years_active' => 5,
            'team_size' => 3,
            'services' => ['Wedding', 'Portrait', 'Debut'],
            'shooting_types' => ['indoor', 'outdoor'],
            'price_min' => 8000,
            'price_max' => 30000,
            'coverage_area' => 'bulan_only',
            'facebook' => 'https://facebook.com/kapstudio.ph',
            'instagram' => 'https://instagram.com/kapstudio.ph',
            'website' => null,
            'gcash_account_name' => 'Katrina A. Pascual',
            'gcash_account_number' => '09171234512',
            'packages' => [
                ['name' => 'Elegant Wedding Package', 'description' => 'Full-day fine-art wedding photography coverage.', 'included_items' => ['8 hours coverage', '350 edited photos', 'Printed photo album', 'Online gallery'], 'price' => 25000, 'duration_minutes' => 480, 'buffer_minutes' => 45],
                ['name' => 'Portrait Session', 'description' => 'Studio or outdoor portrait session for individuals or couples.', 'included_items' => ['1.5 hours session', '30 edited photos', 'Online gallery'], 'price' => 8000, 'duration_minutes' => 90, 'buffer_minutes' => 15],
            ],
        ],
        [
            'slug' => 'amaras-studio',
            'name' => 'Amaras Studio',
            'type' => PhotographerType::Studio,
            'location' => 'Bulan, Sorsogon',
            'bio' => 'Amaras Studio brings a warm, romantic aesthetic to weddings, prenups, and family portraits. Our small team is hands-on from initial consultation through final gallery delivery, so every shoot gets full creative attention.',
            'style' => ['Traditional', 'Candid'],
            'email' => 'bookings@amarasstudio.test',
            'phone' => '09171234503',
            'years_active' => 4,
            'team_size' => 4,
            'services' => ['Wedding', 'Portrait', 'Family'],
            'shooting_types' => ['indoor', 'outdoor'],
            'price_min' => 9000,
            'price_max' => 32000,
            'coverage_area' => 'sorsogon_wide',
            'facebook' => 'https://facebook.com/amarasstudio',
            'instagram' => null,
            'website' => 'https://amarasstudio.test',
            'gcash_account_name' => 'Amara S. Villanueva',
            'gcash_account_number' => '09171234513',
            'packages' => [
                ['name' => 'Romantic Wedding Package', 'description' => 'Full-day warm, romantic-style wedding coverage.', 'included_items' => ['8 hours coverage', '300 edited photos', 'Online gallery'], 'price' => 28000, 'duration_minutes' => 480, 'buffer_minutes' => 45],
                ['name' => 'Family Portrait Session', 'description' => 'Outdoor or in-studio family portrait session.', 'included_items' => ['2 hours session', '40 edited photos', 'Online gallery'], 'price' => 9000, 'duration_minutes' => 120, 'buffer_minutes' => 15],
            ],
        ],
        [
            'slug' => 'cj-creatives',
            'name' => 'CJ Creatives',
            'type' => PhotographerType::Freelancer,
            'location' => 'Bulan, Sorsogon',
            'bio' => 'CJ Creatives is a freelance photographer focused on candid, story-driven coverage for birthdays, debuts, and small intimate events. Bringing an easygoing, unobtrusive shooting style so subjects stay relaxed and natural.',
            'style' => ['Candid', 'Documentary'],
            'email' => 'cj.creatives@example.test',
            'phone' => '09171234504',
            'years_active' => 3,
            'team_size' => null,
            'services' => ['Birthday', 'Debut', 'Portrait'],
            'shooting_types' => ['indoor', 'outdoor'],
            'price_min' => 3000,
            'price_max' => 12000,
            'coverage_area' => 'bulan_only',
            'facebook' => 'https://facebook.com/cjcreatives.ph',
            'instagram' => 'https://instagram.com/cj.creatives',
            'website' => null,
            'gcash_account_name' => 'Carlo J. Reyes',
            'gcash_account_number' => '09171234514',
            'packages' => [
                ['name' => 'Birthday Coverage', 'description' => 'Candid coverage for birthday celebrations.', 'included_items' => ['3 hours coverage', '100 edited photos', 'Online gallery'], 'price' => 5000, 'duration_minutes' => 180, 'buffer_minutes' => 30],
                ['name' => 'Debut Package', 'description' => 'Full debut event coverage, candid and story-driven.', 'included_items' => ['5 hours coverage', '200 edited photos', 'Online gallery'], 'price' => 12000, 'duration_minutes' => 300, 'buffer_minutes' => 30],
            ],
        ],
        [
            'slug' => 'joesol-photography',
            'name' => 'Joesol Photography',
            'type' => PhotographerType::Freelancer,
            'location' => 'Bulan, Sorsogon',
            'bio' => 'Joesol Photography specializes in graduation, christening, and corporate event coverage, with a focus on clean, well-lit, dependable delivery — the go-to choice for clients who want their event documented without drama.',
            'style' => ['Traditional', 'Documentary'],
            'email' => 'joesol.photography@example.test',
            'phone' => '09171234505',
            'years_active' => 6,
            'team_size' => null,
            'services' => ['Graduation', 'Christening', 'Corporate'],
            'shooting_types' => ['indoor', 'outdoor'],
            'price_min' => 3500,
            'price_max' => 15000,
            'coverage_area' => 'sorsogon_wide',
            'facebook' => 'https://facebook.com/joesolphotography',
            'instagram' => null,
            'website' => null,
            'gcash_account_name' => 'Joseph Soliman',
            'gcash_account_number' => '09171234515',
            'packages' => [
                ['name' => 'Graduation Shoot', 'description' => 'Clean, well-lit graduation photo session.', 'included_items' => ['2 hours session', '50 edited photos', 'Online gallery'], 'price' => 3500, 'duration_minutes' => 120, 'buffer_minutes' => 15],
                ['name' => 'Corporate Event Coverage', 'description' => 'Dependable full coverage for corporate events.', 'included_items' => ['5 hours coverage', '150 edited photos', 'Online gallery'], 'price' => 15000, 'duration_minutes' => 300, 'buffer_minutes' => 30],
            ],
        ],
        [
            'slug' => 'frederick-robelas',
            'name' => 'Frederick Robelas',
            'type' => PhotographerType::Freelancer,
            'location' => 'Bulan, Sorsogon',
            'bio' => 'Frederick Robelas is a freelance photographer with a passion for outdoor prenup and portrait sessions, combining natural light with fine-art composition to create timeless, editorial-style images.',
            'style' => ['Fine Art', 'Cinematic'],
            'email' => 'frederick.robelas@example.test',
            'phone' => '09171234506',
            'years_active' => 2,
            'team_size' => null,
            'services' => ['Prenup', 'Portrait'],
            'shooting_types' => ['outdoor'],
            'price_min' => 4000,
            'price_max' => 14000,
            'coverage_area' => 'bulan_only',
            'facebook' => 'https://facebook.com/fred.robelas.photo',
            'instagram' => 'https://instagram.com/fred.robelas',
            'website' => null,
            'gcash_account_name' => 'Frederick Robelas',
            'gcash_account_number' => '09171234516',
            'packages' => [
                ['name' => 'Prenup Session', 'description' => 'Outdoor prenup session with natural light, fine-art composition.', 'included_items' => ['3 hours session', '60 edited photos', 'Online gallery'], 'price' => 8000, 'duration_minutes' => 180, 'buffer_minutes' => 20],
                ['name' => 'Portrait Session', 'description' => 'Outdoor editorial-style portrait session.', 'included_items' => ['1.5 hours session', '25 edited photos', 'Online gallery'], 'price' => 4000, 'duration_minutes' => 90, 'buffer_minutes' => 15],
            ],
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('PhotographerShowcaseSeeder only runs in local/testing.');
            return;
        }

        $reviewer = User::where('account_type', AccountType::Administrator)->first();

        if (! extension_loaded('gd')) {
            $this->command->warn('GD extension not available — portfolio images will be skipped (profile/cover images are unaffected).');
        }

        $this->command->info('Seeding showcase photographers...');

        foreach (self::PROVIDERS as $i => $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone_number' => $data['phone'],
                    'email_verified_at' => now(),
                    'password' => bcrypt('password'),
                    'account_type' => AccountType::Photographer,
                ]
            );

            PhotographerApplication::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'photographer_type' => $data['type'],
                    'status' => PhotographerApplicationStatus::Approved,
                    'business_name' => $data['name'],
                    'location' => $data['location'],
                    'years_active' => $data['years_active'],
                    'team_size' => $data['team_size'],
                    'services' => $data['services'],
                    'coverage_area' => $data['coverage_area'],
                    'shooting_types' => $data['shooting_types'],
                    'price_min' => $data['price_min'],
                    'price_max' => $data['price_max'],
                    'submitted_at' => now(),
                    'reviewed_at' => now(),
                    'reviewed_by' => $reviewer?->id,
                ]
            );

            // Seed profile and cover images (load from seeders/images/ or generate)
            $profilePhotoPath = $this->seedImage($data['slug'], 'logo', 'profile', $i);
            $coverPhotoPath = $this->seedImage($data['slug'], 'cover', 'cover', $i);

            PhotographerProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'bio' => $data['bio'],
                    'style' => $data['style'],
                    'profile_photo_path' => $profilePhotoPath,
                    'cover_photo_path' => $coverPhotoPath,
                    'facebook' => $data['facebook'],
                    'instagram' => $data['instagram'],
                    'website' => $data['website'],
                ]
            );

            // Seed portfolio images
            $this->seedPortfolioImages($user, $data['slug'], $i);

            // Seed published packages (required for hasActivePackage() / fully_bookable)
            $this->seedPackages($user, $data);

            // Seed GCash payment config (required for gcash_configured / fully_bookable)
            PhotographerPaymentConfig::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'gcash_account_name' => $data['gcash_account_name'],
                    'gcash_account_number' => $data['gcash_account_number'],
                    // Not rendered or existence-checked anywhere in the app (see
                    // PhotographerPaymentConfigFactory's own 'fake-qr.jpg' for the
                    // same existing convention) — no real file is generated here.
                    'gcash_qr_path' => "gcash-qr/seed/{$data['slug']}.jpg",
                ]
            );

            $this->command->line("✓ {$data['name']} ({$data['type']->value})");
        }

        $this->command->info('Showcase photographers seeded successfully!');
    }

    /**
     * Loads a real image from database/seeders/images/{slug}-{kind}.{ext} and
     * copies it to the public disk. Profile/cover photos are user-supplied —
     * unlike portfolio images, these are deliberately NOT auto-generated. If
     * the source file isn't there yet, the field is left null with a warning
     * so the seeder can be safely re-run later once the file is in place.
     */
    private function seedImage(string $slug, string $kind, string $storedAs, int $index): ?string
    {
        $sourceDir = database_path('seeders/images');
        $destDir = "photographers/showcase/{$slug}";

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $sourceFile = "{$sourceDir}/{$slug}-{$kind}.{$extension}";

            if (is_file($sourceFile)) {
                $destPath = "{$destDir}/{$storedAs}.{$extension}";
                Storage::disk('public')->put($destPath, file_get_contents($sourceFile));
                return $destPath;
            }
        }

        $this->command->warn(
            "No {$kind} image found for '{$slug}' — expected database/seeders/images/{$slug}-{$kind}.{jpg,jpeg,png,webp}. ".
            'Leaving that field null; re-run this seeder once the file is in place.'
        );

        return null;
    }

    /**
     * Seeds 6-8 active portfolio images + 1-2 archived for each photographer.
     */
    private function seedPortfolioImages(User $photographer, string $slug, int $index): void
    {
        if (! extension_loaded('gd')) {
            $this->command->warn("GD extension not available — skipping portfolio images for \"{$photographer->name}\". Enable php-gd to seed portfolio images.");
            return;
        }

        $destDir = "photographers/showcase/{$slug}";

        // Delete existing portfolio images to avoid duplicates
        PhotographerPortfolioImage::where('user_id', $photographer->id)->delete();

        // Generate 6-8 active portfolio images
        $imageCount = fake()->numberBetween(6, 8);

        for ($i = 1; $i <= $imageCount; $i++) {
            $colorIndex = ($photographer->id + $i) % count(self::AVATAR_COLORS);
            $color = self::AVATAR_COLORS[$colorIndex];
            $path = "{$destDir}/portfolio-{$i}.jpg";

            if (!Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, $this->generatePlaceholderImage("Photo {$i}", 800, 600, $color));
            }

            PhotographerPortfolioImage::create([
                'user_id' => $photographer->id,
                'path' => $path,
                'status' => PortfolioImageStatus::Active,
                'sort_order' => $i,
            ]);
        }

        // Add 1-2 archived images
        for ($i = 0; $i < fake()->numberBetween(1, 2); $i++) {
            $path = "{$destDir}/archived-{$i}.jpg";

            if (!Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, $this->generatePlaceholderImage('Archived', 800, 600, '#9CA3AF'));
            }

            PhotographerPortfolioImage::create([
                'user_id' => $photographer->id,
                'path' => $path,
                'status' => PortfolioImageStatus::Archived,
                'sort_order' => $imageCount + $i,
            ]);
        }
    }

    /**
     * Seeds 1-2 published Packages per provider from their PROVIDERS entry.
     * Keyed on (user_id, name) via updateOrCreate so re-running this seeder
     * without migrate:fresh doesn't pile up duplicate packages.
     */
    private function seedPackages(User $photographer, array $data): void
    {
        foreach ($data['packages'] as $pkg) {
            Package::updateOrCreate(
                ['user_id' => $photographer->id, 'name' => $pkg['name']],
                [
                    'description' => $pkg['description'],
                    'included_items' => $pkg['included_items'],
                    'price' => $pkg['price'],
                    'duration_minutes' => $pkg['duration_minutes'],
                    'buffer_minutes' => $pkg['buffer_minutes'],
                    'status' => PackageStatus::Published,
                ]
            );
        }
    }

    /**
     * Generates a solid-color JPEG placeholder with centered text using GD.
     */
    private function generatePlaceholderImage(string $label, int $width, int $height, string $hexColor): string
    {
        $image = imagecreatetruecolor($width, $height);

        [$r, $g, $b] = sscanf($hexColor, '#%02x%02x%02x');
        $background = imagecolorallocate($image, (int) $r, (int) $g, (int) $b);
        imagefill($image, 0, 0, $background);

        $white = imagecolorallocate($image, 255, 255, 255);
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($label);
        $textHeight = imagefontheight($font);
        $x = max((int) (($width - $textWidth) / 2), 4);
        $y = max((int) (($height - $textHeight) / 2), 4);
        imagestring($image, $font, $x, $y, $label, $white);

        ob_start();
        imagejpeg($image, quality: 85);
        $contents = ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}