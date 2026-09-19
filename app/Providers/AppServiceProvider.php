<?php

namespace App\Providers;

use App\Models\Booking;
use App\Observers\BookingObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Brute-force / credential-stuffing protection for auth endpoints
        // (login, register, forgot-password) — none of these had any rate
        // limiting before. Keyed by IP + submitted email so one attacker
        // can't lock out a real user's email by hammering it from many IPs
        // while still being generous enough for normal typos. Applied via
        // throttle:auth on the routes themselves (see routes/api.php).
        RateLimiter::for('auth', function (Request $request) {
            $emailKey = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($request->ip().'|'.$emailKey),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}