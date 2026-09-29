<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModifyBookingDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_type' => ['sometimes', 'in:studio,client_location,outdoor_location,other'],
            'province_id' => ['sometimes', 'nullable', 'integer', 'exists:location_provinces,id'],
            'city_municipality_id' => ['sometimes', 'nullable', 'integer', 'exists:location_cities_municipalities,id'],
            'barangay_id' => ['sometimes', 'nullable', 'integer', 'exists:location_barangays,id'],
            'event_address' => ['nullable', 'string', 'max:500'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $provinceId = $this->input('province_id');
            $cityMunicipalityId = $this->input('city_municipality_id');
            $barangayId = $this->input('barangay_id');

            if ($cityMunicipalityId && $provinceId) {
                $belongs = \App\Models\LocationCityMunicipality::where('id', $cityMunicipalityId)
                    ->where('province_id', $provinceId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('city_municipality_id', 'This city/municipality does not belong to the selected province.');
                }
            }

            if ($barangayId && $cityMunicipalityId) {
                $belongs = \App\Models\LocationBarangay::where('id', $barangayId)
                    ->where('city_municipality_id', $cityMunicipalityId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('barangay_id', 'This barangay does not belong to the selected city/municipality.');
                }
            }

            // Same province -> city -> barangay consistency check, per session.
            foreach ((array) $this->input('additional_schedules', []) as $i => $s) {
                $p = $s['province_id'] ?? null;
                $c = $s['city_municipality_id'] ?? null;
                $b = $s['barangay_id'] ?? null;

                if ($c && $p && ! \App\Models\LocationCityMunicipality::where('id', $c)->where('province_id', $p)->exists()) {
                    $validator->errors()->add("additional_schedules.$i.city_municipality_id", 'This city/municipality does not belong to the selected province.');
                }
                if ($b && $c && ! \App\Models\LocationBarangay::where('id', $b)->where('city_municipality_id', $c)->exists()) {
                    $validator->errors()->add("additional_schedules.$i.barangay_id", 'This barangay does not belong to the selected city/municipality.');
                }
            }
        });
    }
}