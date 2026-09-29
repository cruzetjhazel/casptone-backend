<?php

namespace App\Models;

use App\Enums\BookingLocationType;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Enums\PaymentPlan;
use App\Enums\ServiceTrackerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use App\Observers\BookingObserver;

#[ObservedBy([BookingObserver::class])]
class Booking extends Model
{
    use HasFactory;
    protected $attributes = [
        'payment_status' => 'pending',
    ];

    protected $fillable = [
        'client_id', 'photographer_id', 'package_id',
        'is_custom_package', 'package_snapshot', 'custom_package_snapshot', 'add_ons_snapshot',
        'event_type', 'custom_event_type', 'event_date', 'start_time', 'end_time', 'duration_minutes',
        'location_type', 'province_id', 'city_municipality_id', 'barangay_id',
        'event_address', 'guest_count', 'special_requests',
        'subtotal', 'total_price', 'status', 'hold_expires_at',
        'rejection_reason', 'cancellation_reason', 'cancellation_requested_at',
        'cancellation_decision', 'cancellation_decided_at', 'superseded_by_booking_id',
        'non_completion_reason', 'non_completion_reported_at',
        'non_completion_reported_by', 'non_completion_dispute_deadline_at',
        'non_completion_disputed_at', 'non_completion_dispute_reason',
        'non_completion_review_status', 'non_completion_admin_notes', 'non_completion_resolved_at',
        'requested_event_date', 'requested_start_time', 'reschedule_requested_at',
        'reschedule_decision', 'reschedule_decided_at',
        'modification_type', 'modification_reason', 'modification_requested_at',
        'payment_plan', 'payment_status',
        'service_status', 'service_status_updated_at',
        'custom_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_custom_package' => 'boolean',
            'package_snapshot' => 'array',
            'custom_package_snapshot' => 'array',
            'add_ons_snapshot' => 'array',
            'event_date' => 'date:Y-m-d',
            'guest_count' => 'integer',
            'subtotal' => 'decimal:2',
            'total_price' => 'decimal:2',
            'status' => BookingStatus::class,
            'location_type' => BookingLocationType::class,
            'cancellation_decision' => CancellationDecision::class,
            'hold_expires_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancellation_decided_at' => 'datetime',
            'non_completion_reported_at' => 'datetime',
            'non_completion_dispute_deadline_at' => 'datetime',
            'non_completion_disputed_at' => 'datetime',
            'non_completion_resolved_at' => 'datetime',
            'requested_event_date' => 'date:Y-m-d',
            'reschedule_requested_at' => 'datetime',
            'reschedule_decision' => CancellationDecision::class,
            'reschedule_decided_at' => 'datetime',
            'modification_requested_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'payment_plan' => PaymentPlan::class,
            'payment_status' => BookingPaymentStatus::class,
            'service_status' => ServiceTrackerStatus::class,
            'service_status_updated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(LocationProvince::class, 'province_id');
    }

    public function cityMunicipality(): BelongsTo
    {
        return $this->belongsTo(LocationCityMunicipality::class, 'city_municipality_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(LocationBarangay::class, 'barangay_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The booking whose confirmed payment auto-declined this one for the
     * same photographer/slot (see SlotConflictService::releaseConflictingBookings).
     * Null once this booking has been accommodated again, or if it was
     * never superseded in the first place (e.g. an ordinary reject/cancel).
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'superseded_by_booking_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(BookingExtension::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BookingSchedule::class)->orderBy('sort_order');
    }

    /**
     * Schedule 1 (this booking's own event_date/start_time/end_time —
     * unchanged, computed by CreateBookingAction exactly as before) plus
     * every additional BookingSchedule row, as one ordered collection.
     * BookingResource, the extension-target picker, and anything else
     * that needs "every occupied period for this booking" reads through
     * this rather than special-casing where Schedule 1's columns live.
     */
    public function allSchedules(): \Illuminate\Support\Collection
    {
        $primary = new BookingSchedule([
            'label' => $this->custom_event_type ?: ucfirst(str_replace('_', ' ', $this->event_type ?? '')),
            'event_date' => $this->event_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->duration_minutes,
        ]);
        // id intentionally stays null (unsaved model) — BookingScheduleResource
        // reads a null id as "this is the primary schedule, not a
        // booking_schedules row."

        return collect([$primary])->concat($this->schedules);
    }

    /**
     * Extension requests are only meaningful for sliding-hours custom
     * bookings (custom_hours is set — see CreateBookingAction::
     * resolveCustomPackage) and only once the booking is actually
     * Confirmed. Fixed-package and flat-base-fee custom bookings have no
     * hourly rate to bill additional coverage against.
     */
    public function isEligibleForExtensionRequest(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && $this->is_custom_package
            && $this->custom_hours !== null;
    }

    public function hasPendingExtensionRequest(): bool
    {
        return $this->extensions()->where('status', \App\Enums\BookingExtensionStatus::Pending)->exists();
    }

    /**
     * Sum of every APPROVED extension's additional_charge — kept separate
     * from total_price so the original booking total is never silently
     * increased (see CreateBookingAction / spec). Callers that need "what
     * the client owes altogether" should add this to total_price
     * themselves rather than this method folding it in.
     */
    public function approvedExtensionCharge(): float
    {
        return (float) $this->extensions()
            ->where('status', \App\Enums\BookingExtensionStatus::Approved)
            ->sum('additional_charge');
    }

    public function isHoldExpired(): bool
    {
        return $this->status === BookingStatus::Pending
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isPast();
    }

    public function hasPendingCancellationRequest(): bool
    {
        return $this->cancellation_requested_at !== null && $this->cancellation_decision === null;
    }

    public function hasPendingRescheduleRequest(): bool
    {
        return $this->reschedule_requested_at !== null && $this->reschedule_decision === null;
    }

    /**
     * The amount due online for a given payment plan (§8.2, §8.8).
     * Half Payment = 50% online + 50% remaining balance; Full Payment = 100% online.
     */
    public function onlineAmountDueFor(PaymentPlan $plan): float
    {
        return $plan === PaymentPlan::Full
            ? (float) $this->total_price
            : round((float) $this->total_price / 2, 2);
    }

    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * Total Booking Amount - Online Payment = Remaining Balance (§8.8).
     */
    public function remainingBalance(): float
    {
        return max(0.0, round((float) $this->total_price - $this->totalPaid(), 2));
    }

    /**
     * True once the required online payment for a Half-Payment booking has
     * been submitted (Confirmed + payment_plan=Half) but the onsite
     * remaining balance hasn't been recorded yet (§8.9).
     */
    public function isEligibleForOnsitePayment(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && $this->payment_status !== BookingPaymentStatus::FullyPaid;
    }

    /**
     * Service Tracker (Module 10, §8.10-8.11) is only meaningful once a
     * booking has actually been confirmed — or is already Completed via the
     * tracker reaching its final stage.
     */
    public function canManageServiceTracker(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    /**
     * True only once the photographer's explicit completion action becomes
     * valid: still Confirmed, and the service tracker has reached its final
     * stage (Delivered). BookingStatus never flips to Completed on its own —
     * see MarkServiceCompletedAction, the only caller of this check.
     */
    public function canCompleteService(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && $this->service_status === ServiceTrackerStatus::Delivered;
    }

    /**
     * Cancellation is only available before the service has actually
     * started: a Pending request, or a Confirmed booking that's still
     * pre-event (service_status null or Upcoming — event_day/editing/
     * delivered/completed all count as "started").
     */
    public function isEligibleForCancellationRequest(): bool
    {
        if ($this->status === BookingStatus::Pending) {
            return true;
        }

        return $this->status === BookingStatus::Confirmed
            && in_array($this->service_status, [null, ServiceTrackerStatus::Upcoming], true);
    }

    /**
     * True once the booking's required payment (per its payment plan) has
     * been settled — used by BookingObserver to auto-advance the service
     * tracker from null to Upcoming.
     */
    public function isPaymentSettled(): bool
    {
        return in_array($this->payment_status, [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid], true);
    }

    /**
     * Other clients who previously requested this exact photographer/date/
     * time and were auto-declined when THIS booking (or any other booking
     * for the same overlapping slot) had its payment confirmed first — see
     * SlotConflictService::releaseConflictingBookings, which is the only
     * place superseded_by_booking_id gets set. Scoped by slot overlap
     * rather than strictly to this booking's id so that clients who lost
     * out across multiple prior rounds (e.g. a previously accommodated
     * client who also later cancelled) all remain reviewable, per the
     * "Accommodate Other Reservation" requirement.
     *
     * Only meaningful once this booking itself is Cancelled — call this
     * after a previously paid/confirmed booking has been cancelled.
     */
    public function accommodationCandidates(): \Illuminate\Database\Eloquent\Builder
    {
        return Booking::query()
            ->where('photographer_id', $this->photographer_id)
            ->where('event_date', $this->event_date)
            ->where('id', '!=', $this->id)
            ->where('status', BookingStatus::Cancelled)
            ->whereNotNull('superseded_by_booking_id')
            ->where('start_time', '<', $this->end_time)
            ->where('end_time', '>', $this->start_time)
            ->with('client');
    }
        /** Completed, or reported as a no-show (and not overturned by admin). */
    public function isReviewable(): bool
    {
        return $this->status === BookingStatus::Completed
            || ($this->status === BookingStatus::Cancelled && $this->non_completion_reason !== null);
    }

    /** null | open | disputed | upheld | final */
    public function nonCompletionState(): ?string
    {
        if (! $this->non_completion_reason) {
            return null;
        }
        if ($this->non_completion_review_status === 'pending_admin') {
            return 'disputed';
        }
        if ($this->non_completion_review_status === 'upheld') {
            return 'upheld';
        }
        if ($this->non_completion_dispute_deadline_at && $this->non_completion_dispute_deadline_at->isFuture()) {
            return 'open';
        }

        return 'final';
    }
}