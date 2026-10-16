<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic slot offers with an explicit "accept offer" step.
 *
 *  - bookings.slot_offer_accepted_at: set when the offered client accepts (or when the offer is
 *    made to a client who had ALREADY submitted a payment reference). Payment is only allowed
 *    while it is set; see Booking::isAwaitingOfferAcceptance().
 *  - booking_slot_offers: one row per offer, so we know who was already offered a given released
 *    slot (declined / lapsed clients are never re-offered automatically).
 *
 * Offers that already exist when this runs (Confirmed + slot_offered_at) are marked as accepted,
 * so clients who were already paying keep working exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('slot_offer_accepted_at')->nullable()->after('slot_offered_at');
        });

        Schema::create('booking_slot_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('released_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('status', 20)->default('offered');
            $table->timestamp('offered_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['released_booking_id', 'status']);
        });

        if ($this->isMysqlFamily()) {
            DB::statement("
                ALTER TABLE booking_slot_offers
                ADD CONSTRAINT chk_booking_slot_offer_status
                CHECK (`status` in ('offered','accepted','declined','lapsed','withdrawn'))
            ");
        }

        // Existing offers are treated as already accepted (backward compatible).
        DB::table('bookings')
            ->whereNotNull('slot_offered_at')
            ->whereNull('slot_offer_accepted_at')
            ->update(['slot_offer_accepted_at' => DB::raw('slot_offered_at')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_slot_offers');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('slot_offer_accepted_at');
        });
    }

    private function isMysqlFamily(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};