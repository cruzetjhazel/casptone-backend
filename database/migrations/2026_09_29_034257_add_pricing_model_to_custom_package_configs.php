<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->string('pricing_model', 20)->nullable()->after('enabled');
            $table->decimal('unit_rate', 10, 2)->nullable()->after('hourly_rate');
            $table->unsignedSmallInteger('coverage_hours')->nullable()->after('unit_rate');
            $table->unsignedSmallInteger('max_people')->nullable()->after('coverage_hours');
        });

        // Existing photographers keep exactly the pricing they have today.
        DB::table('custom_package_configs')->whereNull('pricing_model')->update([
            'pricing_model' => DB::raw("CASE WHEN hourly_rate IS NOT NULL THEN 'hourly' ELSE 'fixed' END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->dropColumn(['pricing_model', 'unit_rate', 'coverage_hours', 'max_people']);
        });
    }
};