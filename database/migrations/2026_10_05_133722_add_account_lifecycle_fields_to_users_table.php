<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['suspension_reason' => 'text', 'suspended_at' => 'timestamp', 'deactivated_at' => 'timestamp', 'reactivated_at' => 'timestamp'] as $col => $type) {
                if (! Schema::hasColumn('users', $col)) {
                    $table->{$type}($col)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reactivated_at']);
        });
    }
};