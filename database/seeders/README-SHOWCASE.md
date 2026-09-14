# Photographer Showcase Seeder

This directory contains the `PhotographerShowcaseSeeder` which seeds 6 professional photographers with complete profiles, images, and portfolios.

## Quick Start

```bash
# Run the showcase seeder after initial database setup
php artisan db:seed --class=PhotographerShowcaseSeeder
```

## Features

✅ **6 Pre-configured Photographers:**
- HHProduction (Studio)
- KAP Studio (Studio)
- Amaras Studio (Studio)
- CJ Creatives (Freelancer)
- Joesol Photography (Freelancer)
- Frederick Robelas (Freelancer)

✅ **Complete Profiles:**
- Business names, locations, phone, email
- Professional bios and photography styles
- Services, coverage areas, pricing
- Social links (Facebook, Instagram, website)

✅ **Images:**
- Profile photo (400×400)
- Cover photo (1200×400)
- 6-8 portfolio images (800×600)
- 1-2 archived portfolio images

✅ **All photographers are immediately bookable** and appear on the Explore page.

## Using Custom Images

You can provide your own images for the photographers. The seeder will load them if found, or generate colorful placeholders if not.

### Adding Custom Images

1. Create the `database/seeders/images/` directory:
   ```bash
   mkdir database/seeders/images
   ```

2. Add images with these naming patterns:
   ```
   database/seeders/images/
   ├── hh-production-logo.jpg        # Profile image
   ├── hh-production-cover.jpg       # Cover image
   ├── kap-studio-logo.jpg
   ├── kap-studio-cover.jpg
   ├── amaras-studio-logo.jpg
   ├── amaras-studio-cover.jpg
   ├── cj-creatives-logo.jpg
   ├── cj-creatives-cover.jpg
   ├── joesol-photography-logo.jpg
   ├── joesol-photography-cover.jpg
   ├── frederick-robelas-logo.jpg
   ├── frederick-robelas-cover.jpg
   ```

3. Supported image formats: **jpg, jpeg, png, webp**

4. Recommended sizes:
   - **Logo (Profile):** 400×400 px
   - **Cover:** 1200×400 px

5. Re-run the seeder:
   ```bash
   php artisan db:seed --class=PhotographerShowcaseSeeder
   ```

### Image Storage

- **Source:** `database/seeders/images/`
- **Destination:** `storage/app/public/photographers/showcase/{slug}/`
- **URLs:** `/storage/photographers/showcase/{slug}/{image}`

## Portfolio Images

Portfolio images are automatically generated as colorful placeholders. To provide custom portfolio images, modify the `seedPortfolioImages()` method in `PhotographerShowcaseSeeder.php` to load from your custom directory.

## Running After DemoSeeder

This seeder works independently but complements `DemoSeeder`:

```bash
# Option 1: Run just showcase photographers
php artisan db:seed --class=PhotographerShowcaseSeeder

# Option 2: Run all seeders in order
php artisan migrate:fresh --seed
# This runs both AdminSeeder, DemoSeeder, and PhotographerShowcaseSeeder
```

## Environment

- ✅ Runs in `local` and `testing` environments
- ❌ Will not run in production
- Requires: PHP GD extension (for placeholder generation)

## Troubleshooting

**"GD extension is required"**
- Enable php-gd in your PHP configuration
- Restart Apache/Nginx

**Images not loading on Explore page**
- Ensure `php artisan storage:link` was run
- Check that `public/storage` symlink exists
- Verify images are in `storage/app/public/photographers/showcase/`

**Photographer not appearing on Explore**
- Confirm they are Approved status
- Check that all required fields are filled (bio, style, profile/cover photos)
- Verify they have ≥6 active portfolio images
- Check that GCash payment config exists
