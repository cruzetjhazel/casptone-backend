<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('non_completion_reported_by')->nullable()->after('non_completion_reported_at')->constrained('users')->nullOnDelete();
            $table->timestamp('non_completion_dispute_deadline_at')->nullable()->after('non_completion_reported_by');
            $table->timestamp('non_completion_disputed_at')->nullable()->after('non_completion_dispute_deadline_at');
            $table->text('non_completion_dispute_reason')->nullable()->after('non_completion_disputed_at');
            $table->string('non_completion_review_status')->nullable()->after('non_completion_dispute_reason'); // pending_admin | upheld | overturned
            $table->text('non_completion_admin_notes')->nullable()->after('non_completion_review_status');
            $table->timestamp('non_completion_resolved_at')->nullable()->after('non_completion_admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('non_completion_reported_by');
            $table->dropColumn([
                'non_completion_dispute_deadline_at', 'non_completion_disputed_at',
                'non_completion_dispute_reason', 'non_completion_review_status',
                'non_completion_admin_notes', 'non_completion_resolved_at',
            ]);
        });
    }
};