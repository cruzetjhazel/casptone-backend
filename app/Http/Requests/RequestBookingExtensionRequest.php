<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestBookingExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Hard cap of 12 extra hours per request — matches the max a
            // photographer can configure for an entire sliding-hours
            // booking (see CustomPackageConfigRequest::max_hours) as a
            // sane upper bound; there's no dedicated "max extension hours"
            // setting.
            'additional_hours' => ['required', 'integer', 'min:1', 'max:12'],
            // Which schedule entry to extend, for a multi-schedule booking
            // (e.g. "Wedding, Oct 20" vs "Prenup, Oct 5"). Omit/null for a
            // single-schedule booking — the action defaults to the primary
            // schedule, which is what every booking had before multi-schedule
            // support existed. Existence + ownership (must belong to this
            // booking) is checked in RequestBookingExtensionAction, since
            // that needs the route's $booking, which isn't available here.
            'schedule_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
