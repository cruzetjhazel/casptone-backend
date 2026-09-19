<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photographer_id' => ['required', 'integer', 'exists:users,id'],

            'is_custom_package' => ['sometimes', 'boolean'],
            'package_id' => ['required_if:is_custom_package,false', 'nullable', 'integer'],
            'custom_component_ids' => ['sometimes', 'array'],
            'custom_component_ids.*' => ['integer'],
            // Present when the client used the sliding-hours picker instead
            // of a discrete duration option — see CreateBookingAction::
            // resolveCustomPackage(), which requires the photographer to
            // have hourly pricing enabled for this to be accepted.
            'custom_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],

            'add_on_ids' => ['sometimes', 'array'],
            'add_on_ids.*' => ['integer'],

            'event_type' => ['required', Rule::in([
                'wedding', 'birthday', 'prenup', 'graduation', 'portrait',
                'corporate_event', 'product_photography', 'family_event', 'other',
            ])],
            'custom_event_type' => ['required_if:event_type,other', 'nullable', 'string', 'max:255'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],

            'location_type' => ['required', Rule::in(['studio', 'client_location', 'outdoor_location', 'other'])],
            'province_id' => ['required_unless:location_type,studio', 'nullable', 'integer', 'exists:location_provinces,id'],
            'city_municipality_id' => ['required_unless:location_type,studio', 'nullable', 'integer', 'exists:location_cities_municipalities,id'],
            'barangay_id' => ['required_unless:location_type,studio', 'nullable', 'integer', 'exists:location_barangays,id'],
            'event_address' => ['required_unless:location_type,studio', 'nullable', 'string', 'max:500'],
            'guest_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'special_requests' => ['sometimes', 'nullable', 'string', 'max:2000'],
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