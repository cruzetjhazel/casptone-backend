<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate handled in the controller (abort_unless isAdministrator)
    }

    public function rules(): array
    {
        return [
            'refund_status' => ['required', Rule::in(['pending', 'partial', 'full', 'denied'])],
            'refund_amount' => ['nullable', 'numeric', 'min:0.01'],
            'refund_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}