<?php

namespace App\Support\Payments;

use App\Models\Business;
use App\Models\Store;

/**
 * The webhook URL shown to a business, and posted to the provider by us.
 *
 * One place builds it, because the string a merchant copies into Bitfra's
 * dashboard and the string we hand Korapay as its `notification_url` must be the
 * same one — two derivations would eventually disagree, and the disagreement
 * would surface as payments settling for one merchant and not another.
 *
 * ## Why the base URL is configured rather than derived from the request
 *
 * This value is persisted into a third party's dashboard and has to stay correct
 * for as long as it is there, so it must not depend on who happened to be asking
 * when it was rendered. Nothing in this app configures `TrustProxies`, and the
 * only scheme handling is `URL::forceScheme('https')` guarded by
 * `environment('production')` — so behind the Cloudflare front a request-derived
 * URL can come out with the wrong scheme, and would then be cached in someone
 * else's settings page. `APP_URL` is the deployment's own statement of where the
 * API lives; use it.
 *
 * ## Why these identifiers
 *
 * `store_id` and `business_code` are both generated once, at creation, and never
 * change — so a URL already pasted into a provider's dashboard keeps working.
 * `slug` is deliberately not used: it is derived from the name and can change.
 * A connection's row id would be worse still, since reconnecting issues a new one
 * and would break every URL already in the wild.
 *
 * The URL is **not a secret**. It selects which stored secret a signature is
 * tested against; the signature is the gate. Leaking it buys an attacker one
 * HMAC comparison and some log noise, never money — and it has to be freely
 * copy-pasteable, so treating it as one would defeat the feature.
 */
final class PaymentWebhookUrl
{
    public static function forStore(string $provider, Store $store): string
    {
        return self::build($provider, (string) $store->store_id);
    }

    /**
     * The URL for a business whose provider account is shared by every store.
     *
     * A provider dashboard holds one webhook URL per account, so a business-wide
     * connection has nowhere to put a per-store one. This is what goes there.
     */
    public static function forBusiness(string $provider, Business|string $business): string
    {
        $code = $business instanceof Business ? $business->business_code : $business;

        return self::build($provider, (string) $code);
    }

    private static function build(string $provider, string $scope): string
    {
        // Named route, so a change to the path cannot leave merchant dashboards
        // pointing at a 404. `absolute: false` keeps it a path we can prefix
        // with the configured origin.
        $path = route('webhooks.payments.scope', [
            'provider' => $provider,
            'scope' => $scope,
        ], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }
}
