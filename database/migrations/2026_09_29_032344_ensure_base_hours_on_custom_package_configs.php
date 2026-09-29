<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('custom_package_configs', 'base_hours')) {
            Schema::table('custom_package_configs', function (Blueprint $table) {
                $table->unsignedSmallInteger('base_hours')->nullable()->after('base_fee');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('custom_package_configs', 'base_hours')) {
            Schema::table('custom_package_configs', function (Blueprint $table) {
                $table->dropColumn('base_hours');
            });
        }
    }
};