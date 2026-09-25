<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CHECK chk_booking_location_type');
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT chk_booking_location_type
                 CHECK (location_type IN ('studio','client_location','outdoor_location','outside_bicol','other'))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CHECK chk_booking_location_type');
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT chk_booking_location_type
                 CHECK (location_type IN ('studio','client_location','outdoor_location','other'))"
            );
        }
    }
};