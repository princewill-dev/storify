<?php

namespace App\Services\Payments\Data;

/**
 * Whose connection a webhook URL named, and how far a payment it reports may
 * reach.
 *
 * The provider-only endpoint has no scope at all — it cannot tell whose account
 * a payment belongs to, so it tries every stored secret for that provider until
 * one verifies the signature. A scoped URL answers that question up front, which
 * is the whole point of giving a business a URL to paste into its provider's
 * dashboard.
 *
 * ## Why `$storeOwnsKeys` exists rather than just `$storeId`
 *
 * A store with its own provider credentials has its own account, and that
 * account is notified about that store's payments and nothing else — so only
 * that store's orders may settle through it.
 *
 * A store without its own credentials is charging through the business's shared
 * account, which is the account the provider posts *every* store's payments
 * about. Narrowing settlement to the store in the URL there would silently drop
 * the rest, so the reach stays business-wide and `$storeOwnsKeys` is false.
 */
final class WebhookScope
{
    public function __construct(
        public readonly int $businessId,
        /** The store named in the URL, when the URL named one. */
        public readonly ?int $storeId = null,
        /** Whether that store has its own credentials for this provider. */
        public readonly bool $storeOwnsKeys = false,
    ) {}
}
