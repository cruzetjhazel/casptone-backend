<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Why the client wants to move the booking (was never saved before).
            $table->text('reschedule_reason')->nullable();

            // "Modify details" requests: what the client wants changed, and the photographer's answer.
            $table->json('modification_changes')->nullable();
            $table->string('modification_decision')->nullable();
            $table->timestamp('modification_decided_at')->nullable();

            // Who ended the booking: client, photographer or admin (null = not cancelled / declined request).
            $table->string('cancelled_by', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'reschedule_reason',
                'modification_changes',
                'modification_decision',
                'modification_decided_at',
                'cancelled_by',
            ]);
        });
    }
};