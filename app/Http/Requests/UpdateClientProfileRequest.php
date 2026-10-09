<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // email and phone_number are intentionally NOT editable here, so they have no
        // rules and never appear in validated(). withValidator() below rejects any
        // attempt to submit a different value instead of silently ignoring it.
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'birthday' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'profile_photo' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();

            if ($this->has('email')
                && mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $user->email)) {
                $validator->errors()->add('email', "Your email address can't be changed here. Contact support to update it.");
            }

            if ($this->has('phone_number')
                && $this->normalizePhone($this->input('phone_number')) !== $this->normalizePhone($user->phone_number)) {
                $validator->errors()->add('phone_number', "Your registered number can't be changed here. Contact support if it needs updating.");
            }
        });
    }

    /** "+63 917-123-4567" and "09171234567" count as the same number. */
    private function normalizePhone(mixed $phone): string
    {
        $phone = preg_replace('/[\s-]/', '', (string) $phone);

        return str_starts_with($phone, '+63') ? '0'.substr($phone, 3) : $phone;
    }
}