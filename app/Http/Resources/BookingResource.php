<?php

namespace App\Http\Resources;

use App\Enums\BookingLocationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client' => ['id' => $this->client->id, 'name' => $this->client->name],
            'photographer' => ['id' => $this->photographer->id, 'name' => $this->photographer->name],
            'is_custom_package' => $this->is_custom_package,
            'package_snapshot' => $this->package_snapshot,
            'custom_package_snapshot' => $this->custom_package_snapshot,
            'add_ons_snapshot' => $this->add_ons_snapshot,
            'event_type' => $this->event_type,
            'custom_event_type' => $this->custom_event_type,
            'event_date' => $this->event_date->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'location_type' => $this->location_type->value,
            'event_address' => $this->event_address,
            'guest_count' => $this->guest_count,
            'special_requests' => $this->special_requests,
            'coverage_area_notice' => $this->location_type !== BookingLocationType::Studio,
            'subtotal' => $this->subtotal,
            'total_price' => $this->total_price,
            'status' => $this->status->value,
            'hold_expires_at' => $this->hold_expires_at,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_requested_at' => $this->cancellation_requested_at,
            'cancellation_decision' => $this->cancellation_decision?->value,
            'cancellation_decided_at' => $this->cancellation_decided_at,
            'reschedule_request' => $this->reschedule_requested_at ? [
                'requested_event_date' => $this->requested_event_date?->format('Y-m-d'),
                'requested_start_time' => $this->requested_start_time,
                'requested_at' => $this->reschedule_requested_at,
                'decision' => $this->reschedule_decision?->value,
                'decided_at' => $this->reschedule_decided_at,
            ] : null,
            'modification_request' => $this->modification_requested_at ? [
                'type' => $this->modification_type,
                'reason' => $this->modification_reason,
                'requested_at' => $this->modification_requested_at,
            ] : null,
            'payment_plan' => $this->payment_plan?->value,
            'payment_status' => $this->payment_status?->value,
            'amount_paid' => $this->totalPaid(),
            'remaining_balance' => $this->remainingBalance(),
            'payment_actions' => [
                'can_submit_online_payment' => $this->status?->value === 'confirmed'
                    && $this->payment_status?->value === 'pending',
                'can_record_onsite_payment' => $this->isEligibleForOnsitePayment(),
            ],
            'service_status' => $this->service_status?->value,
            'service_status_updated_at' => $this->service_status_updated_at,
            'has_reviewed' => $this->review()->exists(),
            'created_at' => $this->created_at,
        ];
    }
}