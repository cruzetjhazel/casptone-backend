<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPaymentReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference_number' => ['required', 'string', 'max:255'],
            'amount_received' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}