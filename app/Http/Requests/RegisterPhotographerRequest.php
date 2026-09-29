<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterPhotographerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'email.regex' => 'Only @gmail.com addresses are accepted.',
            'email.unique' => 'This email is already registered.',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'regex:/^[a-z0-9._%+\-]+@gmail\.com$/', Rule::unique('users', 'email')],
            'phone_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'photographer_type' => ['required', Rule::in(['freelancer', 'studio'])],
            'terms_accepted' => ['required', 'accepted'],
        ];
    }
}