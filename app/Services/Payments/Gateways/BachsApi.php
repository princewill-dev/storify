<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\Data\GatewayCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * How a request to Bachs is addressed and authenticated — in one place.
 *
 * Two things here decide whose money a call moves, and both are easy to get
 * wrong in a way nothing reports: which host the key belongs to (a sandbox key
 * against the live host is a 401, but a live key against the sandbox host is a
 * payment that never happens), and the `X-Account-Id` header without which a
 * direct charge silently becomes the platform's own.
 *
 * {@see BachsGateway} charges merchants; the Connect services create accounts and
 * pay them out. They are separate classes with separate jobs, and both must agree
 * on these two facts, so the facts live here rather than in whichever of them
 * was written first.
 */
final class BachsApi
{
    private const LIVE_URL = 'https://api.bachs.io/v1';

    private const SANDBOX_URL = 'https://sandbox-api.bachs.io/v1';

    /**
     * A client authenticated with this credential set's key.
     *
     * `$accountId` is for callers acting as an account that the credentials do
     * not themselves name — the Connect services hold the platform's credentials
     * and address a business's account per call. Passing null falls back to the
     * credentials' own account, which is how the merchant path attaches it.
     */
    public static function client(GatewayCredentials $credentials, ?string $accountId = null): PendingRequest
    {
        $client = Http::withToken((string) $credentials->get('api_key'))
            ->timeout(30)
            ->acceptJson();

        $accountId ??= $credentials->accountId();

        return $accountId === null || $accountId === ''
            ? $client
            : $client->withHeaders(['X-Account-Id' => $accountId]);
    }

    /** Test keys are not interchangeable with live ones, and the hosts differ. */
    public static function baseUrl(GatewayCredentials $credentials): string
    {
        $key = (string) $credentials->get('api_key');

        return str_starts_with($key, 'sk_sandbox_') ? self::SANDBOX_URL : self::LIVE_URL;
    }
}
