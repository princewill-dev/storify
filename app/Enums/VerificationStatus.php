<?php

namespace App\Enums;

/**
 * The outcome of asking a provider about a payment.
 *
 * PENDING is not a failure and not an absence: for manual bank transfer it is
 * the honest permanent answer, because settlement is confirmed by a person on
 * the management side rather than by the provider. Collapsing it into FAILED
 * would make a waiting transfer look rejected.
 */
enum VerificationStatus: string
{
    case PAID = 'paid';
    case PENDING = 'pending';
    case FAILED = 'failed';
}
