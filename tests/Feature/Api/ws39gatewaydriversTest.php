<?php

use App\Enums\WebhookEventType;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\Http;

/*
| WS-39 — provider drivers.
|
| Every driver is exercised against a faked provider API. The point is not to
| prove the providers work — only their sandboxes can do that — but to pin the
| things that differ between them and are silently wrong when confused:
|
|   * amounts: kobo (Paystack, Squad) versus the major unit (Flutterwave,
|     Korapay, Monnify) versus USD cents (Bitfra);
|   * where the redirect URL hides in the response body;
|   * how each one signs a webhook — SHA512, SHA256, a plain hash echo, or a
|     timestamped HMAC with a replay window.
*/

function ws39Driver(string $code): object
{
    return app(PaymentGatewayManager::class)->driver($code);
}

function ws39Intent(int $amountMinor = 250000): PaymentIntent
{
    return new PaymentIntent(
        reference: 'API_TEST_0001',
        amountMinor: $amountMinor,
        currency: 'NGN',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
    );
}

test('paystack and squad send amounts in kobo, the others in the major unit', function () {
    // The units differ per provider and getting one wrong is silent: a ₦2,500
    // charge sent as 2500 to a provider expecting kobo is a ₦25 sale.
    $captured = [];

    Http::fake([
        'api.paystack.co/*' => function ($request) use (&$captured) {
            $captured['paystack'] = $request->data()['amount'] ?? null;

            return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://pay.test/x']]);
        },
        'api-d.squadco.com/*' => function ($request) use (&$captured) {
            $captured['squad'] = $request->data()['amount'] ?? null;

            return Http::response(['success' => true, 'data' => ['checkout_url' => 'https://squad.test/x']]);
        },
        'api.flutterwave.com/*' => function ($request) use (&$captured) {
            $captured['flutterwave'] = $request->data()['amount'] ?? null;

            return Http::response(['status' => 'success', 'data' => ['link' => 'https://flw.test/x']]);
        },
        'api.korapay.com/*' => function ($request) use (&$captured) {
            $captured['korapay'] = $request->data()['amount'] ?? null;

            return Http::response(['status' => true, 'data' => ['checkout_url' => 'https://kora.test/x']]);
        },
    ]);

    $nuisance = ['public_key' => 'pk_live_abcdefghij', 'secret_key' => 'sk_live_abcdefghij'];
    $flutterwave = ['secret_key' => 'FLWSECK-abcdefghij', 'public_key' => 'FLWPUBK-abcdefghij', 'webhook_secret' => 'hash'];

    ws39Driver('paystack')->initialize(ws39Intent(), GatewayCredentials::make('paystack', $nuisance));
    ws39Driver('squad')->initialize(ws39Intent(), GatewayCredentials::make('squad', $nuisance));
    ws39Driver('flutterwave')->initialize(ws39Intent(), GatewayCredentials::make('flutterwave', $flutterwave));
    ws39Driver('korapay')->initialize(ws39Intent(), GatewayCredentials::make('korapay', $nuisance));

    expect($captured['paystack'])->toBe(250000)
        ->and($captured['squad'])->toBe(250000)
        // ₦2,500, not 250000.
        ->and($captured['flutterwave'])->toBe(2500.0)
        ->and($captured['korapay'])->toBe(2500.0);
});

test('each driver reads the redirect url from wherever its provider hides it', function () {
    Http::fake([
        'api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://pay.test/x']]),
        'api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => ['link' => 'https://flw.test/x']]),
        'api.korapay.com/*' => Http::response(['status' => true, 'data' => ['checkout_url' => 'https://kora.test/x']]),
        'api-d.squadco.com/*' => Http::response(['success' => true, 'data' => ['checkout_url' => 'https://squad.test/x']]),
        'bitfra.net/*' => Http::response(['payment_id' => 'pay_123', 'payment_link' => 'https://bitfra.test/x']),
    ]);

    $keys = ['public_key' => 'pk_live_abcdefghij', 'secret_key' => 'sk_live_abcdefghij'];

    $usd = new PaymentIntent(
        reference: 'API_TEST_0002',
        amountMinor: 1000,
        currency: 'USD',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
    );

    expect(ws39Driver('paystack')->initialize(ws39Intent(), GatewayCredentials::make('paystack', $keys))->redirectUrl)->toBe('https://pay.test/x')
        ->and(ws39Driver('flutterwave')->initialize(ws39Intent(), GatewayCredentials::make('flutterwave', $keys))->redirectUrl)->toBe('https://flw.test/x')
        ->and(ws39Driver('korapay')->initialize(ws39Intent(), GatewayCredentials::make('korapay', $keys))->redirectUrl)->toBe('https://kora.test/x')
        ->and(ws39Driver('squad')->initialize(ws39Intent(), GatewayCredentials::make('squad', $keys))->redirectUrl)->toBe('https://squad.test/x')
        // Bitfra is the only USD provider, and the only one that names the id
        // it wants its webhook matched on.
        ->and(ws39Driver('bitfra')->initialize($usd, GatewayCredentials::make('bitfra', $keys))->providerId)->toBe('pay_123');
});

test('monnify authenticates first and then initialises', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token-abc'],
        ]),
        'sandbox.monnify.com/api/v1/merchant/transactions/init-transaction' => Http::response([
            'requestSuccessful' => true,
            'responseBody' => ['checkoutUrl' => 'https://monnify.test/x'],
        ]),
    ]);

    $credentials = GatewayCredentials::make('monnify', [
        'api_key' => 'MK_TEST_abcdefghij',
        'secret_key' => 'secret_abcdefghij',
        'contract_code' => '1234567890',
    ]);

    $result = ws39Driver('monnify')->initialize(ws39Intent(), $credentials);

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toBe('https://monnify.test/x');

    // The token is obtained with basic auth over the api key and secret.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/auth/login')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('MK_TEST_abcdefghij:secret_abcdefghij')));
});

test('a webhook with the wrong signature is rejected by every driver', function () {
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'API_TEST_0001']]);
    $request = new WebhookRequest($body, [
        'x-paystack-signature' => 'deadbeef',
        'x-korapay-signature' => 'deadbeef',
        'x-squad-signature' => 'deadbeef',
        'monnify-signature' => 'deadbeef',
        'verif-hash' => 'not-the-hash',
        'x-bixmerchant-signature' => 't='.time().',v1=deadbeef',
    ]);

    $keys = ['secret_key' => 'sk_live_abcdefghij', 'public_key' => 'pk_live_abcdefghij', 'webhook_secret' => 'the-hash'];

    foreach (['paystack', 'korapay', 'squad', 'monnify', 'flutterwave', 'bitfra'] as $code) {
        expect(ws39Driver($code)->parseWebhook($request, GatewayCredentials::make($code, $keys)))
            ->toBeNull("expected {$code} to reject a bad signature");
    }
});

test('a correctly signed webhook is normalised to a settlement event', function () {
    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'API_TEST_0001', 'amount' => 250000]];
    $body = json_encode($payload);

    $request = new WebhookRequest($body, [
        'x-paystack-signature' => hash_hmac('sha512', $body, 'sk_live_abcdefghij'),
    ]);

    $event = ws39Driver('paystack')->parseWebhook($request, GatewayCredentials::make('paystack', [
        'secret_key' => 'sk_live_abcdefghij',
    ]));

    expect($event)->not->toBeNull()
        ->and($event->type)->toBe(WebhookEventType::PAYMENT_SUCCESS)
        ->and($event->reference)->toBe('API_TEST_0001')
        ->and($event->amountMinor)->toBe(250000);
});

test('a replayed bitfra webhook is refused once its timestamp goes stale', function () {
    // The signature is genuine; only the age gives it away. Without a
    // freshness window a captured webhook could be replayed forever.
    $body = json_encode(['event' => 'payment.completed', 'payment_id' => 'pay_123']);
    $stale = time() - 3600;

    $request = new WebhookRequest($body, [
        'x-bixmerchant-signature' => 't='.$stale.',v1='.hash_hmac('sha256', $stale.'.'.$body, 'whsec_value'),
    ]);

    expect(ws39Driver('bitfra')->parseWebhook($request, GatewayCredentials::make('bitfra', ['webhook_secret' => 'whsec_value'])))
        ->toBeNull();

    $fresh = time();
    $freshRequest = new WebhookRequest($body, [
        'x-bixmerchant-signature' => 't='.$fresh.',v1='.hash_hmac('sha256', $fresh.'.'.$body, 'whsec_value'),
    ]);

    expect(ws39Driver('bitfra')->parseWebhook($freshRequest, GatewayCredentials::make('bitfra', ['webhook_secret' => 'whsec_value'])))
        ->not->toBeNull();
});

test('bitfra only settles on completion, not on a seen transfer', function () {
    // `payment.paid` means a transfer was spotted; `payment.completed` means it
    // cleared the confirmation threshold. Settling on the first would credit an
    // order for money that never confirmed.
    $seen = json_encode(['event' => 'payment.paid', 'payment_id' => 'pay_123']);
    $ts = time();

    $event = ws39Driver('bitfra')->parseWebhook(
        new WebhookRequest($seen, ['x-bixmerchant-signature' => 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$seen, 'whsec_value')]),
        GatewayCredentials::make('bitfra', ['webhook_secret' => 'whsec_value']),
    );

    expect($event->type)->toBe(WebhookEventType::IGNORED)
        ->and($event->isSettlement())->toBeFalse();
});

test('every registered provider has a driver except the ones deliberately held back', function () {
    $implemented = app(PaymentGatewayManager::class)->implementedCodes();

    expect($implemented)->toContain('paystack', 'flutterwave', 'korapay', 'squad', 'monnify', 'bitfra', 'bank_transfer')
        // Bachs is registered so its card and fee are visible, but has no
        // driver: its published API requires pre-created product ids and does
        // not document payment retrieval, webhook signing or refunds. It
        // therefore cannot be seeded, connected, or offered at checkout.
        ->and($implemented)->not->toContain('bachs');
});
