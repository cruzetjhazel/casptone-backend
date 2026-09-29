<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPhotographerOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function bookable(int $publishedPackages = 1): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();
        PhotographerProfile::factory()->for($user)->complete()->create();
        PhotographerPortfolioImage::factory()->for($user)->count(6)->create();
        Package::factory()->for($user)->published()->count($publishedPackages)->create();
        PhotographerPaymentConfig::factory()->for($user)->create();

        return $user;
    }

    private function ids(): array
    {
        return $this->getJson('/api/photographers')->assertOk()->json('data.*.id');
    }

    public function test_recommended_order_is_stable_and_ties_break_by_lowest_id(): void
    {
        $a = $this->bookable();
        $b = $this->bookable();
        $c = $this->bookable();

        $expected = [$a->id, $b->id, $c->id];

        $this->assertSame($expected, $this->ids());
        $this->assertSame($expected, $this->ids()); // same order on every request
        $this->assertSame($expected, $this->ids());
    }

    public function test_photographer_with_more_published_packages_ranks_first(): void
    {
        $a = $this->bookable(1);
        $b = $this->bookable(3);
        $c = $this->bookable(2);

        $this->assertSame([$b->id, $c->id, $a->id], $this->ids());
    }

    public function test_photographers_who_are_not_bookable_are_excluded(): void
    {
        $bookable = $this->bookable();

        // Approved but no published package, so not bookable.
        $notBookable = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($notBookable)->approved()->create();
        PhotographerProfile::factory()->for($notBookable)->complete()->create();
        PhotographerPortfolioImage::factory()->for($notBookable)->count(6)->create();
        PhotographerPaymentConfig::factory()->for($notBookable)->create();

        $this->assertSame([$bookable->id], $this->ids());
    }
}