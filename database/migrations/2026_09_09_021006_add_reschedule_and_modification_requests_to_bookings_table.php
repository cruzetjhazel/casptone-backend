<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('requested_event_date')->nullable()->after('cancellation_decided_at');
            $table->time('requested_start_time')->nullable()->after('requested_event_date');
            $table->timestamp('reschedule_requested_at')->nullable()->after('requested_start_time');
            $table->string('reschedule_decision')->nullable()->after('reschedule_requested_at');
            $table->timestamp('reschedule_decided_at')->nullable()->after('reschedule_decision');

            $table->string('modification_type')->nullable()->after('reschedule_decided_at');
            $table->text('modification_reason')->nullable()->after('modification_type');
            $table->timestamp('modification_requested_at')->nullable()->after('modification_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'requested_event_date', 'requested_start_time', 'reschedule_requested_at',
                'reschedule_decision', 'reschedule_decided_at',
                'modification_type', 'modification_reason', 'modification_requested_at',
            ]);
        });
    }
};