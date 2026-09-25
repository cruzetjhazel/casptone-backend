<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accommodate Other Reservation feature: when SlotConflictService
 * auto-declines a rival Pending/Confirmed-unpaid booking because another
 * client's payment for the same slot was confirmed first (see
 * SlotConflictService::releaseConflictingBookings), it now records WHICH
 * booking won the slot here. If that winning booking is later cancelled,
 * this column is how the photographer's "Accommodate Other Reservation"
 * screen finds the clients who previously lost that same slot, without
 * relying on parsing cancellation_reason text.
 *
 * Nulled out again once a candidate is actually accommodated (see
 * AccommodateBookingAction) — at that point the booking is active again,
 * not "superseded" by anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('superseded_by_booking_id')->nullable()->after('cancellation_decided_at')
                ->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_booking_id');
        });
    }
};