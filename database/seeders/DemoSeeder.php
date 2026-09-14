<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\CustomPackageComponentType;
use App\Enums\PackageStatus;
use App\Enums\PaymentPlan;
use App\Enums\PhotographerApplicationStatus;
use App\Enums\PhotographerType;
use App\Enums\PortfolioImageStatus;
use App\Enums\ReportRequestedAction;
use App\Enums\ReportSeverity;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Enums\ServiceTrackerStatus;
use App\Models\ActivityLog;
use App\Models\AddOn;
use App\Models\AvailabilityWindow;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\ClientProfile;
use App\Models\CustomPackageComponent;
use App\Models\CustomPackageConfig;
use App\Models\FavoritePhotographer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\PhotographerPaymentReference;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;
use App\Models\ProfileView;
use App\Models\Report;
use App\Models\ReportNote;
use App\Models\Review;
use App\Models\ServiceSearchLog;
use App\Models\User;
use App\Models\WalkInClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Populates a full demo dataset: clients, photographers (freelancer + studio,
 * all approved so they appear on Explore), packages/add-ons/custom-package
 * setups, availability, bookings across every status, payments, detailed
 * reviews, reports, activity logs, profile views, and service search logs.
 *
 * All 10 photographers are approved with realistic, varied data including
 * unique bios, styles, services, pricing, packages, and reviews.
 *
 * Only runs in local/testing, same guard as AdminSeeder. Run after
 * AdminSeeder so an administrator exists to attribute admin-side actions to.
 */
class DemoSeeder extends Seeder
{
    private const PHOTOGRAPHER_COUNT = 10;

    private const FREELANCER_COUNT = 6; // remaining PHOTOGRAPHER_COUNT - FREELANCER_COUNT are studios

    private const CLIENT_COUNT = 20;

    // Deterministic palette for generated profile/cover placeholder images,
    // cycled by photographer index so colors stay stable across reseeds.
    private const AVATAR_COLORS = [
        '#6D28D9', '#DB2777', '#2563EB', '#059669', '#D97706',
        '#DC2626', '#0891B2', '#7C3AED', '#EA580C', '#4F46E5',
    ];

    private const SEARCH_TERMS = [
        'wedding photographer', 'prenup shoot', 'debut photographer',
        'birthday photographer', 'graduation photos', 'corporate event photographer',
        'newborn photoshoot', 'family portrait', 'christening photographer',
        'product photography', 'studio photographer near me', 'outdoor prenup',
        'photographer with drone', 'affordable wedding package', 'photo and video package',
    ];

    /**
     * Realistic photographer profiles with varied data for all 10 photographers
     */
    private const PHOTOGRAPHER_PROFILES = [
        // Freelancers (0-5)
        [
            'name' => 'CJ Creatives',
            'bio' => 'Candid photographer specializing in birthdays and intimate events. I love capturing genuine moments without forced poses. 3 years of experience bringing joy to celebrations.',
            'styles' => ['Candid', 'Documentary'],
            'services' => ['Birthday', 'Debut', 'Portrait'],
            'price_min' => 3000,
            'price_max' => 12000,
            'years' => 3,
            'packages' => [
                ['name' => 'Birthday Party Package', 'price' => 4000, 'items' => ['3 hours coverage', '100 edited photos', 'Online gallery']],
                ['name' => 'Debut Documentation', 'price' => 8000, 'items' => ['6 hours coverage', '200 edited photos', 'Slideshow video']],
            ],
            'addons' => [
                ['name' => 'Extra Hour', 'price' => 1000],
                ['name' => 'Print Delivery', 'price' => 500],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'CJ captured my daughter\'s birthday perfectly! Every moment felt so natural and candid. Highly recommend!'],
                ['rating' => 5, 'comment' => 'Best photographer for candid shots. CJ has a gift for capturing emotion without being intrusive.'],
                ['rating' => 4, 'comment' => 'Great work! Would have preferred a bit more posed family shots, but overall excellent.'],
                ['rating' => 5, 'comment' => 'My debut photos are absolutely stunning. CJ was professional and made me feel at ease the entire time.'],
            ],
        ],
        [
            'name' => 'Joesol Photography',
            'bio' => 'Professional event photographer with 6+ years in graduations, christenings, and corporate functions. Clean, polished coverage that captures important milestones reliably.',
            'styles' => ['Traditional', 'Documentary'],
            'services' => ['Graduation', 'Christening', 'Corporate', 'Event'],
            'price_min' => 3500,
            'price_max' => 15000,
            'years' => 6,
            'packages' => [
                ['name' => 'Graduation Package', 'price' => 5000, 'items' => ['4 hours coverage', '150 edited photos', 'Custom album book']],
                ['name' => 'Corporate Event Package', 'price' => 9000, 'items' => ['8 hours coverage', '300 edited photos', 'Highlights video']],
                ['name' => 'Christening Coverage', 'price' => 6000, 'items' => ['5 hours coverage', '180 edited photos']],
            ],
            'addons' => [
                ['name' => 'Drone Coverage', 'price' => 3000],
                ['name' => 'Video Highlights', 'price' => 2500],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'Joesol was incredible at our corporate event. Professional, organized, and delivered amazing photos quickly.'],
                ['rating' => 4, 'comment' => 'Great graduation photos. The edited images were beautiful and delivered on time.'],
                ['rating' => 5, 'comment' => 'Best photographer we\'ve hired for our christening. Every important moment was captured perfectly.'],
                ['rating' => 5, 'comment' => 'Reliable, professional, and produces high-quality work. Booking again for next year\'s event!'],
                ['rating' => 4, 'comment' => 'Good work overall. Minor issue with scheduling but photographer made up for it with quality.'],
            ],
        ],
        [
            'name' => 'Frederick Robelas',
            'bio' => 'Fine art photographer creating romantic, editorial-style prenup and portrait sessions. Specializing in natural light and authentic emotion. Building a boutique portfolio since 2024.',
            'styles' => ['Fine Art', 'Cinematic'],
            'services' => ['Prenup', 'Portrait', 'Engagement'],
            'price_min' => 4000,
            'price_max' => 14000,
            'years' => 2,
            'packages' => [
                ['name' => 'Prenup Session', 'price' => 10000, 'items' => ['4 hours shooting', '250+ raw selections', 'Fine art editing', 'Digital gallery']],
                ['name' => 'Portrait Session', 'price' => 5000, 'items' => ['2 hours session', '80 edited images', 'Location flexibility']],
            ],
            'addons' => [
                ['name' => 'Cinematic Video Edit', 'price' => 3500],
                ['name' => 'Additional Location', 'price' => 2000],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'Frederick created absolute magic with our prenup photos. Every shot is editorial-quality. This is fine art photography!'],
                ['rating' => 5, 'comment' => 'The artistic vision and editing are exceptional. Worth every peso. Can\'t wait to see our wedding photos!'],
                ['rating' => 4, 'comment' => 'Beautiful prenup session. Frederick is very artistic and professional. Slightly pricey but the quality justifies it.'],
                ['rating' => 5, 'comment' => 'My portrait session was like being in a fashion magazine. Frederick knows how to work with natural light.'],
            ],
        ],
        [
            'name' => 'Aurora Studios',
            'bio' => 'Specializing in family portraits and newborn photography with a warm, nurturing approach. Creating timeless keepsakes that celebrate life\'s special moments. 4 years of beautiful memories.',
            'styles' => ['Traditional', 'Fine Art'],
            'services' => ['Family Portrait', 'Newborn', 'Portrait', 'Children'],
            'price_min' => 2500,
            'price_max' => 10000,
            'years' => 4,
            'packages' => [
                ['name' => 'Newborn Bundle', 'price' => 6000, 'items' => ['In-home session', '150 edited photos', '2 digitally created backdrops']],
                ['name' => 'Family Portrait Session', 'price' => 4000, 'items' => ['2 hours session', '120 edited images', 'Outfit change included']],
                ['name' => 'Children\'s Portrait', 'price' => 2500, 'items' => ['1 hour session', '60 images', 'One location']],
            ],
            'addons' => [
                ['name' => 'Maternity Session', 'price' => 2000],
                ['name' => 'Printed Album', 'price' => 2500],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'Aurora made my newborn session so comfortable and peaceful. The photos are absolutely gorgeous.'],
                ['rating' => 5, 'comment' => 'Family photos came out beautifully! Aurora has a gift with making kids smile naturally.'],
                ['rating' => 4, 'comment' => 'Great newborn photographer. Very patient and professional. Would recommend to all new parents.'],
                ['rating' => 5, 'comment' => 'My children loved the session and the photos are frame-worthy. Aurora is wonderful!'],
                ['rating' => 4, 'comment' => 'Beautiful maternity and newborn photos. Slight delay in delivery but quality made up for it.'],
            ],
        ],
        [
            'name' => 'Lens & Soul',
            'bio' => 'Wedding and prenup specialist creating romantic, dreamy imagery with personalized shooting plans. Every couple gets a custom vision. 5 years of happily married stories.',
            'styles' => ['Cinematic', 'Fine Art'],
            'services' => ['Wedding', 'Prenup', 'Engagement', 'Portrait'],
            'price_min' => 5000,
            'price_max' => 18000,
            'years' => 5,
            'packages' => [
                ['name' => 'Bride & Groom Session', 'price' => 12000, 'items' => ['3 hours coverage', '200+ edited photos', 'Highlight video']],
                ['name' => 'Prenup Essentials', 'price' => 8000, 'items' => ['3 hour shoot', '150 images', 'Custom album']],
                ['name' => 'Engagement Shoot', 'price' => 5000, 'items' => ['2 hours coverage', '100 edited photos']],
            ],
            'addons' => [
                ['name' => 'Cinematic Same-Day Edit', 'price' => 4000],
                ['name' => 'Bridal Preparation', 'price' => 2000],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'Lens & Soul made our prenup magical! The dreamy aesthetic is exactly what we wanted. Outstanding work!'],
                ['rating' => 5, 'comment' => 'Our wedding photos are breathtaking. The cinematography and editing elevated everything. Highly recommended!'],
                ['rating' => 5, 'comment' => 'Romantic, artistic, and professional. Lens & Soul truly captured the soul of our love story.'],
                ['rating' => 4, 'comment' => 'Beautiful engagement photos with a dreamy quality. Minor color grading preference but overall stunning.'],
            ],
        ],
        [
            'name' => 'Eventscape Photography',
            'bio' => 'Capturing the essence of corporate events and weddings with an artistic documentary eye. From concept to final gallery, 7 years of excellence delivering unforgettable stories.',
            'styles' => ['Documentary', 'Fine Art'],
            'services' => ['Corporate', 'Wedding', 'Event', 'Conference'],
            'price_min' => 6000,
            'price_max' => 20000,
            'years' => 7,
            'packages' => [
                ['name' => 'Full Day Wedding', 'price' => 18000, 'items' => ['10 hours coverage', '400+ edited photos', 'Highlight film', 'Album']],
                ['name' => 'Corporate Event Package', 'price' => 10000, 'items' => ['8 hours coverage', '250 edited photos', 'Slideshow']],
                ['name' => 'Half Day Event', 'price' => 6000, 'items' => ['5 hours coverage', '150 photos', 'Online gallery']],
            ],
            'addons' => [
                ['name' => 'Event Videography', 'price' => 5000],
                ['name' => 'Second Shooter', 'price' => 4000],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'Eventscape perfectly documented our wedding day. The candid moments and artistic composition are incredible.'],
                ['rating' => 5, 'comment' => 'Corporate event coverage was professional and creative. Captured the energy and important moments perfectly.'],
                ['rating' => 5, 'comment' => 'Exceptional photographer with 7+ years of experience. It shows in every frame. Highly recommend!'],
                ['rating' => 4, 'comment' => 'Great work on our conference coverage. Extensive photo library delivered promptly.'],
            ],
        ],
        // Studios (6-9)
        [
            'name' => 'HHProduction',
            'bio' => 'Full-service event photography and videography studio. Team of 5 professionals with 7+ years capturing weddings, debuts, and corporate events with cinematic quality.',
            'styles' => ['Cinematic', 'Documentary'],
            'services' => ['Wedding', 'Corporate', 'Videography', 'Debut', 'Event'],
            'price_min' => 12000,
            'price_max' => 45000,
            'years' => 7,
            'team_size' => 5,
            'packages' => [
                ['name' => 'Wedding + Videography Package', 'price' => 35000, 'items' => ['12 hours dual coverage', '500+ edited photos', '4K cinematic video', 'Teaser & full edit']],
                ['name' => 'Debut Full Service', 'price' => 18000, 'items' => ['8 hours coverage', '300 photos', 'HD video highlights', 'Album']],
                ['name' => 'Corporate Video Package', 'price' => 15000, 'items' => ['8 hours video', 'Full event coverage', 'Edited highlights', 'Drone shots']],
            ],
            'addons' => [
                ['name' => 'Drone Aerial', 'price' => 3500],
                ['name' => 'Same-Day Edit', 'price' => 5000],
                ['name' => 'Additional Videographer', 'price' => 4000],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'HHProduction delivered cinema-quality wedding coverage. The team was organized and captured every perfect moment!'],
                ['rating' => 5, 'comment' => 'Incredible production value! Both photos and video are stunning. Professional team throughout the entire process.'],
                ['rating' => 5, 'comment' => 'Our corporate event looked amazing thanks to HHProduction. Drone shots added such great perspective!'],
                ['rating' => 5, 'comment' => 'Best investment for our debut. The 5-person team ensured nothing was missed. Outstanding quality!'],
            ],
        ],
        [
            'name' => 'KAP Studio',
            'bio' => 'Boutique photography studio known for clean, fine-art portraiture and elegant wedding coverage. Small team (3 people) means personalized attention on every shoot. 5 years of refined artistry.',
            'styles' => ['Fine Art', 'Traditional'],
            'services' => ['Wedding', 'Portrait', 'Debut', 'Family', 'Engagement'],
            'price_min' => 8000,
            'price_max' => 30000,
            'years' => 5,
            'team_size' => 3,
            'packages' => [
                ['name' => 'Intimate Wedding Package', 'price' => 22000, 'items' => ['8 hours coverage', '250+ edited photos', 'Handmade album', 'Engagement session included']],
                ['name' => 'Fine Art Portrait Session', 'price' => 8000, 'items' => ['3 hour session', '150 images', 'Studio or location', 'Custom backdrop']],
                ['name' => 'Debut Elegance', 'price' => 14000, 'items' => ['6 hours coverage', '200 images', 'Professional album']],
            ],
            'addons' => [
                ['name' => 'Engagement Session', 'price' => 3500],
                ['name' => 'Luxury Album', 'price' => 4000],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'KAP Studio\'s fine art approach is unlike anything else. Our wedding photos are pure elegance.'],
                ['rating' => 5, 'comment' => 'The boutique experience makes such a difference. Personalized attention and refined artistry throughout.'],
                ['rating' => 5, 'comment' => 'My portrait session was elevated and artistic. Each photo feels carefully composed. Highly impressed!'],
                ['rating' => 4, 'comment' => 'Beautiful debut package and album quality is exceptional. Professional and meticulous work.'],
            ],
        ],
        [
            'name' => 'Amaras Studio',
            'bio' => 'Bringing warmth and romance to weddings, prenups, and family portraits. Team of 4 hands-on from concept through delivery. 4 years of creating lasting, emotional memories.',
            'styles' => ['Traditional', 'Candid'],
            'services' => ['Wedding', 'Prenup', 'Family', 'Portrait', 'Event'],
            'price_min' => 9000,
            'price_max' => 32000,
            'years' => 4,
            'team_size' => 4,
            'packages' => [
                ['name' => 'Romantic Wedding Package', 'price' => 28000, 'items' => ['10 hours coverage', '350+ photos', 'Candid + posed blend', 'Album & slideshow']],
                ['name' => 'Prenup Escape', 'price' => 12000, 'items' => ['4 hours outdoor shoot', '200 images', 'Album included']],
                ['name' => 'Family & Milestone Session', 'price' => 9000, 'items' => ['3 hours session', '150 edited photos', 'Location scout']],
            ],
            'addons' => [
                ['name' => 'Prenup Videography', 'price' => 4500],
                ['name' => 'Family Album', 'price' => 3000],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'Amaras captured our wedding day perfectly! The warmth and romance in every shot is exactly what we wanted.'],
                ['rating' => 5, 'comment' => 'Our prenup photos are absolutely dreamy. The team made us feel so comfortable and the results are stunning.'],
                ['rating' => 5, 'comment' => 'Family photos that actually look natural and heartfelt. Amaras has a gift for capturing real emotion.'],
                ['rating' => 4, 'comment' => 'Great experience working with the team. Minor scheduling conflict but resolved quickly and professionally.'],
            ],
        ],
        [
            'name' => 'Lumina Collective',
            'bio' => 'Award-winning studio with team of 6 dedicated to bringing your vision to life. Specializing in high-end weddings, corporate events, and editorial shoots. 8+ years of excellence.',
            'styles' => ['Fine Art', 'Cinematic'],
            'services' => ['Wedding', 'Corporate', 'Editorial', 'Event', 'Commercial'],
            'price_min' => 15000,
            'price_max' => 50000,
            'years' => 8,
            'team_size' => 6,
            'packages' => [
                ['name' => 'Luxury Wedding Experience', 'price' => 45000, 'items' => ['12 hours dual coverage', '500+ photos', '4K cinema video', 'Premium album', 'Pre-wedding session']],
                ['name' => 'Corporate Executive Package', 'price' => 20000, 'items' => ['Full day coverage', '300+ photos', 'Event highlights', 'Digital deliverables']],
                ['name' => 'Editorial & Commercial', 'price' => 25000, 'items' => ['3 day shoot', 'Concept development', '400+ images', 'Professional retouching']],
            ],
            'addons' => [
                ['name' => 'Second Unit/Videography', 'price' => 6000],
                ['name' => 'Drone + Aerial Cinematography', 'price' => 5500],
                ['name' => 'Pre-Wedding Travel Session', 'price' => 7000],
            ],
            'reviews' => [
                ['rating' => 5, 'comment' => 'Lumina Collective is the premium choice for weddings. Award-winning quality in every frame. Absolutely worth it!'],
                ['rating' => 5, 'comment' => 'The 6-person team ensured every moment was captured with multiple angles. Cinematic editing is stunning!'],
                ['rating' => 5, 'comment' => 'Our corporate event looked like a magazine spread. Professional, creative, and extremely polished.'],
                ['rating' => 5, 'comment' => 'Luxury service from start to finish. The pre-wedding session addition was an amazing bonus. Best money spent!'],
            ],
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('DemoSeeder only runs in local/testing.');

            return;
        }

        $admin = User::where('account_type', AccountType::Administrator)->first();

        $this->command->info('Seeding clients...');
        $clients = $this->createClients();

        $this->command->info('Seeding photographers...');
        $photographers = $this->createPhotographers();
        $approved = $photographers;  // All photographers are now approved

        $this->command->info('Seeding favorites...');
        $this->createFavorites($clients, $approved);

        $this->command->info('Seeding bookings, payments & reviews...');
        $bookings = $this->createBookings($clients, $approved, $admin);

        $this->command->info('Seeding detailed reviews...');
        $this->createDetailedReviews($clients, $approved, $bookings);

        $this->command->info('Seeding reports...');
        $this->createReports($clients, $approved, $admin, $bookings);

        $this->command->info('Seeding activity logs...');
        $this->createActivityLogs($admin, $photographers, $bookings);

        $this->command->info('Seeding profile views & search logs...');
        $this->createProfileViews($approved);
        $this->createSearchLogs();

        $this->command->info(sprintf(
            'Demo data seeded: %d clients, %d photographers (all approved), %d bookings.',
            $clients->count(),
            $photographers->count(),
            $bookings->count(),
        ));
    }

    /** @return Collection<int, User> */
    private function createClients(): Collection
    {
        return collect(range(1, self::CLIENT_COUNT))->map(function () {
            $client = User::factory()->create();

            ClientProfile::factory()->create(['user_id' => $client->id]);

            return $client;
        });
    }

    /**
     * @return Collection<int, array{user: User, application: PhotographerApplication, type: PhotographerType}>
     */
    private function createPhotographers(): Collection
    {
        return collect(range(0, self::PHOTOGRAPHER_COUNT - 1))->map(function (int $i) {
            $type = $i < self::FREELANCER_COUNT ? PhotographerType::Freelancer : PhotographerType::Studio;
            $profile = self::PHOTOGRAPHER_PROFILES[$i] ?? self::PHOTOGRAPHER_PROFILES[0];
            $name = $profile['name'];

            $user = User::factory()->photographer()->create(['name' => $name]);

            // All photographers are approved
            $status = 'approved';

            $applicationFactory = PhotographerApplication::factory()
                ->state(['user_id' => $user->id, 'photographer_type' => $type]);

            if ($type === PhotographerType::Studio) {
                $applicationFactory = $applicationFactory->studio();
            }

            $reviewer = User::where('account_type', AccountType::Administrator)->first();

            $application = $applicationFactory->approved()->create([
                'reviewed_by' => $reviewer?->id,
                'business_name' => $name,
                'years_active' => $profile['years'],
                'team_size' => $profile['team_size'] ?? null,
                'services' => $profile['services'],
                'price_min' => $profile['price_min'],
                'price_max' => $profile['price_max'],
            ]);

            $imagePaths = $this->seedProfileImages($i, $name);

            $profilePhoto = PhotographerProfile::factory()->create([
                'user_id' => $user->id,
                'bio' => $profile['bio'],
                'style' => $profile['styles'],
                'profile_photo_path' => $imagePaths['profile'],
                'cover_photo_path' => $imagePaths['cover'],
                'facebook' => 'https://facebook.com/'.Str::slug($name),
                'instagram' => 'https://instagram.com/'.Str::slug($name),
                'website' => fake()->boolean(40) ? 'https://'.Str::slug($name).'.test' : null,
            ]);

            $packages = collect();
            $addOns = collect();

            $this->setupApprovedPhotographerBusiness($user, $profile);
            $packages = Package::where('user_id', $user->id)->get();
            $addOns = AddOn::where('user_id', $user->id)->get();

            return [
                'user' => $user,
                'application' => $application,
                'profile' => $profilePhoto,
                'type' => $type,
                'packages' => $packages,
                'add_ons' => $addOns,
                'profile_data' => $profile,
            ];
        });
    }

    /**
     * Generates real placeholder JPEG files on the `public` disk and returns
     * the paths to store on the profile.
     *
     * @return array{profile: string|null, cover: string|null}
     */
    private function seedProfileImages(int $index, string $name): array
    {
        if (! extension_loaded('gd')) {
            $this->command->warn("GD extension not available — skipping demo image generation for \"{$name}\". Enable php-gd to seed profile/cover images.");

            return ['profile' => null, 'cover' => null];
        }

        $slug = Str::slug($name) ?: 'photographer';
        $color = self::AVATAR_COLORS[$index % count(self::AVATAR_COLORS)];
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->map(fn (string $word) => strtoupper($word[0]))
            ->take(2)
            ->implode('');

        $profilePath = "photographers/seed/{$slug}-{$index}-profile.jpg";
        $coverPath = "photographers/seed/{$slug}-{$index}-cover.jpg";

        if (! Storage::disk('public')->exists($profilePath)) {
            Storage::disk('public')->put($profilePath, $this->generatePlaceholderImage($initials ?: 'PH', 400, 400, $color));
        }

        if (! Storage::disk('public')->exists($coverPath)) {
            Storage::disk('public')->put($coverPath, $this->generatePlaceholderImage($name, 1200, 400, $color));
        }

        return ['profile' => $profilePath, 'cover' => $coverPath];
    }

    /**
     * Renders a solid-color JPEG with a centered text label using GD.
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

    /**
     * Seeds 6-8 real portfolio images with persisting JPEG files on disk.
     */
    private function seedPortfolioImages(User $photographer): void
    {
        if (! extension_loaded('gd')) {
            $this->command->warn("GD extension not available — skipping portfolio images for \"{$photographer->name}\". Enable php-gd to seed portfolio images.");
            return;
        }

        $imageCount = fake()->numberBetween(6, 8);
        $slug = Str::slug($photographer->name) ?: 'photographer';
        
        for ($i = 1; $i <= $imageCount; $i++) {
            $colorIndex = ($photographer->id + $i) % count(self::AVATAR_COLORS);
            $color = self::AVATAR_COLORS[$colorIndex];
            
            $path = "photographers/seed/{$slug}-{$photographer->id}-portfolio-{$i}.jpg";
            
            if (! Storage::disk('public')->exists($path)) {
                $label = "Photo {$i}";
                Storage::disk('public')->put($path, $this->generatePlaceholderImage($label, 800, 600, $color));
            }
            
            PhotographerPortfolioImage::firstOrCreate(
                [
                    'user_id' => $photographer->id,
                    'path' => $path,
                ],
                [
                    'status' => PortfolioImageStatus::Active,
                    'sort_order' => $i,
                ]
            );
        }
    }

    private function setupApprovedPhotographerBusiness(User $photographer, array $profileData): void
    {
        $this->seedPortfolioImages($photographer);
        
        PhotographerPortfolioImage::factory()
            ->archived()
            ->count(fake()->numberBetween(1, 2))
            ->create(['user_id' => $photographer->id]);

        // Create photographer-specific packages
        foreach ($profileData['packages'] as $pkgData) {
            Package::factory()->create([
                'user_id' => $photographer->id,
                'name' => $pkgData['name'],
                'included_items' => $pkgData['items'],
                'price' => $pkgData['price'],
                'status' => PackageStatus::Published,
            ]);
        }

        // Occasionally archive an old package
        if (fake()->boolean(30)) {
            Package::factory()->archived()->create([
                'user_id' => $photographer->id,
                'name' => 'Previous Year Package',
            ]);
        }

        // Create photographer-specific add-ons
        foreach ($profileData['addons'] as $addonData) {
            AddOn::factory()->create([
                'user_id' => $photographer->id,
                'name' => $addonData['name'],
                'description' => $addonData['name'].' add-on for your booking.',
                'price' => $addonData['price'],
            ]);
        }

        // Custom packages: ~60% of photographers offer one
        if (fake()->boolean(60)) {
            CustomPackageConfig::factory()->create([
                'user_id' => $photographer->id,
                'enabled' => true,
                'base_fee' => fake()->randomElement([1500, 2000, 3000]),
                'buffer_minutes' => 30,
            ]);

            $this->createCustomPackageComponents($photographer);
        }

        // Availability: ~8 upcoming open windows, wide enough to fit any package
        for ($i = 0; $i < 8; $i++) {
            AvailabilityWindow::factory()->create([
                'user_id' => $photographer->id,
                'date' => now()->addDays(fake()->numberBetween(2, 30))->format('Y-m-d'),
                'start_time' => '06:00',
                'end_time' => '22:00',
            ]);
        }

        // Blocked dates
        BlockedDate::factory()->count(fake()->numberBetween(1, 2))->create([
            'user_id' => $photographer->id,
        ]);

        // GCash payout config
        PhotographerPaymentConfig::factory()->create(['user_id' => $photographer->id]);

        // Payment references
        $refCount = fake()->numberBetween(3, 5);
        for ($i = 0; $i < $refCount; $i++) {
            $state = fake()->randomElement(['available', 'available', 'used', 'used', 'invalidated']);
            $factory = PhotographerPaymentReference::factory();
            $factory = match ($state) {
                'used' => $factory->used(),
                'invalidated' => $factory->invalidated(),
                default => $factory,
            };
            $factory->create(['photographer_id' => $photographer->id]);
        }

        // Walk-in clients
        WalkInClient::factory()->count(fake()->numberBetween(2, 4))->create([
            'photographer_id' => $photographer->id,
        ]);
    }

    private function createCustomPackageComponents(User $photographer): void
    {
        $durationTiers = [
            ['label' => '2 Hours Coverage', 'duration_minutes' => 120, 'price_addition' => 0],
            ['label' => '4 Hours Coverage', 'duration_minutes' => 240, 'price_addition' => 1500],
            ['label' => '6 Hours Coverage', 'duration_minutes' => 360, 'price_addition' => 3000],
        ];
        foreach ($durationTiers as $tier) {
            CustomPackageComponent::factory()->create([
                'user_id' => $photographer->id,
                'type' => CustomPackageComponentType::TierOption,
                'tier_name' => 'Coverage Duration',
                'label' => $tier['label'],
                'price_addition' => $tier['price_addition'],
                'duration_minutes' => $tier['duration_minutes'],
            ]);
        }

        $photoTiers = [
            ['label' => '150 Edited Photos', 'price_addition' => 0],
            ['label' => '300 Edited Photos', 'price_addition' => 2000],
        ];
        foreach ($photoTiers as $tier) {
            CustomPackageComponent::factory()->create([
                'user_id' => $photographer->id,
                'type' => CustomPackageComponentType::TierOption,
                'tier_name' => 'Edited Photos',
                'label' => $tier['label'],
                'price_addition' => $tier['price_addition'],
                'duration_minutes' => null,
            ]);
        }

        CustomPackageComponent::factory()->create([
            'user_id' => $photographer->id,
            'type' => CustomPackageComponentType::FlatOption,
            'tier_name' => null,
            'label' => 'Drone Coverage',
            'price_addition' => 2500,
            'duration_minutes' => null,
        ]);
    }

    /**
     * @param  Collection<int, User>  $clients
     * @param  Collection<int, array{user: User}>  $approved
     */
    private function createFavorites(Collection $clients, Collection $approved): void
    {
        foreach ($clients as $client) {
            $picks = $approved->random(min(fake()->numberBetween(0, 3), $approved->count()));
            foreach ($picks as $photographer) {
                FavoritePhotographer::firstOrCreate([
                    'client_id' => $client->id,
                    'photographer_id' => $photographer['user']->id,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $clients
     * @param  Collection<int, array{user: User, packages: Collection}>  $approved
     * @return Collection<int, Booking>
     */
    private function createBookings(Collection $clients, Collection $approved, ?User $admin): Collection
    {
        // Ensure each photographer gets at least 3-5 bookings distributed across statuses
        $bookings = collect();
        $eventTypes = ['wedding', 'debut', 'birthday', 'corporate', 'graduation', 'christening'];
        $locationTypes = ['studio', 'client_location', 'outdoor_location', 'other'];
        $completedBookingIndex = 0;

        // Give each photographer a different number of completed bookings
        // (4-9) instead of a flat 3 each — this is what drives review-count
        // and rating variety later in createDetailedReviews().
        $completedAssignments = [];
        foreach ($approved->keys() as $idx) {
            $target = fake()->numberBetween(4, 9);
            for ($k = 0; $k < $target; $k++) {
                $completedAssignments[] = $idx;
            }
        }
        shuffle($completedAssignments);

        $bookingPlan = [
            'completed' => count($completedAssignments),
            'confirmed_paid' => 10,
            'confirmed_awaiting_payment' => 7,
            'pending' => 8,
            'cancelled_rejected' => 6,
            'cancelled_after_confirm' => 5,
            'expired' => 3,
        ];

        foreach ($bookingPlan as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $client = $clients->random();
                $photographer = $status === 'completed'
                    ? $approved->get($completedAssignments[$completedBookingIndex++])
                    : $approved->random();
                $publishedPackages = $photographer['packages']->where('status', PackageStatus::Published);
                $package = $publishedPackages->count() > 0 ? $publishedPackages->random() : null;

                $price = $package?->price ?? fake()->randomElement([10000, 12000, 15000, 20000]);
                $daysOffset = match ($status) {
                    'completed' => -fake()->numberBetween(10, 180),
                    'confirmed_paid' => fake()->numberBetween(3, 60),
                    'confirmed_awaiting_payment' => fake()->numberBetween(5, 45),
                    'pending' => fake()->numberBetween(3, 30),
                    'cancelled_rejected' => fake()->numberBetween(3, 30),
                    'cancelled_after_confirm' => fake()->numberBetween(-30, 30),
                    'expired' => fake()->numberBetween(3, 20),
                };

                $booking = Booking::factory()->create([
                    'client_id' => $client->id,
                    'photographer_id' => $photographer['user']->id,
                    'package_id' => $package?->id,
                    'is_custom_package' => false,
                    'package_snapshot' => $package ? ['name' => $package->name, 'price' => (float) $package->price] : null,
                    'add_ons_snapshot' => [],
                    'event_type' => fake()->randomElement($eventTypes),
                    'event_date' => now()->addDays($daysOffset)->format('Y-m-d'),
                    'start_time' => '09:00',
                    'end_time' => '13:00',
                    'location_type' => fake()->randomElement($locationTypes),
                    'event_address' => fake()->address(),
                    'guest_count' => fake()->numberBetween(20, 250),
                    'subtotal' => $price,
                    'total_price' => $price,
                    'status' => BookingStatus::Pending,
                    'hold_expires_at' => now()->addHours(24),
                ]);

                $this->applyBookingStatus($booking, $status, $admin);
                $bookings->push($booking->fresh());
            }
        }

        return $bookings;
    }

    private function applyBookingStatus(Booking $booking, string $status, ?User $admin): void
    {
        $plan = fake()->randomElement([PaymentPlan::Half, PaymentPlan::Full]);

        match ($status) {
            'pending' => null,

            'confirmed_awaiting_payment' => $booking->update([
                'status' => BookingStatus::Confirmed,
                'hold_expires_at' => null,
            ]),

            'cancelled_rejected' => $booking->update([
                'status' => BookingStatus::Cancelled,
                'rejection_reason' => fake()->randomElement([
                    'Not available on the requested date.',
                    'Requested package no longer offered.',
                    'Fully booked for that week.',
                ]),
                'hold_expires_at' => null,
            ]),

            'expired' => $booking->update([
                'status' => BookingStatus::Expired,
                'hold_expires_at' => now()->subDay(),
            ]),

            'cancelled_after_confirm' => $this->applyCancelled($booking, $plan, $admin),
            'confirmed_paid' => $this->applyConfirmed($booking, $plan, $admin),
            'completed' => $this->applyCompleted($booking, $plan, $admin),

            default => null,
        };
    }

    private function applyCancelled(Booking $booking, PaymentPlan $plan, ?User $admin): void
    {
        $wasConfirmedFirst = fake()->boolean(50);
        $decision = fake()->randomElement(['approved', 'rejected']);

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'hold_expires_at' => null,
            'payment_plan' => $wasConfirmedFirst ? $plan : null,
            'payment_status' => $wasConfirmedFirst ? BookingPaymentStatus::Cancelled : BookingPaymentStatus::Pending,
            'cancellation_reason' => fake()->randomElement([
                'Change of event date conflicts with photographer availability.',
                'Client found another provider.',
                'Family emergency.',
            ]),
            'cancellation_requested_at' => now()->subDays(fake()->numberBetween(1, 10)),
            'cancellation_decision' => $decision,
            'cancellation_decided_at' => now()->subDays(fake()->numberBetween(0, 5)),
        ]);

        if ($wasConfirmedFirst) {
            $this->createPaymentsForBooking($booking, $plan, onlyFirstInstallment: true, admin: $admin);
        }
    }

    private function applyConfirmed(Booking $booking, PaymentPlan $plan, ?User $admin): void
    {
        $verificationQueueCase = fake()->boolean(20);
        $serviceStatus = fake()->randomElement([ServiceTrackerStatus::EventDay, ServiceTrackerStatus::Editing]);

        $booking->update([
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => null,
            'payment_plan' => $plan,
            'service_status' => $serviceStatus,
            'service_status_updated_at' => now()->subDays(fake()->numberBetween(0, 5)),
        ]);

        if ($verificationQueueCase) {
            $booking->update(['payment_status' => BookingPaymentStatus::PendingVerification]);
            $this->createPaymentsForBooking($booking, $plan, onlyFirstInstallment: true, admin: $admin, firstInstallmentUnmatched: true);
            return;
        }

        if ($plan === PaymentPlan::Full) {
            $booking->update(['payment_status' => BookingPaymentStatus::FullyPaid]);
            $this->createPaymentsForBooking($booking, $plan, onlyFirstInstallment: false, admin: $admin);
        } else {
            $booking->update(['payment_status' => BookingPaymentStatus::PartiallyPaid]);
            $this->createPaymentsForBooking($booking, $plan, onlyFirstInstallment: true, admin: $admin);
        }
    }

    private function applyCompleted(Booking $booking, PaymentPlan $plan, ?User $admin): void
    {
        $booking->update([
            'status' => BookingStatus::Completed,
            'hold_expires_at' => null,
            'payment_plan' => $plan,
            'payment_status' => BookingPaymentStatus::FullyPaid,
            'service_status' => ServiceTrackerStatus::Delivered,
            'service_status_updated_at' => now()->subDays(fake()->numberBetween(1, 90)),
        ]);

        $this->createPaymentsForBooking($booking, $plan, onlyFirstInstallment: false, admin: $admin);
    }

    private function createPaymentsForBooking(
        Booking $booking,
        PaymentPlan $plan,
        bool $onlyFirstInstallment,
        ?User $admin,
        bool $firstInstallmentUnmatched = false,
    ): void {
        $total = (float) $booking->total_price;
        $firstAmount = $plan === PaymentPlan::Full ? $total : round($total / 2, 2);
        $paymentDate = $booking->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        if ($firstInstallmentUnmatched) {
            Payment::factory()->create([
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'photographer_id' => $booking->photographer_id,
                'plan' => $plan,
                'amount' => $firstAmount,
                'photographer_payment_reference_id' => null,
                'matching_status' => fake()->randomElement(['pending_match', 'not_matched']),
                'payment_date' => $paymentDate,
            ]);

            return;
        }

        $firstReference = PhotographerPaymentReference::factory()->used()->create([
            'photographer_id' => $booking->photographer_id,
            'amount_received' => $firstAmount,
            'payment_date' => $paymentDate,
        ]);

        Payment::factory()->create([
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'photographer_id' => $booking->photographer_id,
            'plan' => $plan,
            'amount' => $firstAmount,
            'reference_number' => $firstReference->reference_number,
            'photographer_payment_reference_id' => $firstReference->id,
            'payment_date' => $firstReference->payment_date,
            'matching_status' => 'matched',
            'verified_by' => $admin?->id,
            'verified_at' => now(),
            'verification_action' => 'verified',
        ]);

        if ($onlyFirstInstallment || $plan === PaymentPlan::Full) {
            return;
        }

        $onsiteFactory = Payment::factory()->onsite();
        $onsiteFactory = $admin ? $onsiteFactory->manuallyVerified($admin) : $onsiteFactory;

        $onsiteFactory->create([
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'photographer_id' => $booking->photographer_id,
            'plan' => $plan,
            'amount' => round($total - $firstAmount, 2),
            'payment_date' => $booking->event_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'notes' => 'Remaining balance collected on-site.',
        ]);
    }

    /**
     * Seeds detailed, realistic reviews for each photographer with varied ratings and text.
     * Reviews are created from completed bookings — every completed booking gets one review.
     * The first few reviews per photographer use the curated, hand-written text from
     * PHOTOGRAPHER_PROFILES; once that runs out, additional reviews use weighted-random
     * ratings (mostly 4-5, some 3, occasional 2) with matching template comments, so
     * review counts and average ratings vary realistically across photographers instead
     * of every profile showing the same flat "3 reviews, all 5 stars".
     */
    private function createDetailedReviews(Collection $clients, Collection $approved, Collection $bookings): void
    {
        $extraCommentsByRating = [
            5 => [
                'Absolutely worth it — every shot felt intentional and the turnaround was fast.',
                'They went above and beyond on the day. Couldn\'t have asked for better coverage.',
                'Communication was great from booking to delivery. Photos speak for themselves.',
                'Second time booking and just as impressed as the first. Highly recommend.',
            ],
            4 => [
                'Really solid work overall, just a couple of shots I wish were framed differently.',
                'Good experience, minor delay on delivery but the quality made up for it.',
                'Happy with the results — professional and easy to coordinate with.',
                'Great value for the price. Would book again for a smaller event.',
            ],
            3 => [
                'Decent photos but felt a bit rushed during the actual shoot.',
                'It was okay — some good shots, some I probably wouldn\'t have kept.',
                'Average experience overall. Communication could have been clearer about the schedule.',
            ],
            2 => [
                'Photos were alright but arrived much later than promised with no update.',
                'Expected more given the price point. A few unusable shots in the batch.',
            ],
        ];

        $replyVariants = [
            'Thank you so much for trusting us with your event! It was a pleasure working with you.',
            'We really appreciate you taking the time to share this — thank you for the opportunity!',
            'So glad you loved the photos! Thanks again for having us.',
            'Thanks for the honest feedback — we\'re taking this into account for future bookings.',
            'Appreciate you choosing us for your special day, thank you!',
        ];

        foreach ($approved as $photographer) {
            // Get every completed booking for this photographer (count varies
            // 4-9 per photographer — see createBookings()).
            $completedBookings = $bookings->filter(
                fn (Booking $b) => $b->photographer_id === $photographer['user']->id && $b->status === BookingStatus::Completed
            )->sortBy('id')->values();

            $profileData = $photographer['profile_data'] ?? [];
            $curatedReviews = $profileData['reviews'] ?? [];

            foreach ($completedBookings as $index => $booking) {
                if ($index < count($curatedReviews)) {
                    $rating = $curatedReviews[$index]['rating'];
                    $comment = $curatedReviews[$index]['comment'];
                } else {
                    // Ran out of hand-written text — fall back to a weighted
                    // random rating with a matching template comment.
                    $rating = fake()->randomElement([5, 5, 5, 4, 4, 4, 3, 3, 2]);
                    $comment = fake()->randomElement($extraCommentsByRating[$rating] ?? $extraCommentsByRating[3]);
                }

                // Guarantee at least one reply per photographer (always reply
                // to their first review), then a 50/50 chance on the rest.
                $hasReply = $index === 0 || fake()->boolean(50);

                Review::create([
                    'booking_id' => $booking->id,
                    'client_id' => $booking->client_id,
                    'photographer_id' => $booking->photographer_id,
                    'rating' => $rating,
                    'comment' => $comment,
                    'reply' => $hasReply ? fake()->randomElement($replyVariants) : null,
                    'replied_at' => $hasReply ? now()->subDays(fake()->numberBetween(1, 30)) : null,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $clients
     * @param  Collection<int, array{user: User}>  $approved
     * @param  Collection<int, Booking>  $bookings
     */
    private function createReports(Collection $clients, Collection $approved, ?User $admin, Collection $bookings): void
    {
        $reportables = [
            ['target' => ReportTargetType::Booking, 'status' => ReportStatus::Resolved, 'severity' => ReportSeverity::High, 'action' => ReportRequestedAction::Refund],
            ['target' => ReportTargetType::Booking, 'status' => ReportStatus::UnderReview, 'severity' => ReportSeverity::Medium, 'action' => ReportRequestedAction::Investigate],
            ['target' => ReportTargetType::Booking, 'status' => ReportStatus::Submitted, 'severity' => ReportSeverity::Low, 'action' => ReportRequestedAction::Other],
            ['target' => ReportTargetType::Payment, 'status' => ReportStatus::UnderReview, 'severity' => ReportSeverity::Urgent, 'action' => ReportRequestedAction::Investigate],
            ['target' => ReportTargetType::Client, 'status' => ReportStatus::Closed, 'severity' => ReportSeverity::Medium, 'action' => ReportRequestedAction::Warn],
            ['target' => ReportTargetType::Studio, 'status' => ReportStatus::Resolved, 'severity' => ReportSeverity::High, 'action' => ReportRequestedAction::RemoveReview],
            ['target' => ReportTargetType::Bug, 'status' => ReportStatus::Submitted, 'severity' => ReportSeverity::Low, 'action' => ReportRequestedAction::Other],
            ['target' => ReportTargetType::Other, 'status' => ReportStatus::Submitted, 'severity' => ReportSeverity::Low, 'action' => ReportRequestedAction::Other],
        ];

        foreach ($reportables as $spec) {
            $reporter = fake()->boolean(60) ? $clients->random() : $approved->random()['user'];

            $referenceId = match ($spec['target']) {
                ReportTargetType::Booking => (string) $bookings->random()->id,
                ReportTargetType::Payment => (string) $bookings->random()->id,
                ReportTargetType::Client => (string) $clients->random()->id,
                ReportTargetType::Studio => (string) $approved->random()['user']->id,
                default => null,
            };

            $report = Report::factory()->create([
                'reporter_id' => $reporter->id,
                'target_type' => $spec['target']->value,
                'reference_id' => $referenceId,
                'reason' => match ($spec['target']) {
                    ReportTargetType::Booking => 'Photographer did not show up on the event date.',
                    ReportTargetType::Payment => 'GCash reference number was not recognized by the system.',
                    ReportTargetType::Client => 'Client was verbally abusive during the shoot.',
                    ReportTargetType::Studio => 'Delivered photos do not match the portfolio quality shown.',
                    ReportTargetType::Bug => 'Booking calendar shows the wrong available dates.',
                    default => 'General concern about platform behavior.',
                },
                'severity' => $spec['severity'],
                'details' => fake()->paragraph(),
                'requested_action' => $spec['action'],
                'status' => $spec['status'],
                'resolved_at' => in_array($spec['status'], [ReportStatus::Resolved, ReportStatus::Closed], true)
                    ? now()->subDays(fake()->numberBetween(0, 10))
                    : null,
            ]);

            if (in_array($spec['status'], [ReportStatus::UnderReview, ReportStatus::Resolved, ReportStatus::Closed], true)) {
                ReportNote::factory()->create([
                    'report_id' => $report->id,
                    'admin_id' => $admin?->id,
                    'note' => 'Reviewed the submitted evidence and reached out to both parties for clarification.',
                ]);

                if (fake()->boolean(40)) {
                    ReportNote::factory()->create([
                        'report_id' => $report->id,
                        'admin_id' => $admin?->id,
                        'note' => 'Follow-up: resolution communicated to the reporter.',
                    ]);
                }
            }
        }
    }

    /**
     * @param  Collection<int, array{user: User, application: PhotographerApplication}>  $photographers
     * @param  Collection<int, Booking>  $bookings
     */
    private function createActivityLogs(?User $admin, Collection $photographers, Collection $bookings): void
    {
        foreach ($photographers as $entry) {
            $status = $entry['application']->status;

            ActivityLog::create([
                'causer_id' => $admin?->id,
                'subject_type' => PhotographerApplication::class,
                'subject_id' => $entry['application']->id,
                'action' => 'application.approved',
                'description' => 'Approved photographer application',
                'metadata' => ['photographer_name' => $entry['user']->name],
                'created_at' => now()->subDays(fake()->numberBetween(1, 60)),
            ]);
        }

        foreach ($bookings as $booking) {
            $status = $booking->status->value;
            if ($status === 'pending') {
                continue;
            }

            [$action, $description, $causerId] = match (true) {
                $status === 'confirmed' => ['booking.confirmed', 'Booking confirmed', $booking->photographer_id],
                $status === 'completed' => ['booking.completed', 'Service marked as completed', $booking->photographer_id],
                $status === 'cancelled' && $booking->rejection_reason !== null => ['booking.rejected', 'Photographer declined the booking request', $booking->photographer_id],
                $status === 'cancelled' => ['booking.cancelled', 'Booking was cancelled', $booking->client_id],
                $status === 'expired' => ['booking.expired', 'Booking hold expired without action', null],
                default => ['booking.updated', 'Booking updated', null],
            };

            ActivityLog::create([
                'causer_id' => $causerId,
                'subject_type' => Booking::class,
                'subject_id' => $booking->id,
                'action' => $action,
                'description' => $description,
                'metadata' => ['event_date' => $booking->event_date?->format('Y-m-d')],
                'created_at' => $booking->updated_at ?? now(),
            ]);

            $payments = Payment::where('booking_id', $booking->id)->get();

            foreach ($payments as $payment) {
                if (! in_array($payment->matching_status->value, ['matched', 'manually_verified'], true)) {
                    continue;
                }

                ActivityLog::create([
                    'causer_id' => $admin?->id,
                    'subject_type' => Payment::class,
                    'subject_id' => $payment->id,
                    'action' => 'payment.verified',
                    'description' => 'Verified payment against submitted reference',
                    'metadata' => ['amount' => (float) $payment->amount],
                    'created_at' => $payment->verified_at ?? $payment->created_at,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, array{user: User}>  $approved
     */
    private function createProfileViews(Collection $approved): void
    {
        foreach ($approved as $entry) {
            $viewCount = fake()->numberBetween(8, 25);
            for ($i = 0; $i < $viewCount; $i++) {
                $viewedOn = now()->subDays(fake()->numberBetween(0, 30))->format('Y-m-d');

                ProfileView::create([
                    'photographer_id' => $entry['user']->id,
                    'viewer_hash' => hash('sha256', fake()->uuid()),
                    'viewed_on' => $viewedOn,
                    'created_at' => $viewedOn.' '.fake()->time(),
                ]);
            }
        }
    }

    private function createSearchLogs(): void
    {
        foreach (range(1, 30) as $i) {
            ServiceSearchLog::create([
                'term' => fake()->randomElement(self::SEARCH_TERMS),
                'created_at' => now()->subDays(fake()->numberBetween(0, 60)),
            ]);
        }
    }
}