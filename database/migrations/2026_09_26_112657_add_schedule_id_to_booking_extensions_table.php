<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an extension request target one specific schedule entry on a
 * multi-schedule booking (e.g. "extend the Wedding on Oct 20", not the
 * Prenup on Oct 5). Null means "the primary schedule" — i.e. extend the
 * booking's own end_time directly, which is the only case that existed
 * before multi-schedule bookings and remains the common case (a booking
 * with just one schedule has nothing else to pick from).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_extensions', function (Blueprint $table) {
            $table->foreignId('booking_schedule_id')->nullable()->after('booking_id')
                ->constrained('booking_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_extensions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_schedule_id');
        });
    }
};
