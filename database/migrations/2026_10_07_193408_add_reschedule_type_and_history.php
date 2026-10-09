<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // standard | weather | venue | agreed — the type of the current reschedule request.
            $table->string('reschedule_type', 20)->nullable();
        });

        Schema::create('booking_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('type', 20)->default('standard');
            $table->date('original_event_date');
            $table->time('original_start_time');
            $table->time('original_end_time')->nullable();
            $table->date('new_event_date');
            $table->time('new_start_time');
            $table->time('new_end_time')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reschedules');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('reschedule_type');
        });
    }
};