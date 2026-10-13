<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\Data\GatewayCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * How a request to Bachs is addressed and authenticated — in one place.
 *
 * The host is read from configuration and from nowhere else. Bachs publishes two
 * of them, and which one a call goes to used to be inferred from the API key's
 * `sk_sandbox_` / `sk_live_` prefix. That worked, and it was still the wrong
 * shape: it made the environment a property of a *credential*, so a key pasted in
 * from a different Bachs account silently redirected every call, and there was
 * nowhere to read off which host the platform was actually talking to.
 * `services.bachs.base_url` is that place — one value, set once, in `.env`.
 *
 * Two consequences are worth knowing before changing it:
 *
 * - **The default is live.** A sandbox key against the live host is a 401; a
 *   live key against the sandbox host is a charge that never happens. So an
 *   unset variable fails loudly and a wrong one does not, and the fallback is
 *   the loud direction.
 * - **It is platform-wide, so it covers merchant connections too.** A business
 *   that connects its own Bachs account is addressed to this same host. Point it
 *   at sandbox and every business's charges, payouts and account lookups go
 *   there as well. That is the intent — one platform speaks to one Bachs
 *   environment — but it is the reason this is not a per-credential setting.
 *
 * The `X-Account-Id` header used to be the second such fact — the thing that made
 * a charge land in a business's own account rather than the platform's. Every
 * call this makes now addresses the platform's account, because that is where
 * the money is supposed to land, so the header is gone rather than passed as
 * null. A caller that needs it back is a caller that needs the deleted rail.
 */
final class BachsApi
{
    /**
     * The last resort when nothing is configured — not a mode.
     *
     * A constant rather than a second switch, because an empty `BACHS_BASE_URL`
     * means "not set" the same way an empty secret means "not configured", and
     * it must not mean "address calls to the empty host".
     */
    public const LIVE_URL = 'https://api.bachs.io/v1';

    /**
     * A client authenticated with this credential set's key.
     */
    public static function client(GatewayCredentials $credentials): PendingRequest
    {
        return Http::withToken((string) $credentials->get('api_key'))
            ->timeout(30)
            ->acceptJson();
    }

    /**
     * The host every Bachs call is addressed to, whichever key it carries.
     *
     * Every caller appends a `/path`, so a trailing slash in the variable — the
     * shape a URL is usually pasted in — is trimmed rather than left to produce
     * `.../v1//payouts`, which is a 404 that reads like the endpoint moved.
     */
    public static function baseUrl(): string
    {
        $configured = trim((string) config('services.bachs.base_url'));

        return rtrim($configured === '' ? self::LIVE_URL : $configured, '/');
    }
}
