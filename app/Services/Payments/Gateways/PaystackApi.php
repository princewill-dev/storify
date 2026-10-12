<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\Data\GatewayCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * How a request to Paystack is addressed and authenticated — in one place.
 *
 * The counterpart to {@see BachsApi}, and it exists for the same reason: the
 * host and the credential are two facts that more than one class has to agree
 * on, and a class that gets either wrong fails in a way nothing reports. There
 * is no `X-Account-Id` equivalent here — Paystack names the account inside the
 * request body (`subaccount`), not in a header, which is why the split guard in
 * {@see PaystackGateway} checks the payload rather than the headers.
 */
final class PaystackApi
{
    public const BASE_URL = 'https://api.paystack.co';

    /**
     * A client authenticated with this credential set's secret key.
     *
     * Built per call and never cached: `PaystackService::usingGateway()` mutates
     * the instance it is called on, and a cached client in a long-lived service
     * is how one business's keys end up on another's charge. A fresh object
     * cannot leak.
     */
    public static function client(GatewayCredentials $credentials): PendingRequest
    {
        return Http::withToken((string) $credentials->get('secret_key'))
            ->timeout(30)
            ->acceptJson();
    }

    public static function baseUrl(): string
    {
        return self::BASE_URL;
    }
}
