<?php

namespace App\Enums;

/**
 * What a provider's webhook means, once its own vocabulary is stripped off.
 *
 * Every provider names these differently — Paystack says `charge.success`,
 * Bitfra says `payment.completed`, others say `paid`. Normalising here is what
 * lets one processor settle every provider, and keeps each provider's naming
 * inside its own driver.
 */
enum WebhookEventType: string
{
    case PAYMENT_SUCCESS = 'payment_success';
    case PAYMENT_FAILED = 'payment_failed';
    case REFUND = 'refund';

    /** Verified and understood as an event, but not one we act on. */
    case IGNORED = 'ignored';
}
