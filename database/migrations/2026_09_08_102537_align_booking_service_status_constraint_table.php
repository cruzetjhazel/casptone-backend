<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_booking_service_status');
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT chk_booking_service_status
                 CHECK (service_status IS NULL OR service_status IN
                 ('upcoming','event_day','editing','delivered','completed'))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_booking_service_status');
            DB::statement(
                "ALTER TABLE bookings ADD CONSTRAINT chk_booking_service_status
                 CHECK (service_status IS NULL OR service_status IN
                 ('event_day','editing','delivered'))"
            );
        }
    }
};