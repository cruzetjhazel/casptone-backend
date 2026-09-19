<?php

namespace App\Enums;

/**
 * Refund state for a single Payment, recorded by an Admin (§16 client
 * protection / no-show handling). The platform does not process refunds
 * through a payment gateway — this only RECORDS that a refund was decided
 * on and (once marked Refunded) actually paid back to the client outside
 * the system (e.g. manual GCash send), matching the project's existing
 * "record, don't process" approach to payments.
 */
enum RefundStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Partial = 'partial';
    case Full = 'full';
    case Denied = 'denied';
}