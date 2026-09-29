<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';

        if ($mysql) {
            DB::statement('ALTER TABLE packages DROP CONSTRAINT chk_package_duration');
        }

        Schema::table('packages', function (Blueprint $table) {
            // Pricing / package information. Null = no stated length.
            $table->unsignedInteger('duration_minutes')->nullable()->change();
            // Scheduling: 'timed' reserves duration + buffer; 'open' = start time only.
            $table->string('schedule_mode', 16)->default('timed')->after('buffer_minutes');
        });

        if ($mysql) {
            DB::statement('ALTER TABLE packages ADD CONSTRAINT chk_package_duration CHECK (duration_minutes IS NULL OR duration_minutes > 0)');
            DB::statement("ALTER TABLE packages ADD CONSTRAINT chk_package_schedule_mode CHECK (schedule_mode IN ('timed','open'))");
            DB::statement("ALTER TABLE packages ADD CONSTRAINT chk_package_timed_needs_duration CHECK (schedule_mode = 'open' OR duration_minutes IS NOT NULL)");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE packages DROP CONSTRAINT chk_package_timed_needs_duration');
            DB::statement('ALTER TABLE packages DROP CONSTRAINT chk_package_schedule_mode');
        }

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('schedule_mode');
        });
        // duration_minutes stays nullable on rollback (same policy as the
        // optional-duration migration for bookings).
    }
};