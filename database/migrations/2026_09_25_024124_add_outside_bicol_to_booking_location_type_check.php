<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->dropCheckConstraint('chk_booking_location_type');
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT chk_booking_location_type
                 CHECK (location_type IN ('studio','client_location','outdoor_location','outside_bicol','other'))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->dropCheckConstraint('chk_booking_location_type');
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT chk_booking_location_type
                 CHECK (location_type IN ('studio','client_location','outdoor_location','other'))"
            );
        }
    }

    /**
     * MySQL 8.0.16+ requires "DROP CHECK"; MariaDB (what XAMPP ships)
     * requires "DROP CONSTRAINT" for the same operation — neither engine
     * accepts the other's syntax, and there's no single statement that
     * works on both. Rather than sniff the server version (fragile —
     * version() strings vary by build/distro), just try each syntax in
     * turn and swallow whichever one doesn't apply. If the constraint
     * doesn't exist yet under this name (fresh database), both attempts
     * fail harmlessly and we move on to (re)creating it.
     */
    private function dropCheckConstraint(string $name): void
    {
        foreach (["ALTER TABLE bookings DROP CHECK {$name}", "ALTER TABLE bookings DROP CONSTRAINT {$name}"] as $sql) {
            try {
                DB::statement($sql);

                return;
            } catch (\Throwable $e) {
                // Wrong syntax for this server, or constraint didn't exist
                // under this name — try the next option / give up quietly.
            }
        }
    }
};