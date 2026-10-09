<?php

namespace App\Http\Requests;

use App\Models\CustomPackageConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomPackageConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'pricing_model' => ['sometimes', 'nullable', Rule::in(['fixed', 'hourly', 'per_day', 'per_person'])],
            'base_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'buffer_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:480'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'outdoor_hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'base_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'min_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'max_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24', 'gte:min_hours'],
            // Per-day / per-person rate, and the agreed coverage per session
            // (scheduling only — never derived from price). Blank = duration
            // is confirmed by the photographer per booking.
            'unit_rate' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'coverage_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'max_people' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'allows_multiple_sessions' => ['sometimes', 'boolean'],
            'max_sessions' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:15'],
            'base_fee_mode' => ['sometimes', 'nullable', Rule::in(['once', 'per_schedule'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Query the stored config directly. Reading $this->user()->customPackageConfig
            // would cache a null relation on the user object when no config exists
            // yet, and the controller's later read of that relation would go stale.
            $stored = $this->user()
                ? CustomPackageConfig::where('user_id', $this->user()->id)->first()
                : null;

            // The value the config will have after this save: the request's if
            // it sent that key, otherwise what is already stored.
            $value = fn (string $key) => $this->has($key) ? $this->input($key) : $stored?->{$key};

            // Same rule as UpdateCustomPackageConfigAction: an explicit model
            // wins; older clients that send base_fee / hourly_rate without one
            // are read as hourly (rate present) or fixed; otherwise use stored.
            $model = $this->input('pricing_model');
            if (! $model) {
                if ($this->has('hourly_rate') || $this->has('base_fee')) {
                    $model = $this->input('hourly_rate') !== null ? 'hourly' : 'fixed';
                } else {
                    $model = $stored?->pricing_model ?: ($stored?->hourly_rate !== null ? 'hourly' : 'fixed');
                }
            }

            if ($this->boolean('enabled')) {
                [$field, $message] = match ($model) {
                    'hourly' => ['hourly_rate', 'Rate per hour is required while hourly pricing is on.'],
                    'per_day' => ['unit_rate', 'Rate per day is required while per-day pricing is on.'],
                    'per_person' => ['unit_rate', 'Rate per person is required while per-person pricing is on.'],
                    default => ['base_fee', 'Base session fee is required while fixed pricing is on.'],
                };
                $v = $value($field);
                if (! is_numeric($v) || (float) $v <= 0) {
                    $validator->errors()->add($field, $message);
                }
            }

            if ($this->boolean('allows_multiple_sessions') && $model === 'fixed') {
                $validator->errors()->add('allows_multiple_sessions', 'Multiple sessions are not available with fixed pricing.');
            }
        });
    }
}