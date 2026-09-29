<?php

use App\Enums\BookingExtensionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records a client's request for additional photography coverage beyond
 * their originally booked hours (sliding-hours custom packages only — see
 * CreateBookingAction::resolveCustomPackage). Kept as its own table rather
 * than mutating the booking's custom_hours/total_price directly so the
 * original booked coverage and its price stay untouched and the additional
 * charge is always visible as a separate line item (per spec: "Do not
 * silently increase the original booking total").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->unsignedTinyInteger('requested_hours');
            // Snapshot of the photographer's hourly_rate at request time, so
            // a later rate change doesn't retroactively alter a pending or
            // already-decided request.
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('additional_charge', 10, 2);

            $table->string('status', 20)->default(BookingExtensionStatus::Pending->value)->index();
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->text('decline_reason')->nullable();

            // The booking's new coverage end time once approved — kept here
            // rather than overwriting bookings.end_time so "Original
            // Coverage" vs "Additional Coverage" can always be reconstructed
            // and displayed distinctly (per spec: "Do not merge the
            // extension into the original coverage in a way that hides the
            // additional charge").
            $table->time('new_end_time')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE booking_extensions ADD CONSTRAINT chk_booking_extension_status
                 CHECK (status IN ('pending','approved','declined'))"
            );
            DB::statement('ALTER TABLE booking_extensions ADD CONSTRAINT chk_booking_extension_hours CHECK (requested_hours > 0)');
            DB::statement('ALTER TABLE booking_extensions ADD CONSTRAINT chk_booking_extension_amounts CHECK (hourly_rate >= 0 AND additional_charge >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extensions');
    }
};
