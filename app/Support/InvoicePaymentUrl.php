<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Store;

/**
 * The public link a customer opens to pay an invoice.
 *
 * The canonical page is the storefront SPA. The legacy Blade route survives
 * only for an invoice that resolves to no store at all — a business with no
 * storefront has nowhere else to send its customer.
 *
 * The token is the credential either way; the store in the storefront URL is
 * only the origin that serves the page.
 */
final class InvoicePaymentUrl
{
    /**
     * The store that will render the invoice, or null when there is none.
     *
     * An invoice paid through a specific store names it. A business-level
     * invoice (`store_id` null) is served by the business's first store, which
     * is also the order the management screen lists them in.
     */
    public static function storeFor(Invoice $invoice): ?Store
    {
        $store = $invoice->store;

        if ($store !== null && $store->status !== 'deleted') {
            return $store;
        }

        if ($invoice->store_id !== null) {
            return null;
        }

        return Store::query()
            ->where('business_id', $invoice->business_id)
            ->where('status', '!=', 'deleted')
            ->orderBy('id')
            ->first();
    }

    /**
     * The absolute payment URL, or null when the invoice has no token.
     *
     * Falls back to the legacy Blade route when no store exists. The legacy
     * controller redirects *away* only when {@see self::storeFor()} is
     * non-null, so the fallback cannot bounce back here.
     */
    public static function for(Invoice $invoice): ?string
    {
        $token = (string) $invoice->payment_token;

        if ($token === '') {
            return null;
        }

        $store = self::storeFor($invoice);

        if ($store !== null) {
            return SpaUrls::storefront($store->slug, 'invoice/'.$token);
        }

        return route('invoice.pay.show', ['token' => $token]);
    }
}
