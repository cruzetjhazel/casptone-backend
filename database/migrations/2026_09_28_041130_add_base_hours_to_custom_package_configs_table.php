<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sliding-hours pricing: base_fee now covers base_hours of coverage, and each
 * hour beyond that is charged at hourly_rate. Null base_hours keeps the older
 * "hours x hourly_rate, no base fee" behavior for configs saved before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('base_hours')->nullable()->after('base_fee');
        });
    }

    public function down(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->dropColumn('base_hours');
        });
    }
};