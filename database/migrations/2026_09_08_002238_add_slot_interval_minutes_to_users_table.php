<?php
// database/migrations/2026_09_09_000001_add_slot_interval_minutes_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Photographer-only in practice; nullable/default so it's a
            // no-op for clients/admins. 60 is the default booking interval.
            $table->unsignedSmallInteger('slot_interval_minutes')->default(60)->after('account_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('slot_interval_minutes');
        });
    }
};