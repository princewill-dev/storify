<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\Data\GatewayCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * How a request to Bachs is addressed and authenticated — in one place.
 *
 * One thing here decides whose money a call moves, and it is easy to get wrong
 * in a way nothing reports: which host the key belongs to. A sandbox key against
 * the live host is a 401, but a live key against the sandbox host is a payment
 * that never happens.
 *
 * The `X-Account-Id` header used to be the second such fact — the thing that made
 * a charge land in a business's own account rather than the platform's. Every
 * call this makes now addresses the platform's account, because that is where
 * the money is supposed to land, so the header is gone rather than passed as
 * null. A caller that needs it back is a caller that needs the deleted rail.
 */
final class BachsApi
{
    private const LIVE_URL = 'https://api.bachs.io/v1';

    private const SANDBOX_URL = 'https://sandbox-api.bachs.io/v1';

    /**
     * A client authenticated with this credential set's key.
     */
    public static function client(GatewayCredentials $credentials): PendingRequest
    {
        return Http::withToken((string) $credentials->get('api_key'))
            ->timeout(30)
            ->acceptJson();
    }

    /** Test keys are not interchangeable with live ones, and the hosts differ. */
    public static function baseUrl(GatewayCredentials $credentials): string
    {
        $key = (string) $credentials->get('api_key');

        return str_starts_with($key, 'sk_sandbox_') ? self::SANDBOX_URL : self::LIVE_URL;
    }
}
