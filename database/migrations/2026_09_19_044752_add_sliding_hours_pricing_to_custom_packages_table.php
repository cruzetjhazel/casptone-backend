<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional "sliding hours" pricing mode for custom packages: instead of (or
 * alongside) discrete duration options in custom_package_components, a
 * photographer can set an hourly rate + min/max hours and let the client
 * drag a slider to pick coverage length, with the price updating live
 * (base_fee + hours * hourly_rate). Nullable/opt-in — leaving hourly_rate
 * null keeps the existing discrete-duration-component flow working exactly
 * as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('base_fee');
            $table->unsignedTinyInteger('min_hours')->nullable()->after('hourly_rate');
            $table->unsignedTinyInteger('max_hours')->nullable()->after('min_hours');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Records the hours the client actually picked on the slider,
            // for custom bookings made under hourly pricing — kept
            // separately from custom_package_snapshot's duration_minutes
            // so it's easy to display "6 hours" back to the client/admin
            // without decoding minutes.
            $table->unsignedTinyInteger('custom_hours')->nullable()->after('custom_package_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('custom_package_configs', function (Blueprint $table) {
            $table->dropColumn(['hourly_rate', 'min_hours', 'max_hours']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('custom_hours');
        });
    }
};