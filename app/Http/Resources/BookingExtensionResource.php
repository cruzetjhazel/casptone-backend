<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingExtensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requested_hours' => $this->requested_hours,
            'hourly_rate' => $this->hourly_rate,
            'additional_charge' => $this->additional_charge,
            'status' => $this->status->value,
            'requested_at' => $this->requested_at,
            'decided_at' => $this->decided_at,
            'decline_reason' => $this->decline_reason,
            'new_end_time' => $this->new_end_time,
            // Which schedule this extension targets — null means the
            // primary schedule (the booking's own date/time). Non-null on a
            // multi-schedule booking where the client picked a specific
            // entry to extend (e.g. "Wedding, Oct 20" rather than "Prenup,
            // Oct 5"). schedule_label is included so the UI doesn't need a
            // second lookup just to display which one it is.
            'schedule_id' => $this->booking_schedule_id,
            'schedule_label' => $this->booking_schedule_id ? $this->schedule?->label : null,
        ];
    }
}
