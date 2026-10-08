<?php

namespace App\Services\Payments\Data;

use App\Enums\WebhookEventType;

/**
 * A provider's webhook, normalised.
 *
 * Returning one of these from `parseWebhook()` is also how a driver proves a
 * webhook belongs to a given set of credentials: if the signature does not
 * match, the driver returns null and the caller tries the next connection.
 * That is the only mechanism available for providers whose payload carries no
 * merchant identifier.
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly WebhookEventType $type,
        public readonly ?string $reference = null,
        public readonly ?string $providerId = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?int $feesMinor = null,
        public readonly ?string $paidAt = null,
        public readonly array $raw = [],
    ) {}

    public function isSettlement(): bool
    {
        return $this->type === WebhookEventType::PAYMENT_SUCCESS;
    }
}
