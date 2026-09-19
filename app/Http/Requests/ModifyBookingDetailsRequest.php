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
        });
    }
}