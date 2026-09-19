<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\ReportRequestedAction;
use App\Enums\ReportSeverity;
use App\Enums\ReportTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A Client reports on a Studio/Professional; a Photographer reports on
        // a Client. Neither can target their own account type. Matches the
        // dynamic targetOptions split in ReportProblem.tsx.
        $isPhotographer = $this->user()->account_type === AccountType::Photographer;

        $allowedTargets = $isPhotographer
            ? [ReportTargetType::Client, ReportTargetType::Booking, ReportTargetType::Payment, ReportTargetType::Bug, ReportTargetType::Other]
            : [ReportTargetType::Studio, ReportTargetType::Booking, ReportTargetType::Payment, ReportTargetType::Bug, ReportTargetType::Other];

        return [
            'target_type' => ['required', Rule::enum(ReportTargetType::class), Rule::in($allowedTargets)],
            // Required (and must be a real booking of this reporter's) when
            // reporting against a booking — e.g. a no-show report — so an
            // admin reviewing it always has an actual Booking to open rather
            // than a free-text ID that may not even exist. Not required for
            // other target types, which don't necessarily have one.
            'reference_id' => [
                'nullable', 'string', 'max:100',
                Rule::requiredIf(fn () => $this->input('target_type') === ReportTargetType::Booking->value),
            ],
            'reason' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::enum(ReportSeverity::class)],
            'details' => ['required', 'string', 'max:2000'],
            'requested_action' => ['required', Rule::enum(ReportRequestedAction::class)],
            'evidence' => ['nullable', 'array', 'max:3'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('target_type') !== \App\Enums\ReportTargetType::Booking->value || ! $this->filled('reference_id')) {
                return;
            }

            $isPhotographer = $this->user()->account_type === AccountType::Photographer;
            $ownerColumn = $isPhotographer ? 'photographer_id' : 'client_id';

            $owns = \App\Models\Booking::where('id', $this->input('reference_id'))
                ->where($ownerColumn, $this->user()->id)
                ->exists();

            if (! $owns) {
                $validator->errors()->add('reference_id', 'This booking does not belong to you.');
            }
        });
    }
}