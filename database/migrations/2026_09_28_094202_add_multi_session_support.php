<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Photographer opts a package in to covering several photography
            // sessions under ONE booking (e.g. graduation, two shooting days).
            $table->boolean('allows_multiple_sessions')->default(false)->after('buffer_minutes');
            $table->unsignedTinyInteger('max_sessions')->nullable()->after('allows_multiple_sessions');
        });

        Schema::table('booking_schedules', function (Blueprint $table) {
            // Optional per-session location. All null = same as the booking's.
            $table->string('location_type')->nullable()->after('duration_minutes');
            $table->foreignId('province_id')->nullable()->after('location_type')
                ->constrained('location_provinces')->nullOnDelete();
            $table->foreignId('city_municipality_id')->nullable()->after('province_id')
                ->constrained('location_cities_municipalities')->nullOnDelete();
            $table->foreignId('barangay_id')->nullable()->after('city_municipality_id')
                ->constrained('location_barangays')->nullOnDelete();
            $table->string('event_address')->nullable()->after('barangay_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('province_id');
            $table->dropConstrainedForeignId('city_municipality_id');
            $table->dropConstrainedForeignId('barangay_id');
            $table->dropColumn(['location_type', 'event_address']);
        });
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['allows_multiple_sessions', 'max_sessions']);
        });
    }
};