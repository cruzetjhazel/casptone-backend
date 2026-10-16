<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One offer of a released schedule to a waitlisted booking.
 *
 * "Open" = offered (client has not answered) or accepted (client accepted, not paid yet).
 * Every transition out of open is a compare-and-set UPDATE, so two workers can never both
 * close the same offer (and so never both pass the slot on).
 */
class BookingSlotOffer extends Model
{
    public const OFFERED = 'offered';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const LAPSED = 'lapsed';

    public const WITHDRAWN = 'withdrawn';

    public const OPEN = [self::OFFERED, self::ACCEPTED];

    protected $fillable = [
        'booking_id', 'released_booking_id', 'status', 'offered_at', 'expires_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function releasedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'released_booking_id');
    }

    /** The newest open offer of a booking, or null. */
    public static function openFor(Booking $booking): ?self
    {
        return static::where('booking_id', $booking->id)
            ->whereIn('status', self::OPEN)
            ->latest('id')
            ->first();
    }

    /**
     * Close the booking's open offer with $status. Returns the row only if THIS call closed it
     * (null when there was none, or another worker closed it first).
     */
    public static function closeOpenFor(Booking $booking, string $status): ?self
    {
        $row = static::openFor($booking);

        if ($row === null) {
            return null;
        }

        $closed = static::whereKey($row->id)
            ->whereIn('status', self::OPEN)
            ->update(['status' => $status, 'responded_at' => now()]);

        return $closed === 1 ? $row->refresh() : null;
    }

    /** offered -> accepted (stays open until paid, lapsed or withdrawn). */
    public static function markAccepted(Booking $booking): void
    {
        static::where('booking_id', $booking->id)
            ->where('status', self::OFFERED)
            ->update(['status' => self::ACCEPTED, 'responded_at' => now()]);
    }
}