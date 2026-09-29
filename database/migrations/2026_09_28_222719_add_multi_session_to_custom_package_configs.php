<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->boolean('allows_multiple_sessions')->default(false)->after('max_hours');
            $table->unsignedSmallInteger('max_sessions')->nullable()->after('allows_multiple_sessions');
            // 'once' | 'per_schedule' — the photographer must choose explicitly; null = not set.
            $table->string('base_fee_mode', 20)->nullable()->after('max_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->dropColumn(['allows_multiple_sessions', 'max_sessions', 'base_fee_mode']);
        });
    }
};