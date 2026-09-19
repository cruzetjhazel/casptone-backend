<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('province_id')->nullable()->after('location_type')
                ->constrained('location_provinces')->nullOnDelete();
            $table->foreignId('city_municipality_id')->nullable()->after('province_id')
                ->constrained('location_cities_municipalities')->nullOnDelete();
            $table->foreignId('barangay_id')->nullable()->after('city_municipality_id')
                ->constrained('location_barangays')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('barangay_id');
            $table->dropConstrainedForeignId('city_municipality_id');
            $table->dropConstrainedForeignId('province_id');
        });
    }
};