<?php

namespace App\Providers;

use App\Models\Booking;
use App\Observers\BookingObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit registration as a fallback for the #[ObservedBy] attribute
        // on the Booking model, which only takes effect on Laravel 11+.
        // Auto-advances service_status to Upcoming once payment settles —
        // see BookingObserver::saved().
        Booking::observe(BookingObserver::class);
        // Laravel's default ResetPassword notification links to a Blade route this
        // API-only backend doesn't have. Point it at the React frontend's actual
        // /reset-password page instead. FRONTEND_URL is read directly via env()
        // rather than through config/app.php since that file wasn't part of this
        // change — set FRONTEND_URL in .env; if you ever run `php artisan
        // config:cache` in production, switch this to a config('app.frontend_url')
        // lookup backed by a config/app.php entry instead, since cached config
        // freezes env() reads.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:8080'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}