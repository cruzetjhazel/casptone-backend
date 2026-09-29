<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supports a schedule whose duration is not yet known — e.g. a fixed
 * package that spans a prenup, wedding prep, ceremony, and reception,
 * where some of those individual schedules don't have a confirmed length
 * yet. A null end_time/duration_minutes means "not yet confirmed":
 * CreateBookingAction skips numeric availability blocking for that one
 * schedule (AvailabilityService::startTimesForPeriods already treats a
 * null end_time conservatively — see the comment there), and the booking
 * is left Pending so the photographer reviews and confirms it manually
 * rather than the system inventing an end time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_booking_times');
            DB::statement('ALTER TABLE booking_schedules DROP CONSTRAINT chk_booking_schedule_times');
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->time('end_time')->nullable()->change();
            $table->unsignedInteger('duration_minutes')->nullable()->after('end_time');
        });

        Schema::table('booking_schedules', function (Blueprint $table) {
            $table->time('end_time')->nullable()->change();
            $table->unsignedInteger('duration_minutes')->nullable()->after('end_time');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT chk_booking_times CHECK (end_time IS NULL OR end_time > start_time)');
            DB::statement('ALTER TABLE booking_schedules ADD CONSTRAINT chk_booking_schedule_times CHECK (end_time IS NULL OR end_time > start_time)');
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
        Schema::table('booking_schedules', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
        // end_time nullability and the CHECK constraints are intentionally
        // left as-is on rollback — re-tightening a column that may already
        // hold NULLs (real TBD-duration bookings) would fail outright.
    }
};