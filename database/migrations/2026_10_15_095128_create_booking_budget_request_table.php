<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budget-based negotiation. Completely separate from booking_slot_offers.
 *
 * open_booking_id is set to booking_id while the request is open (requested / proposed) and is
 * NULL once it is closed. Its UNIQUE index lets MySQL allow only ONE open request per booking
 * (MySQL has no partial unique index, but it allows any number of NULLs in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_budget_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('photographer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('budget_amount', 10, 2);
            $table->text('client_note')->nullable();
            $table->string('status', 20)->default('requested');
            $table->decimal('proposed_subtotal', 10, 2)->nullable();
            $table->decimal('proposed_total', 10, 2)->nullable();
            $table->json('proposed_inclusions')->nullable();
            $table->text('photographer_note')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('open_booking_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("
                ALTER TABLE booking_budget_requests
                ADD CONSTRAINT chk_booking_budget_request_status
                CHECK (`status` in ('requested','proposed','accepted','rejected','declined','expired','superseded','withdrawn'))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_budget_requests');
    }
};