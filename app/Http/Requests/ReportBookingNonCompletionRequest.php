<?php

namespace App\Http\Requests;

use App\Enums\BookingNonCompletionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportBookingNonCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(array_map(fn ($c) => $c->value, BookingNonCompletionReason::cases()))],
            'notes' => [
            Rule::requiredIf(fn () => $this->input('reason') === BookingNonCompletionReason::PhotographerNoShow->value),
           'nullable', 'string', 'min:20', 'max:1000',
            ],
        ];
    }
}