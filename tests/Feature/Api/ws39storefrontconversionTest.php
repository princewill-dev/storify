<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\ExchangeRateService;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-39 — the storefront's side of currency conversion.
|
| The resolver suite asserts *which* providers are offered and the POS suite
| asserts a converted sale end to end at the till. What is missing is the shop
| itself: that `initialize` prices the charge, writes it down, and that the two
| ways a payment settles — the browser's callback and the provider's webhook —
| credit the store exactly once even when both fire.
|
| The idempotency half is not about currency. `CheckoutPaymentService::verify()`
| credited on every call while the webhook processor had always guarded on the
| transaction's status, so a customer refreshing the success page doubled the
| store's balance. It is asserted here because this change routes converted
| charges through the same method.
*/

/*
 * The fixtures below mirror the ones in the other WS-39 suites rather than
 * reusing them. Pest only loads the files a run actually includes, so calling
 * into another test file's helpers works for a full-suite run and fails the
 * moment somebody filters to this file.
 */

function sc39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'sc39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        // The public storefront API refuses a store that is not published, and
        // this file charges through the storefront checkout.
        'has_website' => true,
        'payment_mode' => 'manual',
        // Part payment is allowed so the amounts below can be chosen for the
        // thing each test is actually about — a ₦1 charge against a ₦23,000
        // order is testing the currency floor, not the part-payment switch. The
        // switch is asserted in StorefrontPartPaymentSettingTest.
        'allow_part_payment' => true,
    ], $attributes));
}

function sc39Order(Store $store, float $total = 23000): Order
{
    return Order::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'SC39-'.fake()->unique()->numerify('######'),
        'subtotal' => $total,
        'total' => $total,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);
}

function sc39Connect(Business $business, string $code, array $config, bool $active = true): void
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

function sc39BitfraKeys(): array
{
    return [
        'api_key' => 'bix-'.str_repeat('a', 28).'-'.str_repeat('b', 28),
        'webhook_secret' => 'whsec_sc39abcdefgh',
    ];
}

function sc39PaystackKeys(): array
{
    return ['public_key' => 'pk_test_sc391234567890', 'secret_key' => 'sk_test_sc391234567890'];
}

/**
 * Store a USD-based rate for the storefront to convert through, and drop the
 * service's copy so this test is not answered from the previous one's.
 */
function sc39Rate(string $quote, float $rate): void
{
    ExchangeRate::updateOrCreate(
        ['base' => 'USD', 'quote' => $quote],
        ['rate' => (string) $rate, 'fetched_at' => now()],
    );

    ExchangeRateService::forget();
}

/**
 * Fake Bitfra's create-payment and single-payment reads.
 *
 * `Http::swap(new Factory)` first: `Http::fake()` merges into whatever fake is
 * already installed and the earliest stub wins, so without a fresh factory a
 * previous test's fake would answer for this one.
 */
function sc39FakeBitfra(float $amountUsd = 17.28): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($amountUsd) {
        if (str_contains($request->url(), '/payments/')) {
            return Http::response([
                'payment_id' => 'pay_sc39_1',
                'amount' => $amountUsd,
                'amount_usd' => $amountUsd,
                'status' => 'PAID',
                'tx_id' => 'tx_sc39_1',
            ]);
        }

        return Http::response([
            'payment_id' => 'pay_sc39_1',
            'payment_link' => 'https://checkout.bitfra.net/sc39-1',
            'status' => 'PENDING',
        ]);
    });
}

/**
 * Fake Paystack's checkout, its verify read and the list read the second
 * confirmation scans.
 */
function sc39FakePaystack(int $amountKobo = 250000, string $status = 'success'): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($amountKobo, $status) {
        $url = $request->url();

        if (str_contains($url, 'transaction/initialize')) {
            return Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/sc39-test',
                    'access_code' => 'sc39_access_code',
                    // Paystack adopts the reference we send, which is what the
                    // callback and the webhook both address the payment by.
                    'reference' => $request['reference'],
                ],
            ]);
        }

        if (str_contains($url, 'transaction/verify')) {
            $reference = Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');

            return Http::response([
                'status' => true,
                'data' => sc39PaystackData($status, $amountKobo, $reference),
            ]);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Http::response([
            'status' => true,
            'data' => [sc39PaystackData($status, $amountKobo, (string) ($query['reference'] ?? ''))],
        ]);
    });
}

/**
 * The body Paystack returns for one transaction, echoed by both reads so the
 * double verification agrees with itself.
 */
function sc39PaystackData(string $status, int $amountKobo, string $reference): array
{
    return [
        'id' => 991122,
        'status' => $status,
        'amount' => $amountKobo,
        'currency' => 'NGN',
        'reference' => $reference,
        'paid_at' => $status === 'success' ? now()->toIso8601String() : null,
    ];
}

/**
 * A signed `charge.success`, over the same bytes `postJson` sends.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function sc39ChargeSuccess(string $reference, int $amountKobo, string $secret): array
{
    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => $reference, 'amount' => $amountKobo, 'currency' => 'NGN'],
    ];

    return [$payload, ['x-paystack-signature' => hash_hmac('sha512', json_encode($payload), $secret)]];
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/* ------------------------------------------------ pricing at initialize */

test('a charge through a USD provider is converted and recorded as the store amount', function () {
    sc39FakeBitfra();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    sc39Connect($business, 'bitfra', sc39BitfraKeys());
    sc39Rate('NGN', 1331.267014);

    $store = sc39Store($owner);
    $order = sc39Order($store, 23000);

    $response = $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bitfra/initialize', [
        'order_number' => $order->order_number,
        'amount' => 23000,
    ], storefrontCustomerHeaders($store))->assertOk();

    // The customer agreed to this figure, so it is echoed back and kept.
    $pricing = $response->json('data.pricing');

    expect($pricing['store_amount_minor'])->toBe(2300000)
        ->and($pricing['store_currency'])->toBe('NGN')
        ->and($pricing['charge_amount_minor'])->toBe(1728)
        ->and($pricing['charge_currency'])->toBe('USD')
        ->and((float) $pricing['rate'])->toEqualWithDelta(1331.267014, 0.0001)
        ->and($pricing['rated_at'])->not->toBeNull();

    $transaction = Transaction::where('order_id', $order->id)->sole();

    // `amount` and `currency` describe the store's own money and never change
    // with the provider. They previously said USD over a naira figure, because
    // the driver's currency was written here.
    expect((float) $transaction->amount)->toBe(23000.0)
        ->and($transaction->currency)->toBe('NGN')
        ->and($transaction->status)->toBe(TransactionStatus::PENDING)
        ->and($transaction->chargeAmountMinor())->toBe(1728)
        // The order is for the naira the merchant asked for, not the dollars
        // the customer will send.
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);

    // And what the provider was actually asked for, in its own units.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/payments')
        && (float) $request['amount'] === 17.28);
});

test('a charge worth less than one minor unit is refused before the provider is called', function () {
    // ₦1 is $0.00075, which rounds to zero cents. Sent anyway, the provider
    // would be asked to charge nothing and the store credited for the full
    // amount — and rounding up to a cent would overcharge by about thirteenfold.
    sc39FakeBitfra();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    sc39Connect($business, 'bitfra', sc39BitfraKeys());
    sc39Rate('NGN', 1331.267014);

    $store = sc39Store($owner);
    $order = sc39Order($store, 23000);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bitfra/initialize', [
        'order_number' => $order->order_number,
        'amount' => 1,
    ], storefrontCustomerHeaders($store))
        ->assertStatus(502)
        ->assertJsonPath(
            'message',
            'This payment is too small to charge in USD — the minimum is 6.66 NGN.'
        );

    // Refused before anything was written and before the gateway was asked.
    expect(Transaction::where('order_id', $order->id)->exists())->toBeFalse()
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);

    Http::assertNothingSent();
});

test('a naira charge keeps its byte-identical shape, with no pricing block', function () {
    // The settlement floor keys on this block's absence, so a same-currency
    // payment must not acquire one.
    sc39FakePaystack(250000);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    sc39Connect($business, 'paystack', sc39PaystackKeys());

    $store = sc39Store($owner);
    $order = sc39Order($store, 2500);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ], storefrontCustomerHeaders($store))->assertOk()
        // An empty PHP array serialises as `[]`, and either way there is no
        // charge to compare against.
        ->assertJsonPath('data.pricing', []);

    $transaction = Transaction::where('order_id', $order->id)->sole();

    expect($transaction->metadata)->not->toHaveKey('pricing')
        ->and($transaction->chargeAmountMinor())->toBeNull()
        ->and($transaction->currency)->toBe('NGN');
});

/* ------------------------------------------- the return leg names the payment */

test('the return url the provider is given carries the reference the success page needs', function () {
    // A provider that decorates the callback URL uses its own name for the
    // payment — Bachs appends `checkout_id`, which nothing here can look up —
    // so without a reference of ours on it the success page has nothing to
    // verify against and, if the webhook has not landed yet, sends the customer
    // back to pay for an order they already paid. This pins that shut.
    sc39FakeBitfra();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    sc39Connect($business, 'bitfra', sc39BitfraKeys());
    sc39Rate('NGN', 1331.267014);

    $store = sc39Store($owner);
    $order = sc39Order($store, 23000);

    $callback = 'https://sc39.storify.buzz/checkout/payment/'.$order->order_number.'/success';

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bitfra/initialize', [
        'order_number' => $order->order_number,
        'amount' => 23000,
        // Exactly as the storefront sends it: already carrying the provider,
        // because a reference on its own does not say who issued it.
        'callback_url' => $callback.'?provider=bitfra',
    ], storefrontCustomerHeaders($store))->assertOk();

    $transaction = Transaction::where('order_id', $order->id)->sole();

    // `&`, not a second `?`: a query string a provider was handed with two
    // question marks puts the reference inside the value of `provider`.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/payments')
        && $request['return_url'] === $callback.'?provider=bitfra&storify_ref='.$transaction->reference);
});

test('a new reference reads as an identifier rather than as an internal code', function () {
    // The reference is not private: the confirmation page prints it, the
    // tracking page lists it, and the provider is handed it to echo back. It
    // used to be `API_<epoch>_<8 hex>` — this platform's vocabulary, and the
    // moment the checkout was opened, on a customer's receipt.
    sc39FakeBitfra();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    sc39Connect($business, 'bitfra', sc39BitfraKeys());
    sc39Rate('NGN', 1331.267014);

    $store = sc39Store($owner);
    $order = sc39Order($store, 23000);

    $reference = $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bitfra/initialize', [
        'order_number' => $order->order_number,
        'amount' => 23000,
    ], storefrontCustomerHeaders($store))->assertOk()->json('data.reference');

    expect($reference)->toMatch('/^ch_[0-9a-f]{16}$/');

    // The string handed back to the client and the one the row carries are the
    // same value, so pinning the shape here pins what every reader sees —
    // including the webhook, which matches on it whole.
    expect(Transaction::where('order_id', $order->id)->sole()->reference)->toBe($reference);
});

/* -------------------------------------------------------- idempotency */

test('verifying the same storefront payment twice credits the store once', function () {
    sc39FakePaystack(250000);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    sc39Connect($business, 'paystack', sc39PaystackKeys());

    $store = sc39Store($owner);
    $order = sc39Order($store, 2500);

    $reference = $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ], storefrontCustomerHeaders($store))->assertOk()->json('data.reference');

    // The customer's browser lands on the callback, and then they refresh.
    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/verify', [
        'reference' => $reference,
    ], storefrontCustomerHeaders($store))->assertOk();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/verify', [
        'reference' => $reference,
    ], storefrontCustomerHeaders($store))->assertOk();

    expect((float) $order->fresh()->amount_paid)->toBe(2500.0)
        ->and((int) $store->fresh()->balance)->toBe(250000)
        ->and(Transaction::where('order_id', $order->id)->count())->toBe(1);
});

test('a webhook settles before the browser returns, and the verify then changes nothing', function () {
    // The ordinary cross-currency sequence: Bitfra's webhook arrives first, the
    // customer's page then asks `verify`. Without the status guard the second
    // call credits the store a second time.
    $secret = sc39PaystackKeys()['secret_key'];
    sc39FakePaystack(250000);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    sc39Connect($business, 'paystack', sc39PaystackKeys());

    $store = sc39Store($owner);
    $order = sc39Order($store, 2500);

    $reference = $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ], storefrontCustomerHeaders($store))->assertOk()->json('data.reference');

    [$payload, $headers] = sc39ChargeSuccess($reference, 250000, $secret);

    $this->postJson('/webhooks/payments/paystack/'.$store->store_id, $payload, $headers)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $creditedOnce = (int) $store->fresh()->balance;

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/verify', [
        'reference' => $reference,
    ], storefrontCustomerHeaders($store))->assertOk();

    expect($creditedOnce)->toBe(250000)
        ->and((int) $store->fresh()->balance)->toBe(250000)
        ->and((float) $order->fresh()->amount_paid)->toBe(2500.0);
});
