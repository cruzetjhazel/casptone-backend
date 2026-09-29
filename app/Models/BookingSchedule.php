<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'label', 'event_date', 'start_time', 'end_time', 'duration_minutes',
        'location_type', 'province_id', 'city_municipality_id', 'barangay_id', 'event_address',
        'sort_order',
    ];

    // Match the model class names your Booking model already uses for its own
    // province/city/barangay relations.
    public function province() { return $this->belongsTo(\App\Models\LocationProvince::class, 'province_id'); }
    public function cityMunicipality() { return $this->belongsTo(\App\Models\LocationCityMunicipality::class, 'city_municipality_id'); }
    public function barangay() { return $this->belongsTo(\App\Models\LocationBarangay::class, 'barangay_id'); }

    protected function casts(): array
    {
        return [
            // Cast the same way as Booking::event_date so both can be
            // merged into one collection and read identically by
            // AvailabilityService (which only ever touches ->event_date,
            // ->start_time, ->end_time — never a model-specific field).
            'event_date' => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
