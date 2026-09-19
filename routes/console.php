<?php

use App\Actions\Booking\ExpireStaleBookingHoldsAction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Actions\Booking\RunServiceProgressTransitionsAction;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('archive:purge --days=90')->daily();

Schedule::call(fn () => app(ExpireStaleBookingHoldsAction::class)->execute())
    ->everyFifteenMinutes()
    ->name('expire-stale-booking-holds')
    ->withoutOverlapping();

// Now that config/sanctum.php sets a real token expiration, this clears out
// the expired rows instead of letting personal_access_tokens grow forever.
// Sanctum already rejects expired tokens on every request regardless of
// pruning — this is just housekeeping, not itself a security control.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

