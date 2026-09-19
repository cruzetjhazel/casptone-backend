<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\RecordPaymentRefundAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPaymentRefundRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        return $this->success(
            PaymentResource::collection(Payment::latest()->get())
        );
    }

    /**
     * Records (does not process) a refund decision for a payment — see §16
     * client-protection / no-show handling. The admin decides pending,
     * partial, full, or denied; actually sending the money back happens
     * outside the platform, same as every other payment in this system.
     */
    public function refund(RecordPaymentRefundRequest $request, Payment $payment, RecordPaymentRefundAction $action)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $payment = $action->execute(
            $payment,
            $request->user(),
            $request->validated('refund_status'),
            $request->validated('refund_amount'),
            $request->validated('refund_notes'),
        );

        return $this->success(new PaymentResource($payment), 'Refund status recorded.');
    }
}