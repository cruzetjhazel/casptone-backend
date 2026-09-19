<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Recorded at registration once the client/photographer checks
            // "I agree to the Terms & Conditions" (see RegisterClientRequest/
            // RegisterPhotographerRequest — both now require terms_accepted).
            // Null would mean an account somehow exists without ever
            // accepting, which should be impossible going forward but is
            // left nullable so this migration doesn't break existing rows.
            $table->timestamp('terms_accepted_at')->nullable()->after('account_status');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Flat platform fee added on top of the package/add-on subtotal
            // (see CreateBookingAction). Framed to clients as covering the
            // platform's dispute-handling / no-show-support process (§16) —
            // it is NOT insurance in the regulated/legal sense, and the
            // frontend disclaimer must say so plainly. Stored separately
            // from subtotal so the price breakdown stays transparent and
            // historical bookings keep whatever fee was in effect when they
            // were made, even if the fee amount changes later.
            $table->decimal('platform_fee', 8, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('platform_fee');
        });
    }
};