<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('non_completion_reason')->nullable()->after('cancellation_decided_at');
            $table->timestamp('non_completion_reported_at')->nullable()->after('non_completion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['non_completion_reason', 'non_completion_reported_at']);
        });
    }
};