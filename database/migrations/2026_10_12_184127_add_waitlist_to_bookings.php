<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waitlist for conflicting bookings.
 *
 *  - Adds the 'waitlisted' booking status (allowed by chk_booking_status).
 *  - Adds bookings.slot_offered_at: set when the photographer offers a released schedule
 *    to a waitlisted booking (drives the "schedule is available again" message).
 *  - Backfills bookings that the OLD auto-decline logic wrongly cancelled (reason starts
 *    with "Automatically declined") back onto the waitlist, if their event date has not
 *    passed, so the photographer can still offer them the slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('slot_offered_at')->nullable()->after('hold_expires_at');
        });

        if ($this->isMysqlFamily()) {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_booking_status');
            DB::statement("
                ALTER TABLE bookings
                ADD CONSTRAINT chk_booking_status
                CHECK (`status` in ('pending','accepted','confirmed','rejected','cancelled','completed','expired','no_show','waitlisted'))
            ");
        }

        $legacy = DB::table('bookings')
            ->where('status', 'cancelled')
            ->whereNotNull('superseded_by_booking_id')
            ->where('cancellation_reason', 'like', 'Automatically declined%')
            ->where('event_date', '>=', now()->toDateString())
            ->pluck('id');

        foreach ($legacy as $id) {
            // The old code overwrote payment_status with 'cancelled'. If the client had already
            // submitted a reference that nobody reviewed yet, show it as under review again.
            $hasUnreviewedPayment = DB::table('payments')
                ->where('booking_id', $id)
                ->where('matching_status', 'not_matched')
                ->exists();

            DB::table('bookings')->where('id', $id)->update([
                'status' => 'waitlisted',
                'payment_status' => $hasUnreviewedPayment ? 'pending_verification' : 'pending',
                'cancellation_reason' => null,
                'hold_expires_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('bookings')->where('status', 'waitlisted')->update([
            'status' => 'cancelled',
            'cancellation_reason' => 'Rolled back from the waitlist.',
        ]);

        if ($this->isMysqlFamily()) {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_booking_status');
            DB::statement("
                ALTER TABLE bookings
                ADD CONSTRAINT chk_booking_status
                CHECK (`status` in ('pending','accepted','confirmed','rejected','cancelled','completed','expired','no_show'))
            ");
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('slot_offered_at');
        });
    }

    private function isMysqlFamily(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
