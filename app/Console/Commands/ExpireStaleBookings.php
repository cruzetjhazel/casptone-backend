<?php

namespace App\Console\Commands;

use App\Actions\Booking\ExpireStaleBookingHoldsAction;
use Illuminate\Console\Command;

class ExpireStaleBookings extends Command
{
    protected $signature = 'bookings:expire-stale';

    protected $description = 'Expires pending requests the photographer never answered and accepted bookings the client never paid.';

    public function handle(ExpireStaleBookingHoldsAction $action): int
    {
        $count = $action->execute();

        $this->info("Expired {$count} stale booking(s).");

        return self::SUCCESS;
    }
}