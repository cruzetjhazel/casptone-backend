<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Optional price used when the booking is not at the studio.
            $table->decimal('outdoor_price', 10, 2)->nullable()->after('price');
        });

        Schema::table('custom_package_configs', function (Blueprint $table) {
            // Optional hourly rate used when the booking is not at the studio.
            $table->decimal('outdoor_hourly_rate', 10, 2)->nullable()->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('outdoor_price');
        });

        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->dropColumn('outdoor_hourly_rate');
        });
    }
};