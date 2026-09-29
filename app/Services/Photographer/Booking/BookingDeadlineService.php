<?php

namespace App\Services\Photographer\Booking;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class BookingDeadlineService
{
    /** Photographer has this long to approve/reject; client has this long to pay. */
    public const WINDOW_HOURS = 24;

    /** An unpaid booking must be settled at least this long before the first session starts. */
    public const PAYMENT_CUTOFF_HOURS = 3;

    /** Never offer a payment window shorter than this. */
    public const MIN_PAYMENT_WINDOW_MINUTES = 60;

    /** Earliest start across schedule 1 and every additional schedule of a saved booking. */
    public function firstSessionStart(Booking $booking): Carbon
    {
        return $booking->allSchedules()
            ->map(fn ($s) => Carbon::parse(Carbon::parse($s->event_date)->toDateString().' '.$s->start_time))
            ->sortBy(fn (Carbon $c) => $c->timestamp)
            ->first();
    }

    /** Deadline for the photographer to decide on a brand-new request (never later than the event). */
    public function decisionDeadlineForRequest(string $eventDate, string $startTime, array $extraSchedules = []): Carbon
    {
        $first = collect([[$eventDate, $startTime]])
            ->concat(collect($extraSchedules)->map(fn ($s) => [$s['event_date'], $s['start_time']]))
            ->map(fn ($p) => Carbon::parse(Carbon::parse($p[0])->toDateString().' '.$p[1]))
            ->sortBy(fn (Carbon $c) => $c->timestamp)
            ->first();

        $deadline = now()->addHours(self::WINDOW_HOURS);

        return $deadline->lessThan($first) ? $deadline : $first;
    }

    /**
     * Deadline for the client to pay after approval/accommodation:
     * min(now + 24h, first session start - 3h). Throws if that leaves
     * less than an hour, i.e. the event is too close to collect a
     * reservation payment.
     */
    public function paymentDeadline(Booking $booking): Carbon
    {
        $now = now();
        $deadline = $now->copy()->addHours(self::WINDOW_HOURS);
        $cutoff = $this->firstSessionStart($booking)->subHours(self::PAYMENT_CUTOFF_HOURS);

        if ($cutoff->lessThan($deadline)) {
            $deadline = $cutoff;
        }

        if ($deadline->lessThan($now->copy()->addMinutes(self::MIN_PAYMENT_WINDOW_MINUTES))) {
            throw ValidationException::withMessages([
                'booking' => ['This event starts too soon to collect a reservation payment. Reject the request or arrange it directly with the client.'],
            ]);
        }

        return $deadline;
    }
}