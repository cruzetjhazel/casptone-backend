<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestBookingModificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1'],
            'changes.guest_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            'changes.event_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'changes.special_requests' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}