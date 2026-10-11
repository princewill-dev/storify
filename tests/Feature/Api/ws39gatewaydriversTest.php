<?php

use App\Enums\VerificationStatus;
use App\Enums\WebhookEventType;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/*
| WS-39 — provider drivers.
|
| Every driver is exercised against a faked provider API. The point is not to
| prove the providers work — only their sandboxes can do that — but to pin the
| things that differ between them and are silently wrong when confused:
|
|   * amounts: kobo (Paystack, Squad) versus the major unit as a number
|     (Flutterwave, Korapay, Monnify), as USD cents (Bitfra) and as a decimal
|     string (Bachs);
|   * where the redirect URL hides in the response body;
|   * how each one signs a webhook — SHA512, SHA256, a plain hash echo, or a
|     timestamped HMAC with a replay window;
|   * which name a provider answers to when asked about a payment later.
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

function ws39BachsKeys(string $apiKey = 'sk_sandbox_abcdefghij'): GatewayCredentials
{
    return GatewayCredentials::make('bachs', [
        'api_key' => $apiKey,
        'webhook_secret' => 'whsec_bachs_value',
    ]);
}

/**
 * A Bachs webhook body, signed the way Bachs signs one.
 */
function ws39BachsWebhook(string $body, int $timestamp, string $secret = 'whsec_bachs_value', string $header = 'x-bachs-signature-v2'): WebhookRequest
{
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return $header === 'x-bachs-signature-v2'
        ? new WebhookRequest($body, ['x-bachs-signature-v2' => "t={$timestamp},v1={$signature}"])
        : new WebhookRequest($body, ['x-bachs-signature' => $signature, 'x-bachs-timestamp' => (string) $timestamp]);
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

test('every registered provider has a driver', function () {
    $implemented = app(PaymentGatewayManager::class)->implementedCodes();

    // Bachs used to be listed here without a driver, so its card, fee copy and
    // credential form led nowhere: no row was seeded, no connection could be
    // tested and no checkout could charge it. Every registered code is now
    // implemented, which is what makes the registry the catalogue it claims to
    // be — a merchant never sees a provider that cannot take money.
    expect($implemented)->toContain('paystack', 'flutterwave', 'korapay', 'squad', 'monnify', 'bitfra', 'bachs', 'bank_transfer')
        ->and(PaymentGatewayRegistry::keys())->toEqualCanonicalizing($implemented);
});

test('bachs is charged in decimal strings under pricing, not in kobo', function () {
    // 250000 kobo is ₦2,500. Sending 250000 where Bachs reads a decimal string
    // charges ₦250,000 — a hundred-fold overcharge, and the customer's bank is
    // the one that finds out.
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'checkout_id' => 'chk_123',
        'checkout_url' => 'https://checkout.bachs.io/chk_123',
        'status' => 'open',
    ])]);

    $result = ws39Driver('bachs')->initialize(ws39Intent(), ws39BachsKeys());

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toBe('https://checkout.bachs.io/chk_123')
        // Bachs names the payment itself, exactly as Bitfra does, and the
        // webhook quotes the same id — so callers round-trip this one.
        ->and($result->providerId)->toBe('chk_123')
        ->and($result->ownReference('API_TEST_0001'))->toBe('chk_123');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['pricing']['amount'] === '2500.00'
            && $body['pricing']['currency'] === 'NGN'
            && $body['reference'] === 'API_TEST_0001'
            && $body['success_url'] === 'https://shop.example.com/return'
            // Exactly one of product_cart and pricing, and we are not selling
            // anything from Bachs' catalogue.
            && ! array_key_exists('product_cart', $body);
    });
});

test('bachs is called on sandbox or live according to the key prefix', function () {
    Http::swap(new Factory);
    Http::fake(['bachs.io/*' => Http::response(['checkout_id' => 'chk_123', 'checkout_url' => 'https://checkout.bachs.io/chk_123'])]);

    ws39Driver('bachs')->initialize(ws39Intent(), ws39BachsKeys('sk_sandbox_abcdefghij'));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://sandbox-api.bachs.io/v1/')
        && $request->hasHeader('Authorization', 'Bearer sk_sandbox_abcdefghij'));

    Http::swap(new Factory);
    Http::fake(['bachs.io/*' => Http::response(['checkout_id' => 'chk_456', 'checkout_url' => 'https://checkout.bachs.io/chk_456'])]);

    $live = ws39Driver('bachs')->initialize(ws39Intent(), ws39BachsKeys('sk_live_abcdefghij'));

    expect($live->providerId)->toBe('chk_456');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.bachs.io/v1/'));
});

test('a rejected bachs checkout surfaces the provider message rather than retrying', function () {
    // A reference Bachs has seen before is refused for good, so the message it
    // gives is the only thing that explains the failure to the merchant.
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Reference already exists.'], 400)]);

    $result = ws39Driver('bachs')->initialize(ws39Intent(), ws39BachsKeys());

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Reference already exists.');
});

test('bachs reads the settled amount from the session charge, not the session', function () {
    // The session's own `amount` is what was asked for; the charge's is what
    // arrived. Reading the wrong one would confirm an underpayment.
    $session = fn (array $charge, string $status = 'open') => [
        'checkout_id' => 'chk_123',
        'status' => $status,
        'amount' => '9999.00',
        'charge' => $charge,
    ];

    $cases = [
        ['succeeded', VerificationStatus::PAID],
        ['accepted', VerificationStatus::PAID],
        ['overpaid', VerificationStatus::PAID],
        ['open', VerificationStatus::PENDING],
        ['processing', VerificationStatus::PENDING],
        ['incomplete', VerificationStatus::FAILED],
        ['failed', VerificationStatus::FAILED],
        ['refunded', VerificationStatus::FAILED],
        ['partially_refunded', VerificationStatus::FAILED],
    ];

    foreach ($cases as [$status, $expected]) {
        Http::swap(new Factory);
        Http::fake(['sandbox-api.bachs.io/*' => Http::response($session([
            'id' => 'chg_001',
            'status' => $status,
            'amount' => '2500.00',
            'currency' => 'NGN',
            'processing_fee' => '50.00',
        ]))]);

        $result = ws39Driver('bachs')->verify('chk_123', ws39BachsKeys());

        expect($result->status)->toBe($expected, "charge status {$status}");

        if ($expected === VerificationStatus::PAID) {
            expect($result->amountMinor)->toBe(250000)
                ->and($result->currency)->toBe('NGN')
                // The checkout id, not `chg_001`: `providerId` is written to
                // `gateway_reference` and matched against incoming webhooks, and
                // a webhook quotes the checkout id. The charge id, which a refund
                // is addressed by, is inside `raw`.
                ->and($result->providerId)->toBe('chk_123')
                ->and($result->raw['id'])->toBe('chg_001')
                ->and($result->feesMinor)->toBe(5000);
        }
    }
});

test('a bachs session with no charge is read from the session status', function () {
    // Until the customer pays there is no charge at all, which is the ordinary
    // state of a checkout that was just created.
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'checkout_id' => 'chk_123',
        'status' => 'open',
        'charge' => null,
    ])]);

    expect(ws39Driver('bachs')->verify('chk_123', ws39BachsKeys())->status)->toBe(VerificationStatus::PENDING);

    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['checkout_id' => 'chk_123', 'status' => 'expired', 'charge' => null])]);

    expect(ws39Driver('bachs')->verify('chk_123', ws39BachsKeys())->status)->toBe(VerificationStatus::FAILED);
});

test('a bachs checkout bachs has never seen is pending, not failed', function () {
    // 404 is the normal answer for an id created a second ago. Reading it as a
    // failure would abort a sale that is about to be paid — and at the till a
    // failed verification is terminal.
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Not found'], 404)]);

    expect(ws39Driver('bachs')->verify('chk_missing', ws39BachsKeys())->status)->toBe(VerificationStatus::PENDING);
});

test('a settled bachs charge with no readable amount is left pending', function () {
    // Settling on an unknown sum would credit the store for money nobody has
    // confirmed, so the payment stays open instead.
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'checkout_id' => 'chk_123',
        'charge' => ['id' => 'chg_001', 'status' => 'succeeded'],
    ])]);

    expect(ws39Driver('bachs')->verify('chk_123', ws39BachsKeys())->status)->toBe(VerificationStatus::PENDING);
});

test('a bachs webhook is accepted under either signature header, and during a rotation', function () {
    $body = json_encode([
        'type' => 'collection.succeeded',
        'data' => [
            'checkout_id' => 'chk_123',
            'charge_id' => 'chg_001',
            'reference' => 'API_TEST_0001',
            'status' => 'SUCCESS',
            'amount' => '2500.00',
            'currency' => 'NGN',
        ],
    ]);
    $now = time();

    $v2 = ws39Driver('bachs')->parseWebhook(ws39BachsWebhook($body, $now), ws39BachsKeys());

    expect($v2)->not->toBeNull()
        ->and($v2->type)->toBe(WebhookEventType::PAYMENT_SUCCESS)
        // The checkout id, because that is what verify() can be asked about.
        ->and($v2->reference)->toBe('chk_123')
        ->and($v2->providerId)->toBe('chg_001')
        ->and($v2->amountMinor)->toBe(250000);

    // A rotation re-signs with the new secret and keeps the old one valid, so
    // the header carries both and whichever secret we hold may be the second.
    $rotated = new WebhookRequest($body, ['x-bachs-signature-v2' => "t={$now},v1=".str_repeat('a', 64).',v1='.hash_hmac('sha256', $now.'.'.$body, 'whsec_bachs_value')]);

    expect(ws39Driver('bachs')->parseWebhook($rotated, ws39BachsKeys()))->not->toBeNull();

    // The legacy pair is still sent, and a connection holding only that
    // secret must keep settling.
    $legacy = ws39Driver('bachs')->parseWebhook(ws39BachsWebhook($body, $now, header: 'x-bachs-signature'), ws39BachsKeys());

    expect($legacy)->not->toBeNull()
        ->and($legacy->reference)->toBe('chk_123');
});

test('a bachs webhook with a wrong or stale signature is refused', function () {
    $body = json_encode(['type' => 'collection.succeeded', 'data' => ['checkout_id' => 'chk_123', 'amount' => '2500.00']]);
    $now = time();

    $wrong = new WebhookRequest($body, ['x-bachs-signature-v2' => "t={$now},v1=".hash_hmac('sha256', $now.'.'.$body, 'not-our-secret')]);

    expect(ws39Driver('bachs')->parseWebhook($wrong, ws39BachsKeys()))->toBeNull();

    // Two signatures appear in the header once a secret rotates; neither being
    // ours is still a rejection.
    $neither = new WebhookRequest($body, ['x-bachs-signature-v2' => "t={$now},v1=".str_repeat('b', 64).',v1='.str_repeat('c', 64)]);

    expect(ws39Driver('bachs')->parseWebhook($neither, ws39BachsKeys()))->toBeNull();

    // Genuine signature, but old enough to be a captured delivery being replayed.
    expect(ws39Driver('bachs')->parseWebhook(ws39BachsWebhook($body, $now - 3600), ws39BachsKeys()))->toBeNull();

    // A connection with no signing secret configured cannot verify anything,
    // so it accepts nothing rather than accepting everything.
    $unconfigured = GatewayCredentials::make('bachs', ['api_key' => 'sk_sandbox_abcdefghij']);

    expect(ws39Driver('bachs')->parseWebhook(ws39BachsWebhook($body, $now), $unconfigured))->toBeNull();
});

test('bachs maps its events onto settlement, failure and refund', function () {
    $now = time();
    $settle = fn (array $payload) => ws39Driver('bachs')->parseWebhook(
        ws39BachsWebhook(json_encode($payload), $now),
        ws39BachsKeys(),
    );

    // Whichever of the two arrives first may settle the order.
    $completed = $settle([
        'type' => 'checkout.completed',
        'data' => [
            'checkout_id' => 'chk_123',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_001', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    expect($completed->type)->toBe(WebhookEventType::PAYMENT_SUCCESS)
        ->and($completed->amountMinor)->toBe(250000)
        ->and($completed->providerId)->toBe('chg_001');

    // A free cart or a trial completes without money, so it is not a settlement.
    expect($settle(['type' => 'checkout.completed', 'data' => ['checkout_id' => 'chk_123', 'payment_status' => 'no_payment_required']])->type)
        ->toBe(WebhookEventType::IGNORED);

    expect($settle(['type' => 'checkout.expired', 'data' => ['checkout_id' => 'chk_123']])->type)->toBe(WebhookEventType::PAYMENT_FAILED)
        ->and($settle(['type' => 'collection.failed', 'data' => ['checkout_id' => 'chk_123']])->type)->toBe(WebhookEventType::PAYMENT_FAILED)
        ->and($settle(['type' => 'refund.paid', 'data' => ['checkout_id' => 'chk_123', 'charge_id' => 'chg_001']])->type)->toBe(WebhookEventType::REFUND)
        // An underpayment can still be accepted as final; deciding that is the
        // settlement floor's job, not this mapping's.
        ->and($settle(['type' => 'collection.underpaid', 'data' => ['checkout_id' => 'chk_123']])->type)->toBe(WebhookEventType::IGNORED)
        ->and($settle(['type' => 'something.new', 'data' => ['checkout_id' => 'chk_123']])->type)->toBe(WebhookEventType::IGNORED);
});

test('a bachs refund is addressed by the charge id, never by our reference', function () {
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['refund_id' => 'ref_001', 'status' => 'processing'])]);

    $result = ws39Driver('bachs')->refund(
        new RefundRequest(reference: 'API_TEST_0001', amountMinor: 100000, providerId: 'chg_001', reason: 'Customer changed their mind'),
        ws39BachsKeys(),
    );

    // A refund settles asynchronously, so this is acceptance, not the money.
    expect($result->success)->toBeTrue()
        ->and($result->refundId)->toBe('ref_001');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['charge_id'] === 'chg_001'
            && $body['amount'] === '1000.00'
            && $body['reason'] === 'Customer changed their mind'
            && str_starts_with($body['reference'], 'RF_');
    });
});

test('the bachs key and account are both checked when testing a connection', function () {
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Invalid API key'], 401)]);

    expect(ws39Driver('bachs')->testConnection(ws39BachsKeys())->success)->toBeFalse();

    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Account disabled'], 403)]);

    expect(ws39Driver('bachs')->testConnection(ws39BachsKeys())->message)->toContain('cannot take payments');

    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'acct_001'])]);

    expect(ws39Driver('bachs')->testConnection(ws39BachsKeys())->success)->toBeTrue();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/accounts/me'));
});
