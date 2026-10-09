<?php

use App\Enums\TransactionStatus;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| WS-39 follow-up — the till actually charges the card.
|
| Selecting Paystack on the POS used to do two wrong things at once: it asked
| the cashier to retype an amount the till already knew, and it never opened
| Paystack at all. `ProcessPosSale` had no gateway call in it — a `paystack` leg
| was written exactly like cash, as a confirmed transaction with a random local
| reference and a credited store balance. The till printed a receipt for money
| nobody had taken.
|
| These tests pin the three replacements: `initialize` opens the provider and
| writes nothing, `status` asks the provider what happened, and `/checkout`
| re-asks before any row exists. The last one is the whole point — it is what
| makes "checkout successful" mean the customer actually paid.
|
| The Korapay case is here on purpose. Paystack is the gateway the bug was
| reported with, but it is also the one the old code named literally, so a
| Paystack-only test would pass against the very defect it is meant to catch.
*/

function pgContext(): array
{
    [$owner, $business] = createBusinessOwner();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Gateway POS Store',
        'status' => Store::STATUS_ACTIVE,
        'pos_enabled' => true,
    ]);

    $staff->assignedStores()->attach($store->id);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Gateway POS Product',
        'quantity' => 10,
        'stock_quantity' => 10,
        'amount' => 1000,
        'status' => 'active',
    ]);

    $stock = StockLocation::create([
        'product_id' => $product->id,
        'locationable_type' => Store::class,
        'locationable_id' => $store->id,
        'business_id' => $business->id,
        'quantity' => 10,
    ]);

    PosSession::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'staff_id' => $staff->id,
        'opening_balance' => 0,
    ]);

    Sanctum::actingAs($staff, ['pos']);

    return [$owner, $business, $staff, $store, $product, $stock];
}

/**
 * Connect a provider for a business, through the cipher — the same way the
 * application stores credentials.
 */
function pgConnect($business, string $code, array $config): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => true,
            'config' => json_encode(CredentialCipher::encrypt($config, PaymentGatewayRegistry::secretKeysFor($code))),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function pgAssign($business, Store $store, string $code): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('store_payment_method')->updateOrInsert(
        ['store_id' => $store->id, 'payment_method_id' => $method->id],
        [
            'business_id' => $business->id,
            'is_active' => true,
            'config' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function pgPaystackKeys(): array
{
    return ['public_key' => 'pk_test_pg1234567890', 'secret_key' => 'sk_test_pg1234567890'];
}

/**
 * The body Paystack returns for one transaction. Echoed back by both reads
 * `doubleVerifyPayment()` makes, because it refuses a payment whose two
 * answers disagree.
 */
function pgPaystackData(string $status, int $amountKobo, string $reference): array
{
    return [
        'id' => 778899,
        'status' => $status,
        'amount' => $amountKobo,
        'currency' => 'NGN',
        'reference' => $reference,
        'paid_at' => $status === 'success' ? now()->toIso8601String() : null,
    ];
}

/**
 * Fake Paystack's checkout and both verification reads.
 *
 * `Http::swap(new Factory)` first: `Http::fake()` merges into whatever fake is
 * already installed and the earliest stub wins, so without a fresh factory a
 * previous test's fake would answer for this one.
 */
function pgFakePaystack(string $status = 'success', int $amountKobo = 100000): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($status, $amountKobo) {
        $url = $request->url();

        if (str_contains($url, 'transaction/initialize')) {
            return Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/pg-test',
                    'access_code' => 'pg_access_code',
                    'reference' => 'pg_provider_reference',
                ],
            ]);
        }

        if (str_contains($url, 'transaction/verify')) {
            $reference = Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');

            return Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => pgPaystackData($status, $amountKobo, $reference),
            ]);
        }

        // The list endpoint the second confirmation scans.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Http::response([
            'status' => true,
            'message' => 'Transactions retrieved',
            'data' => [pgPaystackData($status, $amountKobo, (string) ($query['reference'] ?? ''))],
        ]);
    });
}

/**
 * Fake Korapay. Amounts travel in the major unit, so a paid charge carries
 * `1000.0` naira for the same 100000 kobo Paystack would report.
 */
function pgFakeKorapay(): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/charges/initialize')) {
            return Http::response([
                'status' => true,
                'message' => 'Charge initialized',
                'data' => ['checkout_url' => 'https://checkout.korapay.com/pg-test'],
            ]);
        }

        $reference = Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');

        return Http::response([
            'status' => true,
            'message' => 'Charge retrieved',
            'data' => [
                'reference' => $reference,
                'transaction_reference' => 'krp_settled',
                'status' => 'success',
                'amount' => 1000.0,
                'currency' => 'NGN',
            ],
        ]);
    });
}

function pgVerifiedBank($business): void
{
    DB::table('store_banks')->insert([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'GATEWAY TEST LTD',
        'is_primary' => true,
        'is_verified' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/* ------------------------------------------------------ opening the provider */

test('starting a card charge opens the provider and writes nothing', function () {
    [, $business, , $store, $product] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack();

    $response = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'paystack',
        'amount' => 1000,
    ])->assertCreated();

    expect($response->json('data.authorization_url'))->toBe('https://checkout.paystack.com/pg-test')
        ->and((float) $response->json('data.amount'))->toBe(1000.0)
        // The server's own arithmetic, not the till's — the figure the order
        // will be written for.
        ->and((float) $response->json('data.total'))->toBe(1000.0)
        ->and($response->json('data.reference'))->toStartWith('POS_');

    // Kobo, from the shared conversion — 1000 naira is 100000 of them.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'transaction/initialize')
        && $request['amount'] === 100000
        && $request['reference'] === $response->json('data.reference'));

    // The design decision this file exists to protect: a charge in flight
    // leaves no row anywhere for a dashboard or the drawer to count.
    expect(Transaction::count())->toBe(0)
        ->and(Order::count())->toBe(0)
        ->and($store->fresh()->balance)->toBe(0);

    expect(PosSession::where('store_id', $store->id)->sole()->calculateCashSalesTotal())->toBe(0);
});

test('a charge cannot be started for a method this store cannot charge', function () {
    [, $business, , $store, $product] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack();

    $payload = fn (string $method): array => [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => $method,
        'amount' => 1000,
    ];

    // Cash is taken at the drawer. There is no provider to send anyone to.
    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", $payload('cash'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'That payment method is not available for this store.');

    // A catalogue gateway this business has never connected.
    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", $payload('flutterwave'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'That payment method is not available for this store.');

    // A provider the store does accept, but with no checkout to open.
    pgVerifiedBank($business);
    pgAssign($business, $store, 'bank_transfer');

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", $payload('bank_transfer'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'That payment method does not have a checkout to open.');

    expect(Transaction::count())->toBe(0);
});

test('a charge cannot be started with the wrong pin', function () {
    [, $business, $staff, $store, $product] = pgContext();
    $staff->update(['pos_pin' => '123456']);
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack();

    $payload = fn (?string $pin): array => array_filter([
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'paystack',
        'amount' => 1000,
        'pin' => $pin,
    ]);

    // The PIN is asked before the provider is, so a wrong one cannot move
    // money — and cannot leave a reference to clean up either.
    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", $payload('999999'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid PIN.');

    // Nothing was sent to Paystack at all.
    Http::assertNothingSent();

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", $payload('123456'))
        ->assertCreated();
});

test('staff with no pin set are let through, as they are at checkout', function () {
    // Most cashiers have never set a PIN and sell today without one. The rule
    // has to stay conditional or this endpoint locks them all out.
    [, $business, $staff, $store, $product] = pgContext();
    expect($staff->pos_pin)->toBeNull();

    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack();

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'paystack',
        'amount' => 1000,
    ])->assertCreated();
});

/* ----------------------------------------------------- asking the provider */

test('the till can ask the provider whether a charge has been paid', function () {
    [, $business, , $store] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());

    $ask = fn () => $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/status", [
        'method' => 'paystack',
        'reference' => 'POS_STATUS_TEST',
        'amount' => 1000,
    ]);

    pgFakePaystack('success');
    $ask()->assertOk()->assertJsonPath('data.paid', true);

    // Still in flight. A 200 with "not yet", not an error — the till is asking
    // a question whose honest answer is no.
    pgFakePaystack('ongoing');
    $ask()->assertOk()
        ->assertJsonPath('data.paid', false)
        ->assertJsonPath('data.pending', true);

    pgFakePaystack('failed');
    $ask()->assertOk()
        ->assertJsonPath('data.paid', false)
        ->assertJsonPath('data.pending', false);

    // Paid, but not for this amount. Underpayment is a real outcome, and a
    // sale settled on one is a loss rather than a rounding detail.
    pgFakePaystack('success', 50000);
    $ask()->assertOk()
        ->assertJsonPath('data.paid', false)
        ->assertJsonPath('data.message', 'The provider recorded a different amount for this payment.');
});

test('the provider is not asked about a method the store cannot charge', function () {
    [, , , $store] = pgContext();
    pgFakePaystack();

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/status", [
        'method' => 'paystack',
        'reference' => 'POS_NEVER_STARTED',
        'amount' => 1000,
    ])->assertStatus(422);

    Http::assertNothingSent();
});

/* --------------------------------------------------------- settling the sale */

test('a card leg that never went to the provider is refused', function () {
    [, $business, , $store, $product, $stock] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack();

    // The old build's exact behaviour: a `paystack` leg with no reference. It
    // used to come back "checkout successful" with the money recorded.
    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-unpaid-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'paystack', 'amount' => 1000]],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'A card payment on this sale has not been completed.');

    expect(Order::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(10)
        ->and($stock->fresh()->quantity)->toBe(10)
        ->and($store->fresh()->balance)->toBe(0);
});

test('a card leg the provider has not settled is refused', function () {
    [, $business, , $store, $product] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack('ongoing');

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-pending-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'paystack', 'amount' => 1000, 'reference' => 'POS_ABANDONED']],
    ])->assertStatus(422);

    expect(Order::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and($store->fresh()->balance)->toBe(0);
});

test('a paid card leg creates the order carrying the provider reference', function () {
    [, $business, , $store, $product, $stock] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack('success');

    $reference = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'paystack',
        'amount' => 1000,
    ])->assertCreated()->json('data.reference');

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-paid-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'paystack', 'amount' => 1000, 'reference' => $reference]],
    ])->assertCreated();

    $order = Order::where('store_id', $store->id)->sole();
    $transaction = $order->transactions->sole();

    expect($transaction->reference)->toBe($reference)
        ->and($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((float) $transaction->amount)->toBe(1000.0)
        ->and($transaction->paymentMethod->code)->toBe('paystack')
        ->and($product->fresh()->quantity)->toBe(9)
        ->and($stock->fresh()->quantity)->toBe(9)
        ->and($store->fresh()->balance)->toBe(100000);
});

test('replaying a settled card sale does not credit the store twice', function () {
    [, $business, , $store, $product] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack('success');

    $reference = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'paystack',
        'amount' => 1000,
    ])->assertCreated()->json('data.reference');

    $payload = [
        'idempotency_key' => 'pg-replayed-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'paystack', 'amount' => 1000, 'reference' => $reference]],
    ];

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", $payload)->assertCreated();

    // A retry of the same request — a dropped connection, not a second sale.
    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", $payload)
        ->assertOk()
        ->assertJsonPath('replayed', true);

    $order = Order::where('store_id', $store->id)->sole();

    expect($order->transactions)->toHaveCount(1)
        ->and($store->fresh()->balance)->toBe(100000)
        ->and($product->fresh()->quantity)->toBe(9);
});

test('a second provider is charged through its own checkout, not paystack', function () {
    [, $business, , $store, $product] = pgContext();
    pgConnect($business, 'korapay', ['public_key' => 'pk_test_korapay123', 'secret_key' => 'sk_test_korapay123']);
    pgFakeKorapay();

    $response = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'korapay',
        'amount' => 1000,
    ])->assertCreated();

    // Korapay's URL, and no Paystack request anywhere in the run.
    expect($response->json('data.authorization_url'))->toBe('https://checkout.korapay.com/pg-test');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'paystack'));

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-korapay-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'korapay', 'amount' => 1000, 'reference' => $response->json('data.reference')]],
    ])->assertCreated();

    expect(Order::where('store_id', $store->id)->sole()->transactions->sole()->paymentMethod->code)
        ->toBe('korapay');
});

test('a gateway leg clears through the gateway account, not the drawer', function () {
    // The defect this catches: `paymentAssetKey()` named Paystack literally, so
    // every other provider's takings were posted to Cash on Hand — money the
    // till never held, sitting in the drawer reconciliation as a surplus.
    [, $business, , $store, $product] = pgContext();
    pgConnect($business, 'korapay', ['public_key' => 'pk_test_korapay123', 'secret_key' => 'sk_test_korapay123']);
    pgFakeKorapay();

    $reference = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'korapay',
        'amount' => 1000,
    ])->assertCreated()->json('data.reference');

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-clearing-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'korapay', 'amount' => 1000, 'reference' => $reference]],
    ])->assertCreated();

    $order = Order::where('store_id', $store->id)->sole();

    $account = fn (string $code): int => LedgerAccount::where('business_id', $store->business_id)
        ->where('code', $code)
        ->sole()
        ->id;

    $lines = JournalEntry::where('idempotency_key', 'sale:order:'.$order->id)->sole()->lines;

    expect((int) $lines->where('ledger_account_id', $account('1030'))->sum('debit_kobo'))->toBe(100000)
        ->and((int) $lines->where('ledger_account_id', $account('1010'))->sum('debit_kobo'))->toBe(0);
});

test('a cash sale is untouched by any of this', function () {
    [, , , $store, $product] = pgContext();
    pgFakePaystack();

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-cash-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 1000, 'amount_tendered' => 1000]],
    ])->assertCreated();

    $transaction = Order::where('store_id', $store->id)->sole()->transactions->sole();

    expect($transaction->reference)->toStartWith('TXN-POS-')
        ->and($store->fresh()->balance)->toBe(100000);

    // Cash never leaves the building: no provider was called at all.
    Http::assertNothingSent();
});

test('the payment-methods response tells the till how each method is paid', function () {
    // The client branches on `mode` to decide whether a leg opens a provider.
    // The POS endpoint used to drop it, which is why every gateway looked like
    // a local payment.
    [, $business, $staff, $store] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());

    $methods = collect(
        $this->getJson("/api/v1/pos/stores/{$store->store_id}/payment-methods")
            ->assertOk()
            ->json('data.payment_methods')
    )->keyBy('code');

    expect($methods)->toHaveKey('paystack')
        ->and($methods['paystack']['mode'])->toBe('redirect');

    expect(app(PaymentGatewayResolver::class)->forStore($store))->toHaveKey('paystack');
});
