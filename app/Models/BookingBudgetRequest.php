<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A client's budget request for a Pending booking and the photographer's answer.
 *
 * requested -> proposed (photographer accepted the budget or countered) -> accepted | rejected
 * requested -> declined (photographer said no)
 * any open   -> expired | superseded | withdrawn
 *
 * "Open" = requested or proposed. Closing is a compare-and-set UPDATE that also clears
 * open_booking_id, so two workers can never both close the same request.
 */
class BookingBudgetRequest extends Model
{
    public const REQUESTED = 'requested';

    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const SUPERSEDED = 'superseded';

    public const WITHDRAWN = 'withdrawn';

    public const OPEN = [self::REQUESTED, self::PROPOSED];

    /** Business rules (change here only). */
    public const MAX_REQUESTS_PER_BOOKING = 3;

    public const COUNTER_WINDOW_HOURS = 24;

    public const MAX_INCLUSIONS = 15;

    protected $fillable = [
        'booking_id', 'client_id', 'photographer_id', 'budget_amount', 'client_note', 'status',
        'proposed_subtotal', 'proposed_total', 'proposed_inclusions', 'photographer_note',
        'decline_reason', 'expires_at', 'responded_at', 'open_booking_id',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:2',
            'proposed_subtotal' => 'decimal:2',
            'proposed_total' => 'decimal:2',
            'proposed_inclusions' => 'array',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isStale(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** The booking's open request (use inside the booking lock). */
    public static function openFor(Booking $booking, bool $lock = false): ?self
    {
        $query = static::where('booking_id', $booking->id)->whereIn('status', self::OPEN);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** Close the booking's open request. Returns the row only if THIS call closed it. */
    public static function closeOpenFor(Booking $booking, string $status): ?self
    {
        $row = static::openFor($booking);

        if ($row === null) {
            return null;
        }

        $closed = static::whereKey($row->id)
            ->whereIn('status', self::OPEN)
            ->update(['status' => $status, 'open_booking_id' => null, 'responded_at' => now()]);

        return $closed === 1 ? $row->refresh() : null;
    }

    /**
     * Called inside AcceptBookingAction's lock. Terms waiting for the client block a plain
     * accept; an unanswered request is superseded by it.
     */
    public static function guardNormalAccept(Booking $booking): void
    {
        $open = static::openFor($booking, lock: true);

        if ($open === null) {
            return;
        }

        if ($open->status === self::PROPOSED) {
            throw ValidationException::withMessages([
                'status' => ['The client has been sent custom terms and has not answered yet. Wait for their response or let the offer expire.'],
            ]);
        }

        static::whereKey($open->id)->update([
            'status' => self::SUPERSEDED,
            'open_booking_id' => null,
            'responded_at' => now(),
        ]);
    }

    /**
     * Housekeeping for the expiry job: close open requests whose booking expired / ended, or whose
     * own deadline passed. Idempotent. Returns how many were closed.
     */
    public static function expireStale(): int
    {
        $count = 0;

        static::with('booking')->whereIn('status', self::OPEN)->get()->each(function (self $request) use (&$count) {
            $booking = $request->booking;

            if ($booking === null || $booking->status === BookingStatus::Expired) {
                $to = self::EXPIRED;
            } elseif ($booking->status !== BookingStatus::Pending) {
                $to = self::WITHDRAWN;
            } elseif ($request->isStale()) {
                $to = self::EXPIRED;
            } else {
                return;
            }

            $count += static::whereKey($request->id)
                ->whereIn('status', self::OPEN)
                ->update(['status' => $to, 'open_booking_id' => null, 'responded_at' => now()]);
        });

        return $count;
    }

    /** Shape returned inside BookingResource. */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'is_open' => $this->isOpen(),
            'budget_amount' => (float) $this->budget_amount,
            'client_note' => $this->client_note,
            'proposed_price' => $this->proposed_subtotal !== null ? (float) $this->proposed_subtotal : null,
            'proposed_total' => $this->proposed_total !== null ? (float) $this->proposed_total : null,
            'inclusions' => $this->proposed_inclusions ?? [],
            'photographer_note' => $this->photographer_note,
            'decline_reason' => $this->decline_reason,
            'expires_at' => $this->expires_at,
            'responded_at' => $this->responded_at,
            'created_at' => $this->created_at,
        ];
    }

    /** budget_request + budget_actions for BookingResource (one query per booking). */
    public static function summaryFor(Booking $booking): array
    {
        $requests = $booking->budgetRequests;
        $latest = $requests->sortByDesc('id')->first();
        $open = $requests->first(fn (self $r) => $r->isOpen());
        $pending = $booking->status === BookingStatus::Pending && ! $booking->isHoldExpired();

        return [
            'budget_request' => $latest?->toApiArray(),
            'budget_actions' => [
                'can_request' => $pending
                    && $open === null
                    && $requests->count() < self::MAX_REQUESTS_PER_BOOKING
                    && (float) $booking->subtotal > 0,
                'requests_remaining' => max(0, self::MAX_REQUESTS_PER_BOOKING - $requests->count()),
                'can_respond' => $pending && $open?->status === self::REQUESTED,
                'can_answer_terms' => $pending && $open?->status === self::PROPOSED && ! $open->isStale(),
            ],
        ];
    }
}