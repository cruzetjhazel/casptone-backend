<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PhotographerPublicProfileResource;
use App\Models\User;
use App\Models\FavoritePhotographer;
use App\Models\ProfileView;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use App\Services\Photographer\BookabilityService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPhotographerController extends Controller
{
    use ApiResponses;

    public function index(\Illuminate\Http\Request $request, BookabilityService $bookabilityService)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $photographers = User::query()
            ->where('account_type', \App\Enums\AccountType::Photographer)
            ->whereHas('photographerApplication', fn ($q) =>
                $q->where('status', \App\Enums\PhotographerApplicationStatus::Approved)
            )
            ->when($request->filled('date'), function ($query) use ($request) {
                $date = $request->query('date');
                $query->whereDoesntHave('blockedDates', fn ($q) =>
                    $q->where('date', $date)->whereNull('start_time')
                );
            })
            ->withCount([
                'favoritePhotographers as favorites_count',
                'bookingsAsPhotographer as bookings_count',
                'reviews as reviews_count',
            ])
            ->withAvg('reviews', 'rating')
            ->with([
                'photographerProfile',
                'photographerApplication',
                'portfolioImages',
                'packages',
                'addOns',
                'paymentConfig',
                'reviews.client',
            ])
            ->get()
            ->filter(fn ($photographer) => $bookabilityService->isBookable($photographer))
            ->values();

        return $this->success(PhotographerPublicProfileResource::collection($photographers));
    }

    public function show(Request $request, User $user)
    {
        if (! $user->isPhotographer() || ! $user->isApprovedPhotographer()) {
            throw new NotFoundHttpException('Photographer profile not found.');
        }

        $this->logProfileView($request, $user);

        $user->load([
            'photographerProfile',
            'photographerApplication',
            'portfolioImages',
            'packages',
            'addOns',
            'reviews.client',
        ]);
        $user->loadCount('reviews');
        $user->loadAvg('reviews', 'rating');

        return $this->success(new PhotographerPublicProfileResource($user));
    }

    /**
     * Records a profile view, deduped to once per visitor per day (§ dedupe
     * key = photographer + viewer_hash + viewed_on, unique-constrained).
     * Skips logging when a photographer views their own profile.
     */
    private function logProfileView(Request $request, User $photographer): void
    {
        $viewer = $request->user();

        if ($viewer && $viewer->id === $photographer->id) {
            return;
        }

        $identity = $viewer
            ? "user:{$viewer->id}"
            : 'ip:'.$request->ip().'|ua:'.substr((string) $request->userAgent(), 0, 150);

        ProfileView::firstOrCreate([
            'photographer_id' => $photographer->id,
            'viewer_hash' => hash('sha256', $identity),
            'viewed_on' => now()->toDateString(),
        ]);
    }

    public function featured(BookabilityService $bookabilityService)
    {
        $photographers = User::query()
            ->where('account_type', \App\Enums\AccountType::Photographer)
            ->whereHas('photographerApplication', fn ($q) =>
                $q->where('status', \App\Enums\PhotographerApplicationStatus::Approved)
            )
            ->with([
                'photographerProfile',
                'photographerApplication',
                'portfolioImages',
                'packages',
                'addOns',
                'paymentConfig',
                'reviews.client',
            ])
            ->addSelect(['favorites_count' => FavoritePhotographer::selectRaw('count(*)')
                ->whereColumn('photographer_id', 'users.id')
            ])
            ->withCount(['reviews as reviews_count'])
            ->withAvg('reviews', 'rating')
            ->orderByDesc('favorites_count')
            ->get()
            ->filter(fn ($photographer) => $bookabilityService->isBookable($photographer))
            ->take(5)
            ->values();

        return $this->success(PhotographerPublicProfileResource::collection($photographers));
    }
}