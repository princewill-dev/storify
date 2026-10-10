<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\PaymentWebhookUrl;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-39 — payment webhooks, over HTTP.
|
| Webhooks were tested only at the driver level, so nothing exercised the
| controller, the settlement processor, or the thing businesses are actually
| given: a URL to paste into a provider's dashboard. That is what this file
| covers — the two URL shapes, who a signature is allowed to settle for, and the
| payload the management screen renders.
|
| Paystack carries almost all of it. Its signature is the simplest of the seven
| (an HMAC of the raw body), and its verification is faked the way the rest of
| the suite fakes it, because `settle()` re-verifies with the provider rather
| than trusting the body — a webhook that is only signed correctly must not be
| enough to move money.
*/

/*
 * The fixtures below mirror the ones in the WS-39 gateway suite rather than
 * reusing them. Pest only loads the files a run actually includes, so calling
 * into another test file's helpers works for a full-suite run and fails the
 * moment somebody filters to this file — which is how a suite starts depending
 * on its own run order.
 */

function wh39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'wh39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function wh39Paystack(): array
{
    return ['public_key' => 'pk_live_test1234567890', 'secret_key' => 'sk_live_test1234567890'];
}

function wh39Connect(Business $business, string $code, array $config, bool $active = true): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => $active,
            'config' => json_encode(CredentialCipher::encrypt($config, PaymentGatewayRegistry::secretKeysFor($code))),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/**
 * A store row for one provider. A null config means the store inherits the
 * business's keys; a config means it charges through its own account.
 */
function wh39Assign(Business $business, Store $store, string $code, bool $active = true, ?array $config = null): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('store_payment_method')->updateOrInsert(
        ['store_id' => $store->id, 'payment_method_id' => $method->id],
        [
            'business_id' => $business->id,
            'is_active' => $active,
            'config' => $config === null
                ? null
                : json_encode(CredentialCipher::encrypt($config, PaymentGatewayRegistry::secretKeysFor($code))),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function wh39Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * A pending order for one store, with nothing paid against it.
 */
function wh39Order(Store $store, array $attributes = []): Order
{
    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'WH39-'.fake()->unique()->numerify('######'),
        'subtotal' => 2500,
        'total' => 2500,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

/**
 * A pending transaction against one order.
 */
function wh39Transaction(Order $order, string $provider = 'paystack', array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'reference' => 'API_WH39_'.Str::upper(Str::random(10)),
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'payment_method_id' => PaymentMethod::where('code', $provider)->value('id'),
        'amount' => 2500,
        'currency' => 'NGN',
        'status' => TransactionStatus::PENDING,
    ], $attributes));
}

/**
 * A Paystack-shaped signed payload.
 *
 * The signature is taken over `json_encode()` of the same array the request is
 * posted with, which is byte-for-byte what `postJson` sends — a mismatch here
 * would look exactly like a broken signature check.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function wh39Signed(array $payload, string $secret = 'sk_live_test1234567890'): array
{
    return [$payload, ['x-paystack-signature' => hash_hmac('sha512', json_encode($payload), $secret)]];
}

/**
 * A `charge.success` for one reference.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function wh39ChargeSuccess(string $reference, int $amountKobo = 250000, string $secret = 'sk_live_test1234567890'): array
{
    return wh39Signed([
        'event' => 'charge.success',
        'data' => ['reference' => $reference, 'amount' => $amountKobo, 'currency' => 'NGN'],
    ], $secret);
}

/**
 * Fake both Paystack reads `doubleVerifyPayment()` makes.
 *
 * `Http::swap(new Factory)` first: `Http::fake()` merges into whatever fake is
 * installed and the earliest stub wins, so without a fresh factory a previous
 * test's fake would answer for this one.
 */
function wh39FakePaystack(string $status = 'success', int $amountKobo = 250000): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($status, $amountKobo) {
        $url = $request->url();

        if (str_contains($url, 'transaction/verify')) {
            $reference = Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');

            return Http::response([
                'status' => true,
                'data' => [
                    'id' => 445566,
                    'status' => $status,
                    'amount' => $amountKobo,
                    'currency' => 'NGN',
                    'reference' => $reference,
                    'paid_at' => now()->toIso8601String(),
                ],
            ]);
        }

        // The list endpoint the second confirmation scans.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $reference = (string) ($query['reference'] ?? '');

        return Http::response([
            'status' => true,
            'data' => [[
                'id' => 445566,
                'status' => $status,
                'amount' => $amountKobo,
                'currency' => 'NGN',
                'reference' => $reference,
            ]],
        ]);
    });
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/* ------------------------------------------------- the URL a business is given */

test('a scoped URL built for a store names that store', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    $store = wh39Store($owner);

    $url = PaymentWebhookUrl::forStore('paystack', $store);

    expect($url)->toContain('/webhooks/payments/paystack/'.$store->store_id)
        ->and($url)->toStartWith(rtrim((string) config('app.url'), '/'))
        // Derived from config, never from the request host: this value is
        // pasted into a third party's dashboard and has to be right the first
        // time, whatever a proxy does to the request scheme.
        ->and(PaymentWebhookUrl::forBusiness('paystack', $business))
        ->toContain('/webhooks/payments/paystack/'.$business->business_code);
});

test('the management payload offers a webhook URL per provider, and none where there is nothing to notify', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    $store = wh39Store($owner);
    // Bank transfer is settled by a person reading a bank slip: there is no
    // provider to post an alert, so there is nothing to paste anywhere.
    wh39Assign($business, $store, 'bank_transfer', true);

    $response = $this->withToken(wh39Token($owner))
        ->getJson('/api/v1/management/payment-gateways?store_id='.$store->id)
        ->assertOk();

    $providers = collect($response->json('data.providers'))->keyBy('code');

    expect($providers['paystack']['webhook_url'])->toContain($store->store_id)
        ->and($providers['bank_transfer']['webhook_url'])->toBeNull()
        // The whole payload, URLs included, must stay free of the key itself.
        ->and(json_encode($response->json('data.providers')))->not->toContain('sk_live_test1234567890');
});

test('a provider can be connected before it has issued the secret its webhook needs', function () {
    // The bitfra case, and the whole reason this screen had to show the URL
    // first: the signing secret does not exist until a webhook URL has been
    // saved with Bitfra, and Bitfra shows it once. Demanding it at connect made
    // the connection impossible to complete in the order the provider requires.
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(wh39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bitfra', [
            'is_enabled' => true,
            'config' => ['api_key' => 'bix-0123456789abcdef-0123456789abcdef'],
        ])
        ->assertOk();

    $catalogue = collect(
        $this->withToken(wh39Token($owner))
            ->getJson('/api/v1/management/payment-gateways')
            ->assertOk()
            ->json('data.catalogue')
    )->keyBy('code');

    $secret = collect($catalogue['bitfra']['fields'])->firstWhere('key', 'webhook_secret');

    expect($secret['optional'])->toBeTrue()
        // The order of operations lives with the provider, not in the screen.
        ->and($catalogue['bitfra']['webhook_note'])->toContain('signing secret')
        // Flutterwave's hash is not optional: it is found in the dashboard
        // whenever you like, so there is no reason to connect without it.
        ->and(collect($catalogue['flutterwave']['fields'])->firstWhere('key', 'webhook_secret')['optional'])
        ->toBeFalse();
});

/* ------------------------------------------------------------- settlement */

test('a webhook posted to a store URL settles that store order', function () {
    wh39FakePaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    $store = wh39Store($owner);
    $order = wh39Order($store);
    $transaction = wh39Transaction($order);

    [$payload, $headers] = wh39ChargeSuccess($transaction->reference);

    $this->postJson('/webhooks/payments/paystack/'.$store->store_id, $payload, $headers)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $transaction->refresh();
    $store->refresh();

    expect($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        ->and($transaction->paid_at)->not->toBeNull()
        // Credited once, in kobo, and recorded with what the balance was either
        // side of it.
        ->and((int) $store->balance)->toBe(250000)
        ->and($transaction->balance_updated_at)->not->toBeNull()
        ->and((int) $transaction->store_balance_before)->toBe(0)
        ->and((int) $transaction->store_balance_after)->toBe(250000)
        // Decimal-cast, so it comes back as a string.
        ->and((float) $order->fresh()->amount_paid)->toBe(2500.0);
});

test('a business-wide URL settles an order belonging to any of its stores', function () {
    wh39FakePaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    // The shared-account case: every store is charged through the business's
    // keys, so every store's payments arrive at the one business URL.
    $store = wh39Store($owner);
    $order = wh39Order($store);
    $transaction = wh39Transaction($order);

    [$payload, $headers] = wh39ChargeSuccess($transaction->reference);

    $this->postJson('/webhooks/payments/paystack/'.$business->business_code, $payload, $headers)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((int) $store->fresh()->balance)->toBe(250000);
});

test('the provider-only URL still settles, unchanged', function () {
    // Registered in live Paystack dashboards. Changing what it does would stop
    // settlement for every business that pasted it.
    wh39FakePaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    $store = wh39Store($owner);
    $transaction = wh39Transaction(wh39Order($store));

    [$payload, $headers] = wh39ChargeSuccess($transaction->reference);

    $this->postJson('/webhooks/payments/paystack', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);
});

test('a signature from another connection cannot settle this scope', function () {
    // The defect the scoped URL exists to close: with only the provider in the
    // path, a connection's webhook was checked against every stored secret on
    // the platform, so a verified signature could settle a stranger's order.
    wh39FakePaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($otherBusiness, 'paystack', [
        'public_key' => 'pk_live_other1234567',
        'secret_key' => 'sk_live_other1234567',
    ]);

    $store = wh39Store($owner);
    $transaction = wh39Transaction(wh39Order($store));

    // Correctly signed — with somebody else's key.
    [$payload, $headers] = wh39ChargeSuccess($transaction->reference, 250000, 'sk_live_other1234567');

    $this->postJson('/webhooks/payments/paystack/'.$store->store_id, $payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('status', 'unverified');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::PENDING)
        ->and((int) $store->fresh()->balance)->toBe(0);
});

test('a store charging with its own keys refuses a webhook about another store', function () {
    wh39FakePaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    $ownAccount = wh39Store($owner);
    wh39Assign($business, $ownAccount, 'paystack', true, [
        'public_key' => 'pk_live_own123456789',
        'secret_key' => 'sk_live_own123456789',
    ]);

    // A sibling store, charged through the business's shared account.
    $shared = wh39Store($owner);
    $transaction = wh39Transaction(wh39Order($shared));

    [$payload, $headers] = wh39ChargeSuccess($transaction->reference, 250000, 'sk_live_own123456789');

    // Signed with the dedicated account's key, so the signature is genuine —
    // but that account was never told about this store, so it cannot speak for
    // an order it did not charge.
    $this->postJson('/webhooks/payments/paystack/'.$ownAccount->store_id, $payload, $headers)
        ->assertOk()
        ->assertJsonPath('status', 'transaction not found');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::PENDING)
        ->and((int) $shared->fresh()->balance)->toBe(0);
});

test('a replayed webhook does not credit the balance twice', function () {
    wh39FakePaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    $store = wh39Store($owner);
    $transaction = wh39Transaction(wh39Order($store));

    [$payload, $headers] = wh39ChargeSuccess($transaction->reference);
    $uri = '/webhooks/payments/paystack/'.$store->store_id;

    $this->postJson($uri, $payload, $headers)->assertOk()->assertJsonPath('status', 'success');

    // Providers retry a webhook they think failed, and the client's own verify
    // callback can land on the same payment. Neither may pay the business twice.
    $this->postJson($uri, $payload, $headers)->assertOk()->assertJsonPath('status', 'already processed');

    $order = $transaction->fresh()->order;

    expect((int) $store->fresh()->balance)->toBe(250000)
        ->and($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((float) $order->fresh()->amount_paid)->toBe(2500.0);
});

test('an unknown scope answers exactly as a bad signature does', function () {
    // Not a 404: walking store and business codes could otherwise tell a
    // stranger which ones exist. The two responses have to be identical,
    // because any difference is the oracle.
    wh39FakePaystack();

    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'API_WH39_NOPE']];

    $unknownScope = $this->postJson('/webhooks/payments/paystack/st_9999999999', $payload, [
        'x-paystack-signature' => hash_hmac('sha512', json_encode($payload), 'sk_live_test1234567890'),
    ]);

    $badSignature = $this->postJson('/webhooks/payments/paystack', $payload, [
        'x-paystack-signature' => 'deadbeef',
    ]);

    expect($unknownScope->status())->toBe($badSignature->status())
        ->and($unknownScope->getContent())->toBe($badSignature->getContent())
        ->and($unknownScope->status())->toBe(401);
});

test('an unverified reference is acknowledged rather than reported as an error', function () {
    wh39FakePaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'paystack', wh39Paystack());

    $store = wh39Store($owner);

    // A webhook for a transaction that does not exist yet — a race the provider
    // must not be asked to retry, so this is a 200.
    [$payload, $headers] = wh39ChargeSuccess('API_WH39_UNKNOWN');

    $this->postJson('/webhooks/payments/paystack/'.$store->store_id, $payload, $headers)
        ->assertOk()
        ->assertJsonPath('status', 'transaction not found');
});

/* ------------------------------------------------------------ provider quirks */

test('korapay is told about the alert URL instead of the page the customer lands on', function () {
    // It was sent the browser return URL, so every Korapay alert was posted at
    // a page saying "you can close this window" and lost.
    Http::swap(new Factory);
    Http::fake(['api.korapay.com/*' => Http::response([
        'status' => true,
        'data' => ['checkout_url' => 'https://checkout.korapay.com/ws39'],
    ])]);

    $driver = app(PaymentGatewayManager::class)->driver('korapay');
    $credentials = GatewayCredentials::make('korapay', ['secret_key' => 'sk_test_korapay']);

    $driver->initialize(new PaymentIntent(
        reference: 'API_WH39_KORAPAY',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.test',
        callbackUrl: 'https://shop.example.test/return',
        webhookUrl: 'https://api.example.test/webhooks/payments/korapay/st_0000001234',
    ), $credentials);

    Http::assertSent(fn (Request $request) => $request['notification_url']
        === 'https://api.example.test/webhooks/payments/korapay/st_0000001234'
        && $request['redirect_url'] === 'https://shop.example.test/return');

    // A caller with no URL to give still gets a valid request rather than a
    // crash — the fallback is the old behaviour, not a null.
    Http::swap(new Factory);
    Http::fake(['api.korapay.com/*' => Http::response([
        'status' => true,
        'data' => ['checkout_url' => 'https://checkout.korapay.com/ws39'],
    ])]);

    $driver->initialize(new PaymentIntent(
        reference: 'API_WH39_KORAPAY_2',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.test',
        callbackUrl: 'https://shop.example.test/return',
    ), $credentials);

    Http::assertSent(fn (Request $request) => $request['notification_url']
        === 'https://shop.example.test/return');
});

test('bitfra settles once the transfer is confirmed, and only then', function () {
    // Its `verify()` reported PENDING unconditionally, so a signed completion
    // webhook was parsed, matched to its transaction, and thrown away as
    // "verification failed" — no Bitfra sale could ever complete on any path.
    Http::swap(new Factory);
    Http::fake(['bitfra.net/*' => Http::response([
        'payment_id' => 'pay_wh39',
        'amount' => 49.99,
        'amount_usd' => 49.99,
        'chain' => 'TRON',
        'status' => 'PAID',
        'tx_id' => 'tx_wh39',
        'updated_at' => now()->toIso8601String(),
    ])]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    wh39Connect($business, 'bitfra', [
        'api_key' => 'test_sandbox_key',
        'webhook_secret' => 'whsec_ws39',
    ]);

    $store = wh39Store($owner);
    // Bitfra names its own payments, so the transaction is matched by the id it
    // issued rather than by a reference we supplied.
    $transaction = wh39Transaction(wh39Order($store), 'bitfra', [
        'amount' => 49.99,
        'currency' => 'USD',
        'gateway_reference' => 'pay_wh39',
    ]);

    $completed = json_encode(['event' => 'payment.completed', 'payment_id' => 'pay_wh39']);
    $timestamp = time();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$completed, 'whsec_ws39');

    $this->call(
        'POST',
        '/webhooks/payments/bitfra/'.$store->store_id,
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BIXMERCHANT_SIGNATURE' => $signature,
        ],
        $completed,
    )->assertOk()->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);

    // `payment.paid` means a transfer was seen, not that it cleared the
    // confirmation threshold, so it is acknowledged and ignored.
    $seen = json_encode(['event' => 'payment.paid', 'payment_id' => 'pay_wh39']);
    $seenAt = time();
    $seenSignature = 't='.$seenAt.',v1='.hash_hmac('sha256', $seenAt.'.'.$seen, 'whsec_ws39');

    $this->call(
        'POST',
        '/webhooks/payments/bitfra/'.$store->store_id,
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BIXMERCHANT_SIGNATURE' => $seenSignature,
        ],
        $seen,
    )->assertOk()->assertJsonPath('status', 'unhandled event');
});
