<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_booking_status');
            DB::statement("
                ALTER TABLE bookings
                ADD CONSTRAINT chk_booking_status
                CHECK (`status` in ('pending','accepted','confirmed','rejected','cancelled','completed','expired','no_show'))
            ");
        }

        // No-show reports an admin already confirmed were stored as Cancelled.
        if (Schema::hasColumn('bookings', 'non_completion_review_status')) {
            DB::table('bookings')
                ->where('status', 'cancelled')
                ->where('non_completion_review_status', 'upheld')
                ->update(['status' => 'no_show']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'non_completion_review_status')) {
            DB::table('bookings')->where('status', 'no_show')->update(['status' => 'cancelled']);
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_booking_status');
            DB::statement("
                ALTER TABLE bookings
                ADD CONSTRAINT chk_booking_status
                CHECK (`status` in ('pending','accepted','confirmed','rejected','cancelled','completed','expired'))
            ");
        }
    }
};