<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gcash_account_name' => ['required', 'string', 'max:150'],
            'gcash_account_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
            // Not required on every submission — a photographer updating their
            // account name/number shouldn't be forced to re-upload the QR.
            // The controller enforces it's present on first-time creation.
            'gcash_qr_code' => ['sometimes', 'image', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'gcash_account_name.required' => 'GCash account name is required.',
            'gcash_account_name.max' => 'GCash account name may not be longer than 150 characters.',
            'gcash_account_number.required' => 'GCash account number is required.',
            'gcash_account_number.regex' => 'Enter a valid PH mobile number: 11 digits starting with 09 (e.g. 09171234567).',
            'gcash_qr_code.image' => 'The GCash QR code must be an image (PNG or JPG).',
            'gcash_qr_code.max' => 'The GCash QR code image must be 4MB or smaller.',
        ];
    }
}