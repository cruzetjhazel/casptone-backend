<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday .. 6 = Saturday, matches Carbon::dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['user_id', 'day_of_week']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE booking_hours ADD CONSTRAINT chk_booking_hour_times
                 CHECK (end_time > start_time)'
            );
            DB::statement(
                'ALTER TABLE booking_hours ADD CONSTRAINT chk_booking_hour_day
                 CHECK (day_of_week BETWEEN 0 AND 6)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_hours');
    }
};