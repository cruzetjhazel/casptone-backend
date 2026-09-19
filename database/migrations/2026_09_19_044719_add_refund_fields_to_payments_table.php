<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_status')->default('none')->after('verification_notes');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('refund_status');
            $table->text('refund_notes')->nullable()->after('refund_amount');
            $table->foreignId('refunded_by')->nullable()->after('refund_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable()->after('refunded_by');

            $table->index('refund_status');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_refund_amount_non_negative CHECK (refund_amount IS NULL OR refund_amount >= 0)");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_refund_amount_non_negative');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refund_status', 'refund_amount', 'refund_notes', 'refunded_at']);
        });
    }
};