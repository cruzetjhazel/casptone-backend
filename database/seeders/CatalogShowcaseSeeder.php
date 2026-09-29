<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\AddOnStatus;
use App\Enums\CustomPackageComponentType;
use App\Enums\PackageScheduleMode;
use App\Enums\PackageStatus;
use App\Models\AddOn;
use App\Models\BookingHour;
use App\Models\CustomPackageComponent;
use App\Models\CustomPackageConfig;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEMO/TESTING ONLY. ENRICHES the photographers that DemoSeeder and
 * PhotographerShowcaseSeeder already create (matched by name). It creates NO
 * new photographers.
 *
 * Per photographer it adds: timed, open-ended (start time only) and
 * multi-session packages; draft and archived packages; many add-ons (some
 * archived); and one custom "build your own" setup using a different pricing
 * model (fixed, hourly, per_day, per_person) with tiers and flat extras.
 * It also converts old string-style included_items into the {name, detail}
 * shape the API validates and the frontend renders.
 *
 * Demo photographers get a stable login: {slug}@example.test / password.
 * Idempotent (updateOrCreate on natural keys). Run AFTER DemoSeeder and
 * PhotographerShowcaseSeeder:
 *
 *   php artisan db:seed --class=CatalogShowcaseSeeder
 */
class CatalogShowcaseSeeder extends Seeder
{
    /** The wedding photographer BookingStatesShowcaseSeeder attaches its ~45 state bookings to. */
    public const WEDDING_EMAIL = 'lumina.collective@example.test';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('CatalogShowcaseSeeder only runs in local/testing.');

            return;
        }

        foreach ($this->catalog() as $data) {
            $users = User::where('account_type', AccountType::Photographer)
                ->where('name', $data['name'])->orderBy('id')->get();

            if ($users->isEmpty()) {
                $this->command?->warn("No photographer named '{$data['name']}'. Run DemoSeeder / PhotographerShowcaseSeeder first.");

                continue;
            }

            foreach ($users as $user) {
                $this->giveStableEmail($user, $data);
                $user->forceFill(['slot_interval_minutes' => $data['slot_interval']])->save();
                $this->normalizeExistingPackages($user);
                $this->seedHours($user, $data);
                $this->seedPackages($user, $data['packages']);
                $this->seedAddOns($user, $data['addons']);
                $this->seedCustom($user, $data['custom']);
                $this->command?->info("Enriched {$data['name']} ({$user->email})");
            }
        }
    }

    /** Random factory emails (fake@example.com etc.) become {slug}@example.test. Fixed *.test emails are left alone. */
    private function giveStableEmail(User $user, array $d): void
    {
        if (str_ends_with((string) $user->email, '.test')) {
            return;
        }
        $email = $d['email'] ?? "{$d['slug']}@example.test";
        if (! User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            $user->forceFill(['email' => $email])->save();
        }
    }

    /** Old seeders stored included_items as plain strings; the API/frontend expect {name, detail}. */
    private function normalizeExistingPackages(User $user): void
    {
        foreach (Package::where('user_id', $user->id)->get() as $pkg) {
            $items = $pkg->included_items;
            if (! is_array($items) || $items === []) {
                continue;
            }
            $fixed = array_map(fn ($i) => is_array($i) ? $i : ['name' => (string) $i, 'detail' => ''], $items);
            if ($fixed !== $items) {
                $pkg->update(['included_items' => $fixed]);
            }
        }
    }

    private function seedHours(User $user, array $d): void
    {
        BookingHour::where('user_id', $user->id)->delete();

        // null = no usual hours configured at all (the "open all day" default).
        foreach ($d['hours'] ?? [] as [$dow, $start, $end]) {
            BookingHour::create(['user_id' => $user->id, 'day_of_week' => $dow, 'start_time' => $start, 'end_time' => $end]);
        }
    }

    private function seedPackages(User $user, array $packages): void
    {
        foreach ($packages as $p) {
            $open = ($p['mode'] ?? 'timed') === 'open';
            $multi = (bool) ($p['multi'] ?? false);

            Package::updateOrCreate(
                ['user_id' => $user->id, 'name' => $p['name']],
                [
                    'description' => $p['desc'],
                    'included_items' => array_map(fn ($i) => ['name' => $i[0], 'detail' => $i[1] ?? ''], $p['items']),
                    'price' => $p['price'],
                    'duration_minutes' => $p['minutes'],
                    'buffer_minutes' => $open ? 0 : ($p['buffer'] ?? 0),
                    'schedule_mode' => $open ? PackageScheduleMode::Open : PackageScheduleMode::Timed,
                    'allows_multiple_sessions' => $multi,
                    'max_sessions' => $multi ? ($p['max'] ?? null) : null,
                    'status' => match ($p['status'] ?? 'published') {
                        'draft' => PackageStatus::Draft,
                        'archived' => PackageStatus::Archived,
                        default => PackageStatus::Published,
                    },
                ]
            );
        }
    }

    private function seedAddOns(User $user, array $addons): void
    {
        foreach ($addons as [$name, $desc, $price, $status]) {
            AddOn::updateOrCreate(
                ['user_id' => $user->id, 'name' => $name],
                [
                    'description' => $desc,
                    'price' => $price,
                    'status' => $status === 'archived' ? AddOnStatus::Archived : AddOnStatus::Active,
                ]
            );
        }
    }

    private function seedCustom(User $user, array $c): void
    {
        $model = $c['model'];
        $multi = $model !== 'fixed' && ($c['multi'] ?? false);

        CustomPackageConfig::updateOrCreate(
            ['user_id' => $user->id],
            [
                'enabled' => true,
                'pricing_model' => $model,
                'base_fee' => $model === 'fixed' ? $c['base_fee'] : null,
                'base_hours' => null,
                'hourly_rate' => $model === 'hourly' ? $c['rate'] : null,
                'min_hours' => $model === 'hourly' ? $c['min'] : null,
                'max_hours' => $model === 'hourly' ? $c['max'] : null,
                'unit_rate' => in_array($model, ['per_day', 'per_person'], true) ? $c['rate'] : null,
                'coverage_hours' => $c['coverage_hours'] ?? null,
                'max_people' => $model === 'per_person' ? ($c['max_people'] ?? null) : null,
                'allows_multiple_sessions' => $multi,
                'max_sessions' => $multi ? ($c['max_sessions'] ?? null) : null,
                'base_fee_mode' => null,
                'buffer_minutes' => $c['buffer'] ?? 0,
            ]
        );

        // Replace the old generic tiers (bookings keep their own snapshot, so this is safe).
        CustomPackageComponent::where('user_id', $user->id)->delete();

        foreach ($c['tiers'] ?? [] as $tierName => $options) {
            foreach ($options as [$label, $price, $minutes]) {
                CustomPackageComponent::create([
                    'user_id' => $user->id,
                    'tier_name' => $tierName,
                    'label' => $label,
                    'type' => CustomPackageComponentType::TierOption,
                    'price_addition' => $price,
                    'duration_minutes' => $minutes,
                    'status' => AddOnStatus::Active,
                ]);
            }
        }

        foreach ($c['extras'] ?? [] as [$label, $price]) {
            CustomPackageComponent::create([
                'user_id' => $user->id,
                'tier_name' => null,
                'label' => $label,
                'type' => CustomPackageComponentType::FlatOption,
                'price_addition' => $price,
                'duration_minutes' => null,
                'status' => AddOnStatus::Active,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Data: one entry per EXISTING photographer name
    // ------------------------------------------------------------------

    private function catalog(): array
    {
        $monSat = fn ($s, $e) => array_map(fn ($d) => [$d, $s, $e], [1, 2, 3, 4, 5, 6]);
        $allWeek = fn ($s, $e) => array_map(fn ($d) => [$d, $s, $e], [0, 1, 2, 3, 4, 5, 6]);

        return [
            // Studio. Weddings: open-ended big events + short timed sessions. Hourly custom @2200
            // (BookingStatesShowcaseSeeder hardcodes 2200/hr, so keep this rate).
            [
                'name' => 'Lumina Collective', 'slug' => 'lumina-collective', 'email' => self::WEDDING_EMAIL,
                'slot_interval' => 60, 'hours' => $allWeek('07:00', '21:00'),
                'packages' => [
                    ['name' => 'Engagement Mini Session', 'desc' => 'A relaxed one-hour couple shoot.', 'price' => 4500, 'minutes' => 60, 'buffer' => 15, 'items' => [['Coverage', '1 hour'], ['Edited photos', '30']]],
                    ['name' => 'Prenup Session', 'desc' => 'Half-day prenup at one location.', 'price' => 8500, 'minutes' => 180, 'buffer' => 30, 'items' => [['Coverage', '3 hours'], ['Edited photos', '100'], ['Online gallery']]],
                    ['name' => 'Civil Wedding', 'desc' => 'Intimate civil ceremony and lunch.', 'price' => 15000, 'minutes' => 240, 'buffer' => 30, 'items' => [['Coverage', '4 hours'], ['Edited photos', '150'], ['Highlight reel']]],
                    ['name' => 'Wedding Day Coverage', 'desc' => 'Start-time-only coverage from prep to reception. No fixed end time.', 'price' => 35000, 'minutes' => null, 'mode' => 'open', 'items' => [['Coverage', 'Prep to reception'], ['Edited photos', '400+'], ['Second shooter'], ['Wedding album']]],
                    ['name' => 'Grand Wedding (Multi-Day)', 'desc' => 'Prenup, ceremony and reception under one booking. Each day has its own schedule.', 'price' => 68000, 'minutes' => null, 'mode' => 'open', 'multi' => true, 'max' => 5, 'items' => [['Coverage', 'Up to 5 schedules'], ['Photo + video team'], ['Two albums']]],
                    ['name' => 'Debut Celebration', 'desc' => 'Debut coverage with an open end time.', 'price' => 22000, 'minutes' => 480, 'mode' => 'open', 'items' => [['Typical length', '~8 hours'], ['Edited photos', '250'], ['Slideshow video']]],
                    ['name' => 'Destination Wedding (Coming Soon)', 'desc' => 'Still being priced.', 'price' => 95000, 'minutes' => null, 'mode' => 'open', 'status' => 'draft', 'items' => [['Travel and stay', 'Negotiated']]],
                    ['name' => 'Wedding 2025 Promo', 'desc' => 'Last year\'s promo.', 'price' => 28000, 'minutes' => 480, 'buffer' => 30, 'status' => 'archived', 'items' => [['Coverage', '8 hours']]],
                ],
                'addons' => [
                    ['Second Photographer', 'A second shooter for angles you would miss.', 6000, 'active'],
                    ['Drone Aerial Coverage', 'Aerial shots of the venue and procession.', 4500, 'active'],
                    ['Same-Day Edit Video', 'A short video shown at the reception.', 8000, 'active'],
                    ['Photo Booth', 'Unlimited prints for 3 hours.', 5500, 'active'],
                    ['Extra Hour Coverage', 'One more hour of coverage.', 2500, 'active'],
                    ['Printed Album (30 pages)', 'Hardbound, layout included.', 7500, 'active'],
                    ['Framed Enlargement (16x24)', 'One framed print of your favorite.', 3000, 'active'],
                    ['Live Streaming', 'Stream the ceremony to family abroad.', 9000, 'active'],
                    ['Rush Delivery (14 days)', 'Edited photos in two weeks.', 3500, 'active'],
                    ['Photo Magnet Favors', 'Discontinued.', 1800, 'archived'],
                ],
                'custom' => [
                    'model' => 'hourly', 'rate' => 2200, 'min' => 2, 'max' => 12, 'buffer' => 30,
                    'multi' => true, 'max_sessions' => 4,
                    'tiers' => [
                        'Edited Photos' => [['100 Edited Photos', 0, null], ['200 Edited Photos', 1500, null], ['350 Edited Photos', 3500, null]],
                        'Delivery Speed' => [['Standard (30 days)', 0, null], ['Rush (14 days)', 1500, null], ['Express (7 days)', 3000, null]],
                    ],
                    'extras' => [['RAW Files', 2500], ['Extra Photographer', 4000], ['Drone Coverage', 3500], ['Same-Day Highlights', 6000]],
                ],
            ],

            // Freelancer. Prenup / portrait: short timed sessions, fixed-price custom.
            [
                'name' => 'Frederick Robelas', 'slug' => 'frederick-robelas',
                'slot_interval' => 30, 'hours' => $monSat('09:00', '19:00'),
                'packages' => [
                    ['name' => 'Corporate Headshots', 'desc' => 'Quick professional headshots.', 'price' => 1800, 'minutes' => 45, 'buffer' => 15, 'items' => [['Looks', '2'], ['Retouched photos', '3']]],
                    ['name' => 'Portrait Session', 'desc' => 'One person, 90 minutes, studio or outdoor.', 'price' => 4000, 'minutes' => 90, 'buffer' => 15, 'items' => [['Outfits', '2'], ['Edited photos', '25']]],
                    ['name' => 'Prenup Session', 'desc' => 'Outdoor fine-art prenup, natural light.', 'price' => 8000, 'minutes' => 180, 'buffer' => 20, 'items' => [['Coverage', '3 hours'], ['Edited photos', '60'], ['Online gallery']]],
                    ['name' => 'Couple Session', 'desc' => 'Ninety minutes for two.', 'price' => 3800, 'minutes' => 90, 'buffer' => 15, 'items' => [['Edited photos', '30']]],
                    ['name' => 'Model Portfolio', 'desc' => 'Build a portfolio for agencies.', 'price' => 6500, 'minutes' => 180, 'buffer' => 30, 'items' => [['Looks', '4'], ['Edited photos', '40'], ['Digital comp card']]],
                    ['name' => 'Fashion Editorial', 'desc' => 'Half-day concept shoot.', 'price' => 12000, 'minutes' => 240, 'buffer' => 30, 'items' => [['Creative direction'], ['Edited photos', '60']]],
                    ['name' => 'Private Boudoir Session', 'desc' => 'Not public yet.', 'price' => 9000, 'minutes' => 60, 'status' => 'draft', 'items' => [['Private studio']]],
                ],
                'addons' => [
                    ['Extra Outfit', 'One more look.', 500, 'active'],
                    ['Makeup Artist', 'Professional makeup.', 1500, 'active'],
                    ['Hair Stylist', 'Styling for the shoot.', 1200, 'active'],
                    ['Extra 10 Edited Photos', 'More retouched images.', 800, 'active'],
                    ['Printed 8R Set (5 pcs)', 'Delivered prints.', 700, 'active'],
                    ['Rush 24-Hour Delivery', 'Photos the next day.', 1000, 'active'],
                    ['Additional Location', 'A second spot on the same day.', 2000, 'active'],
                ],
                'custom' => [
                    'model' => 'fixed', 'base_fee' => 3000, 'buffer' => 15,
                    'tiers' => [
                        'Coverage Duration' => [['1 Hour', 0, 60], ['2 Hours', 1500, 120], ['3 Hours', 3000, 180]],
                        'Edited Photos' => [['15 Photos', 0, null], ['30 Photos', 1200, null], ['60 Photos', 2500, null]],
                    ],
                    'extras' => [['RAW Files', 1500], ['Location Scouting', 800]],
                ],
            ],

            // Studio-style events company. Per-day custom pricing.
            [
                'name' => 'Eventscape Photography', 'slug' => 'eventscape-photography',
                'slot_interval' => 60, 'hours' => $allWeek('06:00', '23:00'),
                'packages' => [
                    ['name' => 'Christening Coverage', 'desc' => 'Church and reception.', 'price' => 9000, 'minutes' => 180, 'buffer' => 30, 'items' => [['Coverage', '3 hours'], ['Edited photos', '120']]],
                    ['name' => 'Birthday Party', 'desc' => 'Four hours of party coverage.', 'price' => 12000, 'minutes' => 240, 'buffer' => 30, 'items' => [['Edited photos', '200']]],
                    ['name' => 'Corporate Half Day', 'desc' => 'Seminars and meetings.', 'price' => 18000, 'minutes' => 240, 'buffer' => 30, 'items' => [['Edited photos', '150'], ['Same-day preview']]],
                    ['name' => 'Corporate Full Day', 'desc' => 'Eight hours, fixed.', 'price' => 32000, 'minutes' => 480, 'buffer' => 60, 'items' => [['Edited photos', '400']]],
                    ['name' => 'Conference (Multi-Day)', 'desc' => 'Multiple days, start time only.', 'price' => 60000, 'minutes' => null, 'mode' => 'open', 'multi' => true, 'max' => 4, 'items' => [['Team of 2 photographers']]],
                    ['name' => 'Product Launch', 'desc' => 'Open-ended launch event coverage.', 'price' => 25000, 'minutes' => null, 'mode' => 'open', 'items' => [['Edited photos', '250'], ['Highlights video']]],
                    ['name' => 'Gala Dinner 2027', 'desc' => 'Planned for next year.', 'price' => 40000, 'minutes' => null, 'mode' => 'open', 'status' => 'draft', 'items' => [['TBD']]],
                ],
                'addons' => [
                    ['Photo Booth (3 hrs)', 'Prints with custom frame.', 5500, 'active'],
                    ['Drone Footage', 'Aerial event shots.', 4000, 'active'],
                    ['Highlights Video', 'Two-minute recap.', 6500, 'active'],
                    ['Extra Photographer', 'Second shooter.', 5000, 'active'],
                    ['Live Photo Display', 'Photos on a screen during the event.', 7000, 'active'],
                    ['On-Site Printing', 'Prints handed out on the day.', 4500, 'active'],
                    ['Old Backdrop Rental', 'Retired.', 1500, 'archived'],
                ],
                'custom' => [
                    'model' => 'per_day', 'rate' => 15000, 'coverage_hours' => 8, 'buffer' => 60,
                    'multi' => true, 'max_sessions' => 5,
                    'tiers' => [
                        'Deliverables' => [['Standard Gallery', 0, null], ['Gallery + Highlights Video', 4000, null], ['Gallery + Video + Album', 9000, null]],
                    ],
                    'extras' => [['Extra Photographer', 5000], ['Drone', 4000], ['Same-Day Preview', 3000]],
                ],
            ],

            // Freelancer. Newborn / family / kids: multi-session timed package, fixed custom.
            [
                'name' => 'Aurora Studios', 'slug' => 'aurora-studios',
                'slot_interval' => 30, 'hours' => $monSat('08:00', '17:00'),
                'packages' => [
                    ['name' => 'Family Portrait', 'desc' => 'Ninety minutes, up to 6 people.', 'price' => 4500, 'minutes' => 90, 'buffer' => 30, 'items' => [['Edited photos', '40']]],
                    ['name' => 'Maternity Session', 'desc' => 'Studio or outdoor.', 'price' => 5000, 'minutes' => 90, 'buffer' => 30, 'items' => [['Outfits', '3'], ['Edited photos', '35']]],
                    ['name' => 'Newborn Session', 'desc' => 'Slow, safe and patient.', 'price' => 6500, 'minutes' => 120, 'buffer' => 45, 'items' => [['Props and wraps'], ['Edited photos', '30']]],
                    ['name' => 'Smash Cake Birthday', 'desc' => 'First birthday mini party.', 'price' => 5500, 'minutes' => 120, 'buffer' => 30, 'items' => [['Cake and props setup']]],
                    ['name' => 'Milestone Year Package', 'desc' => 'Up to four short sessions across the first year. Same price however many you use.', 'price' => 12000, 'minutes' => 90, 'buffer' => 30, 'multi' => true, 'max' => 4, 'items' => [['Sessions', 'Up to 4'], ['Edited photos', '25 per session']]],
                    ['name' => 'Extended Family Reunion', 'desc' => 'Big group, flexible length.', 'price' => 15000, 'minutes' => null, 'mode' => 'open', 'items' => [['Group photos'], ['Candid coverage']]],
                    ['name' => 'Baby Bump to Baby (Coming Soon)', 'desc' => 'Bundle in development.', 'price' => 16000, 'minutes' => 90, 'status' => 'draft', 'items' => [['TBD']]],
                ],
                'addons' => [
                    ['Outfit Change', 'More time and looks.', 800, 'active'],
                    ['Printed Photo Book', '20-page photo book.', 3500, 'active'],
                    ['Digital Birth Announcement', 'Designed card.', 700, 'active'],
                    ['Framed Print (11x14)', 'One framed favorite.', 1800, 'active'],
                    ['Extra 15 Edited Photos', 'More images.', 1000, 'active'],
                    ['Printed Album', 'Hardbound family album.', 4500, 'active'],
                ],
                'custom' => [
                    'model' => 'fixed', 'base_fee' => 4000, 'buffer' => 30,
                    'tiers' => [
                        'Coverage Duration' => [['1 Hour', 0, 60], ['90 Minutes', 800, 90], ['2 Hours', 1600, 120]],
                        'Location' => [['Studio', 0, null], ['Client Home', 500, null], ['Outdoor Park', 800, null]],
                    ],
                    'extras' => [['Extra Family Member', 500]],
                ],
            ],

            // Freelancer. Graduation / christening / corporate. Per-person custom pricing.
            [
                'name' => 'Joesol Photography', 'slug' => 'joesol-photography',
                'slot_interval' => 30,
                'hours' => [[1, '08:00', '18:00'], [2, '08:00', '18:00'], [3, '08:00', '18:00'], [4, '08:00', '18:00'], [5, '08:00', '18:00'], [6, '06:00', '20:00'], [0, '06:00', '20:00']],
                'packages' => [
                    ['name' => 'Solo Grad Photos', 'desc' => 'One graduate, one hour.', 'price' => 2200, 'minutes' => 60, 'buffer' => 15, 'items' => [['Edited photos', '20']]],
                    ['name' => 'Graduation Shoot', 'desc' => 'Clean, well-lit graduation session.', 'price' => 3500, 'minutes' => 120, 'buffer' => 15, 'items' => [['Edited photos', '50'], ['Online gallery']]],
                    ['name' => 'Barkada Grad Shoot (up to 5)', 'desc' => 'Group of friends, ninety minutes.', 'price' => 5000, 'minutes' => 90, 'buffer' => 15, 'items' => [['Edited photos', '50']]],
                    ['name' => 'Graduation Ceremony Coverage', 'desc' => 'Ceremony plus family photos.', 'price' => 9000, 'minutes' => 300, 'buffer' => 30, 'items' => [['Edited photos', '200']]],
                    ['name' => 'Grad Weekend (Rehearsal + Ceremony)', 'desc' => 'Two days under one booking.', 'price' => 12000, 'minutes' => 240, 'buffer' => 30, 'multi' => true, 'max' => 3, 'items' => [['Sessions', 'Up to 3']]],
                    ['name' => 'Corporate Event Coverage', 'desc' => 'Dependable coverage, start time only.', 'price' => 15000, 'minutes' => null, 'mode' => 'open', 'items' => [['Edited photos', '150']]],
                    ['name' => 'Grad Season 2024', 'desc' => 'Past promo.', 'price' => 2000, 'minutes' => 60, 'status' => 'archived', 'items' => [['Edited photos', '15']]],
                ],
                'addons' => [
                    ['Cap and Gown Coordination', 'We arrange it for you.', 1200, 'active'],
                    ['Printed Photo Set (10 pcs)', 'Delivered prints.', 1500, 'active'],
                    ['Extra Location', 'Second campus spot.', 800, 'active'],
                    ['Rush 48-Hour Delivery', 'Photos in two days.', 1200, 'active'],
                    ['Drone Coverage', 'Aerial group shot.', 3000, 'active'],
                    ['Video Highlights', 'Short recap video.', 2500, 'active'],
                ],
                'custom' => [
                    'model' => 'per_person', 'rate' => 1200, 'coverage_hours' => 2, 'max_people' => 20, 'buffer' => 15,
                    'multi' => true, 'max_sessions' => 3,
                    'tiers' => [
                        'Edited Photos per Person' => [['10 Photos', 0, null], ['20 Photos', 500, null]],
                    ],
                    'extras' => [['RAW Files', 1500], ['Group Photo Bonus', 800]],
                ],
            ],

            // Freelancer. Birthdays / debuts / portraits. Hourly custom, 15 sessions allowed.
            [
                'name' => 'CJ Creatives', 'slug' => 'cj-creatives',
                'slot_interval' => 60, 'hours' => null, // no usual hours: bookable any time
                'packages' => [
                    ['name' => 'Kids Birthday Party', 'desc' => 'Three hours of candid party coverage.', 'price' => 4000, 'minutes' => 180, 'buffer' => 30, 'items' => [['Edited photos', '100'], ['Online gallery']]],
                    ['name' => 'Birthday Coverage', 'desc' => 'Candid coverage for birthday celebrations.', 'price' => 5000, 'minutes' => 180, 'buffer' => 30, 'items' => [['Edited photos', '100']]],
                    ['name' => 'Debut Package', 'desc' => 'Debut coverage, candid and story-driven.', 'price' => 12000, 'minutes' => 300, 'buffer' => 30, 'items' => [['Edited photos', '200'], ['Slideshow video']]],
                    ['name' => 'Debut All-Day', 'desc' => 'Start-time only, prep to after-party.', 'price' => 20000, 'minutes' => null, 'mode' => 'open', 'items' => [['Edited photos', '350'], ['Highlights video']]],
                    ['name' => 'Anniversary Mini Shoot', 'desc' => 'One relaxed hour.', 'price' => 2500, 'minutes' => 60, 'buffer' => 15, 'items' => [['Edited photos', '25']]],
                    ['name' => 'Birthday Series (3 Sessions)', 'desc' => 'Three short shoots across the year.', 'price' => 6500, 'minutes' => 90, 'buffer' => 15, 'multi' => true, 'max' => 3, 'items' => [['Sessions', 'Up to 3']]],
                ],
                'addons' => [
                    ['Extra Hour', 'One more hour.', 1000, 'active'],
                    ['Print Delivery', 'Delivered prints.', 500, 'active'],
                    ['Photo Booth', 'Two hours with props.', 3500, 'active'],
                    ['Same-Day Preview', '20 photos by the end of the event.', 1500, 'active'],
                    ['Extra 20 Photos', 'More edited photos.', 900, 'active'],
                ],
                'custom' => [
                    'model' => 'hourly', 'rate' => 1500, 'min' => 1, 'max' => 24, 'buffer' => 30,
                    'multi' => true, 'max_sessions' => 15,
                    'tiers' => [
                        'Edited Photos' => [['100 Photos', 0, null], ['250 Photos', 1500, null]],
                    ],
                    'extras' => [['RAW Files', 2000], ['Extra Photographer', 3500]],
                ],
            ],

            // Freelancer. Weddings / prenups / engagements. Fixed custom with duration tiers.
            [
                'name' => 'Lens & Soul', 'slug' => 'lens-and-soul',
                'slot_interval' => 30, 'hours' => $monSat('09:00', '20:00'),
                'packages' => [
                    ['name' => 'Engagement Shoot', 'desc' => 'Relaxed two-hour couple session.', 'price' => 6000, 'minutes' => 120, 'buffer' => 20, 'items' => [['Edited photos', '50']]],
                    ['name' => 'Prenup Essentials', 'desc' => 'One location, half day.', 'price' => 9000, 'minutes' => 180, 'buffer' => 30, 'items' => [['Edited photos', '80'], ['Online gallery']]],
                    ['name' => 'Bridal Preparation', 'desc' => 'Getting-ready coverage.', 'price' => 7000, 'minutes' => 180, 'buffer' => 30, 'items' => [['Edited photos', '100']]],
                    ['name' => 'Church Wedding (Ceremony Only)', 'desc' => 'Ceremony coverage.', 'price' => 12000, 'minutes' => 180, 'buffer' => 30, 'items' => [['Edited photos', '150']]],
                    ['name' => 'Full Wedding Story', 'desc' => 'Start-time only, prep to reception.', 'price' => 30000, 'minutes' => null, 'mode' => 'open', 'items' => [['Edited photos', '350'], ['Highlights video'], ['Album']]],
                    ['name' => 'Wedding Weekend', 'desc' => 'Prenup, ceremony and reception, each with its own schedule.', 'price' => 45000, 'minutes' => null, 'mode' => 'open', 'multi' => true, 'max' => 3, 'items' => [['Sessions', 'Up to 3']]],
                    ['name' => 'Elopement (Coming Soon)', 'desc' => 'Still being priced.', 'price' => 20000, 'minutes' => null, 'mode' => 'open', 'status' => 'draft', 'items' => [['TBD']]],
                ],
                'addons' => [
                    ['Cinematic Same-Day Edit', 'Shown at the reception.', 7000, 'active'],
                    ['Second Shooter', 'Extra angles.', 4500, 'active'],
                    ['Printed Album', '30-page hardbound.', 6500, 'active'],
                    ['Drone Coverage', 'Aerial venue shots.', 3500, 'active'],
                    ['Engagement Guest Book', 'Photo guest book.', 2500, 'active'],
                    ['Rush 7-Day Delivery', 'Photos in a week.', 3000, 'active'],
                ],
                'custom' => [
                    'model' => 'fixed', 'base_fee' => 5000, 'buffer' => 30,
                    'tiers' => [
                        'Coverage Duration' => [['2 Hours', 0, 120], ['4 Hours', 3500, 240], ['6 Hours', 6500, 360]],
                        'Edited Photos' => [['80 Photos', 0, null], ['160 Photos', 2000, null], ['300 Photos', 4500, null]],
                    ],
                    'extras' => [['Drone Coverage', 3500], ['Bridal Prep Add-on', 2500]],
                ],
            ],

            // Studio. Weddings / corporate / videography. Hourly custom.
            [
                'name' => 'HHProduction', 'slug' => 'hhproduction',
                'slot_interval' => 60, 'hours' => $allWeek('06:00', '22:00'),
                'packages' => [
                    ['name' => 'Wedding Cinematic Package', 'desc' => 'Ten hours with a same-day-edit video.', 'price' => 35000, 'minutes' => 600, 'buffer' => 60, 'items' => [['Coverage', '10 hours'], ['Edited photos', '500'], ['Same-day-edit video']]],
                    ['name' => 'Wedding (Start Time Only)', 'desc' => 'No fixed end time.', 'price' => 42000, 'minutes' => null, 'mode' => 'open', 'items' => [['Photo + video team'], ['Edited photos', '500+']]],
                    ['name' => 'Corporate Event Package', 'desc' => 'Half-day corporate coverage.', 'price' => 15000, 'minutes' => 240, 'buffer' => 30, 'items' => [['Edited photos', '200'], ['Highlights reel']]],
                    ['name' => 'Debut Full Service', 'desc' => 'Photo and video for debuts.', 'price' => 25000, 'minutes' => 480, 'buffer' => 45, 'items' => [['Edited photos', '300'], ['Video']]],
                    ['name' => 'Corporate Video Shoot', 'desc' => 'Promotional video, one day.', 'price' => 30000, 'minutes' => 480, 'buffer' => 60, 'items' => [['Edited video', '2 min']]],
                    ['name' => 'Multi-Day Festival Coverage', 'desc' => 'Start time only, up to 4 days.', 'price' => 70000, 'minutes' => null, 'mode' => 'open', 'multi' => true, 'max' => 4, 'items' => [['Team of 3']]],
                    ['name' => 'Holiday Promo 2025', 'desc' => 'Past promo.', 'price' => 18000, 'minutes' => 300, 'status' => 'archived', 'items' => [['Coverage', '5 hours']]],
                ],
                'addons' => [
                    ['Drone Aerial', 'Aerial coverage.', 4500, 'active'],
                    ['Same-Day Edit', 'Reception video.', 8000, 'active'],
                    ['Second Shooter', 'Extra angles.', 5000, 'active'],
                    ['Live Streaming', 'Multi-camera stream.', 9000, 'active'],
                    ['Extra Hour', 'One more hour.', 2500, 'active'],
                    ['Photo Wall Display', 'Live photos on screen.', 6000, 'active'],
                ],
                'custom' => [
                    'model' => 'hourly', 'rate' => 3000, 'min' => 3, 'max' => 14, 'buffer' => 45,
                    'multi' => true, 'max_sessions' => 6,
                    'tiers' => [
                        'Deliverables' => [['Photos Only', 0, null], ['Photos + Highlights Video', 5000, null], ['Photos + Full Film', 12000, null]],
                    ],
                    'extras' => [['Drone', 4500], ['Extra Photographer', 5000], ['Same-Day Edit', 8000]],
                ],
            ],

            // Studio. Weddings / portraits / family. Per-day custom pricing.
            [
                'name' => 'KAP Studio', 'slug' => 'kap-studio',
                'slot_interval' => 60, 'hours' => $monSat('08:00', '20:00'),
                'packages' => [
                    ['name' => 'Elegant Wedding Package', 'desc' => 'Full-day fine-art wedding coverage.', 'price' => 25000, 'minutes' => 480, 'buffer' => 45, 'items' => [['Coverage', '8 hours'], ['Edited photos', '350'], ['Printed album']]],
                    ['name' => 'Portrait Session', 'desc' => 'Studio or outdoor, individuals or couples.', 'price' => 8000, 'minutes' => 90, 'buffer' => 15, 'items' => [['Edited photos', '30']]],
                    ['name' => 'Debut Portrait Package', 'desc' => 'Studio debut portraits.', 'price' => 12000, 'minutes' => 180, 'buffer' => 30, 'items' => [['Looks', '3'], ['Edited photos', '40']]],
                    ['name' => 'Family Heirloom Session', 'desc' => 'Extended family, studio.', 'price' => 9500, 'minutes' => 120, 'buffer' => 30, 'items' => [['Edited photos', '50'], ['Framed print']]],
                    ['name' => 'Wedding (Start Time Only)', 'desc' => 'Open-ended coverage.', 'price' => 32000, 'minutes' => null, 'mode' => 'open', 'items' => [['Edited photos', '400']]],
                    ['name' => 'Two-Day Wedding', 'desc' => 'Prenup day and wedding day.', 'price' => 42000, 'minutes' => null, 'mode' => 'open', 'multi' => true, 'max' => 2, 'items' => [['Sessions', '2']]],
                ],
                'addons' => [
                    ['Printed Photo Album', 'Layout included.', 6500, 'active'],
                    ['Framed Enlargement', '16x24 framed print.', 3000, 'active'],
                    ['Extra Hour', 'One more hour.', 2200, 'active'],
                    ['Makeup Artist', 'Bridal or portrait makeup.', 2500, 'active'],
                    ['Photo Booth', 'Two hours with prints.', 4500, 'active'],
                ],
                'custom' => [
                    'model' => 'per_day', 'rate' => 14000, 'coverage_hours' => 8, 'buffer' => 45,
                    'multi' => true, 'max_sessions' => 3,
                    'tiers' => [
                        'Deliverables' => [['Digital Gallery', 0, null], ['Gallery + Album', 6000, null]],
                    ],
                    'extras' => [['Second Shooter', 4500], ['Drone', 3500]],
                ],
            ],

            // Studio. Weddings / prenups / family. Hourly custom.
            [
                'name' => 'Amaras Studio', 'slug' => 'amaras-studio',
                'slot_interval' => 30, 'hours' => $allWeek('08:00', '21:00'),
                'packages' => [
                    ['name' => 'Romantic Wedding Package', 'desc' => 'Full-day warm, romantic-style wedding.', 'price' => 28000, 'minutes' => 480, 'buffer' => 45, 'items' => [['Coverage', '8 hours'], ['Edited photos', '300'], ['Online gallery']]],
                    ['name' => 'Family Portrait Session', 'desc' => 'Outdoor or in-studio family portraits.', 'price' => 9000, 'minutes' => 120, 'buffer' => 15, 'items' => [['Edited photos', '40']]],
                    ['name' => 'Prenup Golden Hour', 'desc' => 'Sunset prenup, one location.', 'price' => 10000, 'minutes' => 150, 'buffer' => 20, 'items' => [['Edited photos', '70']]],
                    ['name' => 'Wedding (Start Time Only)', 'desc' => 'No fixed end time.', 'price' => 34000, 'minutes' => null, 'mode' => 'open', 'items' => [['Edited photos', '400'], ['Highlight reel']]],
                    ['name' => 'Wedding Trilogy', 'desc' => 'Prenup, ceremony and reception schedules.', 'price' => 52000, 'minutes' => null, 'mode' => 'open', 'multi' => true, 'max' => 3, 'items' => [['Sessions', 'Up to 3']]],
                    ['name' => 'Baptism Day', 'desc' => 'Church and lunch.', 'price' => 7500, 'minutes' => 180, 'buffer' => 30, 'items' => [['Edited photos', '100']]],
                    ['name' => 'Valentine Couple Special', 'desc' => 'Seasonal, coming next year.', 'price' => 3500, 'minutes' => 60, 'status' => 'draft', 'items' => [['Edited photos', '20']]],
                ],
                'addons' => [
                    ['Second Photographer', 'Extra angles.', 5000, 'active'],
                    ['Drone Aerial', 'Aerial coverage.', 4000, 'active'],
                    ['Printed Album (40 pages)', 'Hardbound.', 8000, 'active'],
                    ['Extra Hour', 'One more hour.', 2000, 'active'],
                    ['Same-Day Slideshow', 'Shown at the reception.', 5000, 'active'],
                    ['Holiday Card Set', 'Retired.', 1200, 'archived'],
                ],
                'custom' => [
                    'model' => 'hourly', 'rate' => 2000, 'min' => 2, 'max' => 10, 'buffer' => 30,
                    'multi' => true, 'max_sessions' => 3,
                    'tiers' => [
                        'Edited Photos' => [['100 Photos', 0, null], ['200 Photos', 1800, null], ['350 Photos', 3800, null]],
                    ],
                    'extras' => [['RAW Files', 2500], ['Extra Photographer', 4500]],
                ],
            ],
        ];
    }
}