<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additional schedule entries for a booking that spans more than one
 * date/activity (e.g. Prenup on Oct 5, Wedding on Oct 20). The booking's
 * own event_date/start_time/end_time columns continue to represent
 * "Schedule 1" exactly as before — this table only holds Schedule 2 and
 * beyond, added via "+ Add Another Schedule" in the booking flow. See
 * Booking::allSchedules() for the combined, ordered view both are read
 * through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            // Free-text activity/event label for this specific schedule
            // entry (e.g. "Prenup", "Reception") — distinct from the
            // booking's overall event_type, which stays booking-level.
            $table->string('label');

            $table->date('event_date');
            $table->time('start_time');
            $table->time('end_time');

            // Manual, client-entered order for display — additional
            // schedules aren't necessarily chronological (a photographer
            // may be asked to shoot a rehearsal after the main event).
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['booking_id', 'sort_order']);
            $table->index('event_date');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE booking_schedules ADD CONSTRAINT chk_booking_schedule_times CHECK (end_time > start_time)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_schedules');
    }
};
