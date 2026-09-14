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
            'event_address' => ['nullable', 'string', 'max:500'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
        ];
    }
}