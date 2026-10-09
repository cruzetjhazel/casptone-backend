<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reference_number')) {
            $this->merge([
                'reference_number' => preg_replace('/[\s-]+/', '', (string) $this->input('reference_number')),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'reference_number.regex' => 'The GCash reference number must be exactly 13 digits.',
        ];
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::in(['half', 'full'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference_number' => ['required', 'string', 'regex:/^\d{13}$/'],
            'payer_name' => ['required', 'string', 'max:150'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}