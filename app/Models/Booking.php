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
        'event_type', 'custom_event_type', 'event_date', 'start_time', 'end_time',
        'location_type', 'province_id', 'city_municipality_id', 'barangay_id',
        'event_address', 'guest_count', 'special_requests',
        'subtotal', 'total_price', 'status', 'hold_expires_at',
        'rejection_reason', 'cancellation_reason', 'cancellation_requested_at',
        'cancellation_decision', 'cancellation_decided_at',
        'requested_event_date', 'requested_start_time', 'reschedule_requested_at',
        'reschedule_decision', 'reschedule_decided_at',
        'modification_type', 'modification_reason', 'modification_requested_at',
        'payment_plan', 'payment_status',
        'service_status', 'service_status_updated_at',
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

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
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
}