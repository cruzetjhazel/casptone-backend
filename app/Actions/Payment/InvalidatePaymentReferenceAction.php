<?php

namespace App\Actions\Payment;

use App\Enums\PhotographerPaymentReferenceStatus;
use App\Models\PhotographerPaymentReference;
use Illuminate\Validation\ValidationException;

class InvalidatePaymentReferenceAction
{
    public function execute(PhotographerPaymentReference $reference): PhotographerPaymentReference
    {
        if ($reference->status !== PhotographerPaymentReferenceStatus::Available) {
            throw ValidationException::withMessages([
                'status' => ['Only unused, available references can be invalidated.'],
            ]);
        }

        $reference->update(['status' => PhotographerPaymentReferenceStatus::Invalidated]);

        return $reference->fresh();
    }
}