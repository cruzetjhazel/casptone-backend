<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_provinces', function (Blueprint $table) {
            $table->id();
            $table->string('psgc_code', 10)->unique();
            $table->string('name');
            $table->string('region_code', 10);
            $table->timestamps();
        });

        Schema::create('location_cities_municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('psgc_code', 10)->unique();
            $table->foreignId('province_id')->constrained('location_provinces')->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['city', 'municipality']);
            $table->timestamps();

            $table->index(['province_id', 'name']);
        });

        Schema::create('location_barangays', function (Blueprint $table) {
            $table->id();
            $table->string('psgc_code', 10)->unique();
            $table->foreignId('city_municipality_id')
                ->constrained('location_cities_municipalities')
                ->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['city_municipality_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_barangays');
        Schema::dropIfExists('location_cities_municipalities');
        Schema::dropIfExists('location_provinces');
    }
};