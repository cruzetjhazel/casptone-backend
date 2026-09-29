<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Real DB id for schedule 2+ (a persisted BookingSchedule row),
            // or null for Schedule 1 — see Booking::allSchedules(), which
            // synthesizes Schedule 1 from the booking's own columns rather
            // than a real row. Null tells the frontend/extension flow
            // "target the primary booking, not a booking_schedules row."
            'id' => $this->id,
            'label' => $this->label,
            'event_date' => $this->event_date instanceof \DateTimeInterface
                ? $this->event_date->format('Y-m-d')
                : $this->event_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->duration_minutes,
            'duration_confirmed' => $this->end_time !== null,
            'location_type' => $this->location_type,
            'province_id' => $this->province_id,
            'city_municipality_id' => $this->city_municipality_id,
            'barangay_id' => $this->barangay_id,
            'event_address' => $this->event_address,
            // null = this session uses the booking's own location
            'location_label' => $this->location_type === null ? null : collect([
                $this->barangay?->name, $this->cityMunicipality?->name, $this->province?->name,
            ])->filter()->implode(', '),
            'is_primary' => $this->id === null,
        ];
    }
}
