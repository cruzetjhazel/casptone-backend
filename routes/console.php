<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Actions\Booking\RunServiceProgressTransitionsAction;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('archive:purge --days=90')->daily();



// Now that config/sanctum.php sets a real token expiration, this clears out
// the expired rows instead of letting personal_access_tokens grow forever.
// Sanctum already rejects expired tokens on every request regardless of
// pruning — this is just housekeeping, not itself a security control.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('bookings:expire-stale')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => app(RunServiceProgressTransitionsAction::class)->execute())
    ->everyMinute()
    ->name('run-service-progress-transitions')
    ->withoutOverlapping();