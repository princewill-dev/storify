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

    /**
     * An outcome for money leaving Storify's balance.
     *
     * A third kind of event rather than a flavour of the two above, because it is
     * about a different movement: a charge is a customer paying us, a payout is us
     * paying a business. It has its own table (`payouts`), its own handler and its
     * own state machine, and the only thing the two share is the endpoint.
     *
     * It exists as a type at all so a provider's own vocabulary for it stays in
     * that provider's driver, alongside the charge events it already names. Before
     * it, a transfer delivery parsed to `IGNORED` and was recorded as an event
     * nothing acts on — so a reversal of an already-paid payout reached no handler
     * and the business's money stayed recorded as gone.
     */
    case PAYOUT = 'payout';

    /** Verified and understood as an event, but not one we act on. */
    case IGNORED = 'ignored';
}
