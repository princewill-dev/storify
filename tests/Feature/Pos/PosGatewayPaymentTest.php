<?php

use App\Actions\Pos\PosSaleResult;
use App\Actions\Pos\ProcessPosSale;
use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\ExchangeRateService;
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
| WS-39 follow-up — the till settles a sale without asking anyone.
|
| Selecting Paystack on the POS used to do two wrong things at once: it asked
| the cashier to retype an amount the till already knew, and it never opened
| Paystack at all. `ProcessPosSale` had no gateway call in it — a `paystack` leg
| was written exactly like cash, as a confirmed transaction with a random local
| reference and a credited store balance. The till printed a receipt for money
| nobody had taken.
|
| That was fixed by asking the provider twice: `initialize` opened the charge,
| `status` reported on it, and `/checkout` re-asked before any row existed.
|
| The till takes neither now. Its list is cash and a transfer into the
| business's own bank account — money Storify never holds and cannot split — so
| there is nothing on Storify's side to confirm, and `/checkout` calls no
| provider at all. **A sale the till reports as paid is recorded as paid.**
|
| What keeps that safe is not a verification. It is that `/checkout` accepts
| only those two methods, so a card leg is unrepresentable rather than merely
| unverified — and a stale or edited client is refused before any row exists.
| That rule is the load-bearing one in this file, and it is asserted at the
| endpoint rather than at the list for exactly that reason.
|
| The gateway branches of `ProcessPosSale` are unreachable through the route
| now. They are not deleted — restoring card at the till should not mean
| rewriting them — so the last section drives the action directly and keeps
| them pinned.
|
| The Korapay case is here on purpose. Paystack is the gateway the bug was
| reported with, but it is also the one the old code named literally, so a
| Paystack-only test would pass against the very defect it is meant to catch.
*/

function pgContext(): array
{
    // The trial is how this business reaches the platform's default plan, which
    // is the one the `beforeEach` below installs. WS-55 gates every
    // `/api/v1/pos/*` route, and {@see Entitlements::for()} resolves a business
    // with neither a subscription nor a live trial to `SOURCE_NONE` — no plan,
    // and therefore no till. The other businesses these tests build exist only
    // to hold a foreign store or bank account; no request ever authenticates as
    // one.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

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
 * Admin-set platform values, on the single settings row.
 *
 * Created when absent, because nothing seeds one and a `null` row would mean
 * every test's switch and commission rate silently read as defaults.
 */
function pgSettings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

/**
 * The platform charging a business's cards itself — the processor switch on,
 * with the platform's own Paystack key behind it.
 */
function pgPlatformProcessor(float $commissionPercent = 2.5): void
{
    pgSettings([
        'system_payment_processor' => 'paystack',
        'platform_commission_percent' => $commissionPercent,
        'platform_commission_fixed_kobo' => 0,
    ]);

    config([
        'services.paystack.secret_key' => 'sk_live_platform1234567890',
        'services.paystack.public_key' => 'pk_live_platform1234567890',
    ]);
}

/**
 * A journal entry's lines, keyed `code:dr` / `code:cr`.
 *
 * An array of plain integers rather than a chain of assertions on the entry,
 * because the thing worth stating about a collection is the whole shape of it:
 * which account each side landed on, and for how much.
 */
function pgLines(JournalEntry $entry): array
{
    return $entry->lines()->get()->mapWithKeys(fn ($line) => [
        $line->account->code.($line->debit_kobo > 0 ? ':dr' : ':cr') => $line->debit_kobo > 0
            ? (int) $line->debit_kobo
            : (int) $line->credit_kobo,
    ])->all();
}

/**
 * Put a business under custody with a bank to be paid into.
 *
 * Custody is what makes a managed till sale a debt Storify owes rather than a
 * charge it merely processed, and the two buckets start where the test says.
 */
function pgCustody(Business $business, ?StoreBank $bank = null): Business
{
    $bank ??= pgBusinessBank($business);

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
        'payout_bank_id' => $bank->id,
    ])->save();

    return $business->fresh();
}

/**
 * Store a USD-based rate for the till to convert through.
 *
 * The service caches the table, so a test that seeds one would otherwise be
 * answered from the previous test's copy.
 */
function pgSeedRate(string $quote, float $rate): void
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
 * `$amountUsd` is what the provider reports was paid. It is a parameter rather
 * than a constant because the interesting cases are the ones where it does not
 * match the charge.
 */
function pgFakeBitfra(float $amountUsd = 1000.00): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($amountUsd) {
        if (str_contains($request->url(), '/payments/')) {
            return Http::response([
                'payment_id' => 'pay_pg_1',
                'amount' => $amountUsd,
                'amount_usd' => $amountUsd,
                'status' => 'PAID',
                'tx_id' => 'tx_pg_1',
            ]);
        }

        return Http::response([
            'payment_id' => 'pay_pg_1',
            'payment_link' => 'https://checkout.bitfra.net/pg-1',
            'status' => 'PENDING',
        ]);
    });
}

/**
 * The body Paystack returns for one transaction. Echoed back by both reads
 * `doubleVerifyPayment()` makes, because it refuses a payment whose two
 * answers disagree.
 *
 * `$feesKobo` is left out of the body entirely when it is not given, which is
 * the case the pipeline has to survive: a provider that says nothing about what
 * it charged. Omitting the key rather than sending null keeps that distinct from
 * a provider that genuinely reports zero.
 */
function pgPaystackData(string $status, int $amountKobo, string $reference, ?int $feesKobo = null): array
{
    $data = [
        'id' => 778899,
        'status' => $status,
        'amount' => $amountKobo,
        'currency' => 'NGN',
        'reference' => $reference,
        'paid_at' => $status === 'success' ? now()->toIso8601String() : null,
    ];

    if ($feesKobo !== null) {
        $data['fees'] = $feesKobo;
    }

    return $data;
}

/**
 * Fake Paystack's checkout and both verification reads.
 *
 * `Http::swap(new Factory)` first: `Http::fake()` merges into whatever fake is
 * already installed and the earliest stub wins, so without a fresh factory a
 * previous test's fake would answer for this one.
 */
function pgFakePaystack(string $status = 'success', int $amountKobo = 100000, ?int $feesKobo = null): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($status, $amountKobo, $feesKobo) {
        $url = $request->url();

        if (str_contains($url, 'transaction/initialize')) {
            return Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/pg-test',
                    'access_code' => 'pg_access_code',
                    // Echoed back, which is what Paystack actually does with the
                    // reference we send it — and what makes this fake honest
                    // about the case where a provider adopts our reference
                    // rather than issuing one of its own.
                    'reference' => $request['reference'],
                ],
            ]);
        }

        if (str_contains($url, 'transaction/verify')) {
            $reference = Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');

            return Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => pgPaystackData($status, $amountKobo, $reference, $feesKobo),
            ]);
        }

        // The list endpoint the second confirmation scans.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Http::response([
            'status' => true,
            'message' => 'Transactions retrieved',
            'data' => [pgPaystackData($status, $amountKobo, (string) ($query['reference'] ?? ''), $feesKobo)],
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

/**
 * Fake Bachs: the checkout session it opens, and the session read that reports
 * the settled charge.
 *
 * Its amounts are decimal strings at the currency's precision, never minor
 * units, so a settled ₦1,000 sale comes back as `"1000.00"`.
 */
function pgFakeBachs(string $chargeStatus = 'succeeded'): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($chargeStatus) {
        if ($request->method() === 'POST') {
            return Http::response([
                'checkout_id' => 'chk_pg_1',
                'checkout_url' => 'https://checkout.bachs.io/chk_pg_1',
                'status' => 'open',
            ]);
        }

        return Http::response([
            'checkout_id' => 'chk_pg_1',
            'status' => 'completed',
            'charge' => [
                'id' => 'chg_pg_1',
                'status' => $chargeStatus,
                'amount' => '1000.00',
                'currency' => 'NGN',
            ],
        ]);
    });
}

/**
 * A bank account the business holds, optionally the one its payouts go to.
 *
 * A row on the business, not a `store_bank` pivot row: the till reads the
 * business's own accounts — see {@see BankController} — and an account becomes
 * the business's payout destination by being named on the business.
 *
 * `is_verified` defaults to true because most tests are about something else,
 * and an account the business has added and had confirmed is the ordinary case.
 * The unverified case has a test of its own.
 */
function pgBusinessBank(Business $business, string $number = '0123456789', bool $payout = false, bool $verified = true): StoreBank
{
    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => $number,
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
        'is_verified' => $verified,
    ]);

    if ($payout) {
        $business->forceFill(['payout_bank_id' => $bank->id])->save();
    }

    return $bank;
}

/**
 * Drive a sale through the action the checkout controller calls.
 *
 * Used by the last section only. `/checkout` accepts no card method, so the
 * gateway branches of `ProcessPosSale` cannot be reached through the route, and
 * a test that wants them has to skip the validation that now stands in front of
 * them.
 *
 * @param  array<int, array<string, mixed>>  $payments
 * @param  array<string, int>  $providerFees
 */
function pgExecuteSale(
    Store $store,
    User $staff,
    Product $product,
    array $payments,
    array $providerFees = [],
    string $key = 'pg-action-sale',
): PosSaleResult {
    return app(ProcessPosSale::class)->execute(
        $store,
        $staff,
        PosSession::where('store_id', $store->id)->sole(),
        [
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => $payments,
        ],
        $providerFees,
    );
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/*
 * WS-55 gates the whole till by the business's plan, and this suite is about the
 * money the till takes rather than about the gate. The test database has no plan
 * rows at all (`SeedsPlatformFixturesOnce` seeds the permission catalogue and
 * nothing else), so the trial `pgContext()` opens would resolve down to the
 * *registry* defaults — where `pos` is `false` — and every request below would
 * answer `403 plan_feature_unavailable` before it reached the thing under test.
 * Installing a default plan with the till on it is what lets these tests reach
 * the routes they are testing; the helper's own docblock explains why the trial
 * is the path it takes.
 */
beforeEach(function () {
    defaultPlanIncludingEveryModule();
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
    pgBusinessBank($business);
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

test('a card leg cannot be posted to the till at all', function () {
    // The load-bearing rule this file exists to protect, and the one that
    // replaced the second provider verification.
    //
    // Both of the old build's failures are here. A `paystack` leg with no
    // reference used to come back "checkout successful" with the money recorded;
    // one with a reference used to be handed to the provider to confirm. Neither
    // is refused by `PosPaymentService` any more — it no longer looks at a card
    // leg — so both are refused one step earlier, by the request, as a method
    // this route does not accept.
    //
    // The reference in the second case is a real one that a provider did settle,
    // which is the point: nothing about the leg makes it writable.
    [, $business, , $store, $product, $stock] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgFakePaystack('success');

    foreach ([
        ['method' => 'paystack', 'amount' => 1000],
        ['method' => 'paystack', 'amount' => 1000, 'reference' => 'POS_SETTLED'],
    ] as $leg) {
        $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
            'idempotency_key' => 'pg-card-leg',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [$leg],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.method']);
    }

    expect(Order::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(10)
        ->and($stock->fresh()->quantity)->toBe(10)
        ->and($store->fresh()->balance)->toBe(0);

    // And no provider was asked about either of them.
    Http::assertNothingSent();
});

test('a bank transfer leg writes the sale against the business\'s own account', function () {
    // The till's real path, and the one that replaced the card: money the
    // customer transferred into an account the business owns. Naira for naira
    // with cash — it credits the store's own running balance, and it signs
    // nothing over to Storify because Storify never held it.
    [, $business, , $store, $product, $stock] = pgContext();
    $bank = pgBusinessBank($business, payout: true);
    pgFakePaystack();

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-transfer-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'bank_transfer', 'amount' => 1000, 'bank_account_id' => $bank->id]],
    ])->assertCreated();

    $transaction = Order::where('store_id', $store->id)->sole()->transactions->sole();

    expect($transaction->reference)->toStartWith('TXN-POS-')
        ->and($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((float) $transaction->amount)->toBe(1000.0)
        ->and($transaction->currency)->toBe('NGN')
        ->and($transaction->paymentMethod->code)->toBe('bank_transfer')
        // Which account of the business's the money went into — the payout
        // destination, in this sale's case.
        ->and($transaction->store_bank_id)->toBe($bank->id)
        ->and($product->fresh()->quantity)->toBe(9)
        ->and($stock->fresh()->quantity)->toBe(9)
        ->and($store->fresh()->balance)->toBe(100000);

    // The whole point of this change: the till's word is the record, and no
    // provider was asked anything.
    Http::assertNothingSent();
});

test('a transfer leg with no bank account is refused', function () {
    [, $business, , $store, $product] = pgContext();
    pgBusinessBank($business);

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-transfer-no-bank',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'bank_transfer', 'amount' => 1000]],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'A bank account is required for bank transfer payments.');

    expect(Order::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

test('a transfer leg naming another business\'s bank account is refused', function () {
    // The request catches this one — `bank_account_id` is checked against the
    // store's own business before anything else runs. The action's own copy of
    // the check is pinned separately, in the last section, because a rule that
    // exists in two places and is exercised in one is a rule that quietly stops
    // existing in the other.
    [, $business, , $store, $product] = pgContext();
    pgBusinessBank($business);

    [, $otherBusiness] = createBusinessOwner();
    $foreign = pgBusinessBank($otherBusiness, '9876543210');

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/checkout", [
        'idempotency_key' => 'pg-transfer-foreign-bank',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'bank_transfer', 'amount' => 1000, 'bank_account_id' => $foreign->id]],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['payments.0.bank_account_id']);

    expect(Order::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

test('replaying a till sale does not credit the store twice', function () {
    [, $business, , $store, $product] = pgContext();
    $bank = pgBusinessBank($business);

    $payload = [
        'idempotency_key' => 'pg-replayed-leg',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['method' => 'bank_transfer', 'amount' => 1000, 'bank_account_id' => $bank->id]],
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
});

test('a bachs leg settles under the checkout id, never our own reference', function () {
    // Bachs joins Bitfra in the group that has no lookup by the reference we
    // issue: it answers to the checkout id it returned, so the till has to
    // round-trip that or the leg can never be confirmed. Its amounts are decimal
    // strings rather than minor units, which is the other half of this test — a
    // ₦1,000 sale must be asked for as "1000.00".
    //
    // The round trip is asserted through `status`, which is where it happens
    // now. `/checkout` no longer asks anyone anything.
    [, $business, , $store, $product] = pgContext();
    pgConnect($business, 'bachs', ['api_key' => 'sk_sandbox_pgtest12345', 'webhook_secret' => 'whsec_pg']);
    pgFakeBachs();

    $started = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'bachs',
        'amount' => 1000,
    ])->assertCreated();

    expect($started->json('data.authorization_url'))->toBe('https://checkout.bachs.io/chk_pg_1')
        ->and($started->json('data.provider_reference'))->toBe('chk_pg_1')
        // The store's own amount stays naira, because the till sums it against
        // the sale total.
        ->and((float) $started->json('data.amount'))->toBe(1000.0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['pricing']['amount'] === '1000.00'
        && $request['pricing']['currency'] === 'NGN');

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/status", [
        'method' => 'bachs',
        'reference' => $started->json('data.reference'),
        'provider_reference' => $started->json('data.provider_reference'),
        'amount' => 1000,
    ])->assertOk()->assertJsonPath('data.paid', true);

    // Asked under the checkout id, never the POS reference — the checkout id is
    // the only name Bachs can answer about.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/checkout-sessions/chk_pg_1'));
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

test('the till is offered cash and a transfer into the business\'s own account', function () {
    // Two entries, and a connected provider is not one of them. The till is not
    // the shopfront: a counter sale is money the business takes, in an account
    // the business owns, and Storify neither holds it nor takes a cut of it — so
    // there is nothing here for a provider to collect.
    //
    // The client branches on `mode` to decide whether a leg opens a provider,
    // which is why it is on the wire. It is `offline` for both.
    [, $business, , $store] = pgContext();
    pgConnect($business, 'paystack', pgPaystackKeys());
    pgBusinessBank($business, payout: true);

    $payload = $this->getJson("/api/v1/pos/stores/{$store->store_id}/payment-methods")->assertOk();

    $methods = collect($payload->json('data.payment_methods'))->keyBy('code');

    expect($methods->keys()->sort()->values()->all())->toBe(['bank_transfer', 'cash'])
        ->and($methods['cash']['name'])->toBe('Cash')
        ->and($methods['cash']['mode'])->toBe('offline')
        ->and($methods['cash']['requires_bank_account'])->toBeFalse()
        ->and($payload->json('data.store_currency'))->toBe('NGN')
        ->and($methods['cash']['currency'])->toBe('NGN')
        ->and($methods['cash']['conversion'])->toBeNull()
        // Taken in the store's own currency and never converted: the account
        // the customer transfers into is in the store's country.
        ->and($methods['bank_transfer']['currency'])->toBe('NGN')
        ->and($methods['bank_transfer']['conversion'])->toBeNull()
        ->and($methods['bank_transfer']['name'])->toBe('Bank transfer')
        ->and($methods['bank_transfer']['mode'])->toBe('offline')
        ->and($methods['bank_transfer']['requires_bank_account'])->toBeTrue()
        // Cash is on the list, so the till is not down and the storefront's
        // reason has nothing to say.
        ->and($payload->json('data.unavailable_reason'))->toBeNull()
        ->and($payload->json('data.bank_transfer_unavailable_reason'))->toBeNull();

    // The storefront still offers it. This is a difference in rail, not in
    // configuration, and the two reading one resolver would hide which it is.
    expect(app(PaymentGatewayResolver::class)->forStore($store))->toHaveKey('paystack');
});

test('the till is not offered a bank transfer until the business has an account', function () {
    // The gate is the account, not the storefront's switch: a business that
    // turned bank transfer off online still takes transfers over the counter.
    // Having nowhere to send the money is the one thing that stops it, and the
    // reason says so rather than leaving the option silently missing.
    [, $business, , $store] = pgContext();

    $payload = $this->getJson("/api/v1/pos/stores/{$store->store_id}/payment-methods")->assertOk();

    expect(collect($payload->json('data.payment_methods'))->pluck('code')->all())->toBe(['cash'])
        ->and($payload->json('data.bank_transfer_unavailable_reason'))
        ->toBe('Add a bank account to accept bank transfers at the till.');
});

test('the till is told which account the business\'s payouts go to', function () {
    // Every account the business holds is offered, because the money is the
    // business's and the customer is paying the business — not a store. The
    // payout destination is flagged because that is where the money is meant to
    // end up, and it is what the app preselects.
    [, $business, , $store] = pgContext();
    $payout = pgBusinessBank($business, '0123456789', payout: true);
    $other = pgBusinessBank($business, '9876543210');

    $banks = collect(
        $this->getJson("/api/v1/pos/stores/{$store->store_id}/banks")->assertOk()->json('data.banks')
    )->keyBy('id');

    expect($banks->keys()->sort()->values()->all())->toBe(collect([$payout->id, $other->id])->sort()->values()->all())
        ->and($banks[$payout->id]['is_payout'])->toBeTrue()
        ->and($banks[$other->id]['is_payout'])->toBeFalse()
        ->and($banks[$payout->id]['masked_account_number'])->toBe('012*****89')
        ->and($banks[$payout->id]['bank_code'])->toBe('058');
});

test('a provider that names its own payments is asked about that name, not ours', function () {
    // Bitfra generates a `payment_id` and has no lookup by the reference we
    // supply, so a till that only ever quotes its own `POS_…` reference can
    // never be told the crypto arrived: the sale sits pending until the charge
    // times out. The id travels out with the charge and back with every
    // question, which is what this pins.
    //
    // Bitfra also prices in USD and this store charges naira, so the same test
    // carries the conversion: ₦1,000 at ₦1,000 to the dollar is $1.00, and the
    // till echoes the 100 cents it was told to expect rather than recomputing
    // from a rate that may have moved since.
    [, $business, , $store, $product] = pgContext();

    pgSeedRate('NGN', 1000.0);
    pgConnect($business, 'bitfra', ['api_key' => 'test_sandbox_key', 'webhook_secret' => 'whsec_pg']);
    pgFakeBitfra(1.00);

    $started = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'bitfra',
        'amount' => 1000,
    ])->assertCreated();

    expect($started->json('data.reference'))->toStartWith('POS_')
        ->and($started->json('data.provider_reference'))->toBe('pay_pg_1')
        // The store's own amount stays naira — the till sums these against the
        // sale total, so a dollar figure here would refuse every sale.
        ->and((float) $started->json('data.amount'))->toBe(1000.0)
        ->and($started->json('data.charge_amount'))->toBe(100)
        ->and($started->json('data.charge_currency'))->toBe('USD');

    // Asked under Bitfra's name, and for the amount the card was to be charged.
    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/status", [
        'method' => 'bitfra',
        'reference' => $started->json('data.reference'),
        'provider_reference' => $started->json('data.provider_reference'),
        'amount' => 1000,
        'charge_amount' => 100,
    ])->assertOk()->assertJsonPath('data.paid', true);

    // Asking without the echoed charge cannot be answered: there is nothing to
    // compare the provider's dollars against, so it is refused with a message
    // that names the real problem instead of reporting a units mismatch.
    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/status", [
        'method' => 'bitfra',
        'reference' => $started->json('data.reference'),
        'provider_reference' => $started->json('data.provider_reference'),
        'amount' => 1000,
    ])->assertOk()
        ->assertJsonPath('data.paid', false)
        ->assertJsonPath('data.message', 'Update the POS app to take this payment method — bitfra charges in USD.');

    // A provider that reports the *store* amount in cents is reporting far more
    // than was charged — the failure the echoed amount exists to catch.
    Http::swap(new Factory);
    pgFakeBitfra(1000.00);

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/status", [
        'method' => 'bitfra',
        'reference' => $started->json('data.reference'),
        'provider_reference' => $started->json('data.provider_reference'),
        'amount' => 1000,
        'charge_amount' => 100,
    ])->assertOk()
        ->assertJsonPath('data.paid', false)
        ->assertJsonPath('data.message', 'The provider recorded a different amount for this payment.');

    Http::swap(new Factory);
    pgFakeBitfra(1.00);

    $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/status", [
        'method' => 'bitfra',
        'reference' => $started->json('data.reference'),
        'provider_reference' => $started->json('data.provider_reference'),
        'amount' => 1000,
        'charge_amount' => 100,
    ])->assertOk()->assertJsonPath('data.paid', true);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/payments/pay_pg_1'));

    // Paystack keeps answering to our own reference: it adopts the one it is
    // given, so nothing is round-tripped and no id is invented for it.
    [, $paystackBusiness, , $paystackStore, $paystackProduct] = pgContext();
    pgConnect($paystackBusiness, 'paystack', pgPaystackKeys());
    pgFakePaystack();

    $paystackStart = $this->postJson("/api/v1/pos/stores/{$paystackStore->store_id}/payments/initialize", [
        'items' => [['product_id' => $paystackProduct->id, 'quantity' => 1]],
        'method' => 'paystack',
        'amount' => 1000,
    ])->assertCreated();

    expect($paystackStart->json('data.provider_reference'))->toBeNull();
});

test('a charge that converts to nothing is refused before the provider is asked', function () {
    // ₦1 is a tenth of a cent at ₦1,000 to the dollar. Rounding it up to the
    // smallest unit would overcharge tenfold, and sending zero would charge
    // nothing while crediting the store for the full sale.
    [, $business, , $store, $product] = pgContext();
    pgSeedRate('NGN', 1000.0);
    pgConnect($business, 'bitfra', ['api_key' => 'test_sandbox_key', 'webhook_secret' => 'whsec_pg']);
    pgFakeBitfra();

    $response = $this->postJson("/api/v1/pos/stores/{$store->store_id}/payments/initialize", [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'method' => 'bitfra',
        'amount' => 1,
    ])->assertStatus(422);

    expect(str_contains((string) $response->json('message'), 'too small'))->toBeTrue();

    // Nothing reached Bitfra: the refusal happens before the driver is called.
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The gateway branches the till can no longer reach
|--------------------------------------------------------------------------
|
| `/checkout` accepts cash and `bank_transfer` and nothing else, so nothing in
| this section is reachable through it — which is worth stating, because the
| code is still there and still runs for anyone who restores card at the till.
|
| Deleting it would be the tidy thing and the wrong one: it is the half of the
| old design that still holds up. A managed leg's commission, the provider's own
| fee on the row, and the wallet's refusal to credit a sale nobody priced are
| all money rules that were fought for, and they are not about verification —
| they are about what the platform is owed and owes once a card sale exists.
|
| So it is driven directly, through the action the controller calls, rather than
| through a route that would refuse it. The tests skip validation on purpose;
| that is the only thing being bypassed, and the section above proves the
| validation is there.
*/

test('the sale action refuses a bank account belonging to another business', function () {
    // The bug this pins: these checks matched the literal `transfer`, the POS
    // client's older name, while the catalogue's code is `bank_transfer` — so a
    // leg sent under the code the till now uses skipped both of them, and
    // another business's account was written onto the transaction.
    [, $business, $staff, $store, $product] = pgContext();
    pgBusinessBank($business);

    [, $otherBusiness] = createBusinessOwner();
    $foreign = pgBusinessBank($otherBusiness, '9876543210');

    expect(fn () => pgExecuteSale($store, $staff, $product, [
        ['method' => 'bank_transfer', 'amount' => 1000, 'bank_account_id' => $foreign->id],
    ], key: 'pg-action-foreign-bank'))
        ->toThrow(DomainException::class, 'A selected bank account does not belong to this business.');

    expect(Order::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe(10);
});

test('the sale action refuses a bank transfer with no bank account', function () {
    [, $business, $staff, $store, $product] = pgContext();
    pgBusinessBank($business);

    expect(fn () => pgExecuteSale($store, $staff, $product, [
        ['method' => 'bank_transfer', 'amount' => 1000],
    ], key: 'pg-action-no-bank'))
        ->toThrow(DomainException::class, 'A bank account is required for bank transfer payments.');

    expect(Order::count())->toBe(0);
});

test('a gateway leg clears through the gateway account, not the drawer', function () {
    // The defect this catches: `paymentAssetKey()` named Paystack literally, so
    // every other provider's takings were posted to Cash on Hand — money the
    // till never held, sitting in the drawer reconciliation as a surplus.
    [, $business, $staff, $store, $product] = pgContext();
    pgConnect($business, 'korapay', ['public_key' => 'pk_test_korapay123', 'secret_key' => 'sk_test_korapay123']);
    pgFakeKorapay();

    pgExecuteSale($store, $staff, $product, [
        ['method' => 'korapay', 'amount' => 1000, 'reference' => 'POS_KORAPAY_ACTION'],
    ], key: 'pg-action-clearing');

    $order = Order::where('store_id', $store->id)->sole();

    $account = fn (string $code): int => LedgerAccount::where('business_id', $store->business_id)
        ->where('code', $code)
        ->sole()
        ->id;

    $lines = JournalEntry::where('idempotency_key', 'sale:order:'.$order->id)->sole()->lines;

    expect((int) $lines->where('ledger_account_id', $account('1030'))->sum('debit_kobo'))->toBe(100000)
        ->and((int) $lines->where('ledger_account_id', $account('1010'))->sum('debit_kobo'))->toBe(0);
});

test('a gateway leg records what the provider was asked to charge, in its own units', function () {
    // A naira sale charged in dollars. The store is written down as the naira it
    // is owed, and what the provider actually saw is kept beside it for the same
    // reason the storefront keeps it: it is not recoverable afterwards.
    [, $business, $staff, $store, $product] = pgContext();
    pgSeedRate('NGN', 1000.0);
    pgConnect($business, 'bitfra', ['api_key' => 'test_sandbox_key', 'webhook_secret' => 'whsec_pg']);
    pgFakeBitfra(1.00);

    $reference = 'POS_BITFRA_ACTION';

    pgExecuteSale($store, $staff, $product, [[
        'method' => 'bitfra',
        'amount' => 1000,
        'reference' => $reference,
        'provider_reference' => 'pay_pg_1',
        'charge_amount' => 100,
        'charge_currency' => 'USD',
    ]], key: 'pg-action-charge-record');

    $transaction = Transaction::where('reference', $reference)->sole();

    expect((float) $transaction->amount)->toBe(1000.0)
        // The store's own currency, which is what `amount` has always been in.
        ->and($transaction->currency)->toBe('NGN')
        ->and($transaction->gateway_reference)->toBe('pay_pg_1')
        ->and($transaction->metadata['charge']['amount_minor'])->toBe(100)
        ->and($transaction->metadata['charge']['currency'])->toBe('USD')
        ->and($store->fresh()->balance)->toBe(100000);
});

/*
|--------------------------------------------------------------------------
| A managed leg, and the fee that is Storify's own
|--------------------------------------------------------------------------
|
| None of this is reachable from `/checkout` any more — see the section above
| for why it is kept anyway — but the money rules it states are the reason the
| section above has to hold.
|
| A leg the platform charged is a leg Storify is merchant of record for: the
| provider takes its fee out of Storify's balance, so the business's share is
| `gross - commission` and nothing else. The provider's fee is still fetched —
| `providerFees`, handed over by the checkout — but it is fetched for *our*
| books, as a cost we have incurred, and it never reaches the business's.
|
| That is what these two tests now say: the same sale, priced and unpriced, is
| credited the same amount either way, and the difference between the two is
| only whether our own cost is on the books yet.
*/

test('a managed leg records the provider charge and credits the custody wallet', function () {
    [, $business, $staff, $store, $product] = pgContext();
    pgPlatformProcessor(2.5);
    $business = pgCustody($business);
    // ₦1,000 charged, ₦15 kept by Paystack, ₦25 commission at 2.5%.
    pgFakePaystack('success', 100000, 1500);

    $reference = 'POS_MANAGED_PRICED';

    pgExecuteSale($store, $staff, $product, [
        ['method' => 'paystack', 'amount' => 1000, 'reference' => $reference],
    ], [$reference => 1500], key: 'pg-custody-priced-leg');

    $transaction = Transaction::where('reference', $reference)->sole();

    expect($transaction->fee_kobo)->toBe(1500)
        ->and($transaction->providerFeeBearer()->value)->toBe('platform')
        ->and($transaction->platform_fee_kobo)->toBe(2500)
        // ₦1,000 less our ₦25. Paystack's ₦15 is Storify's cost, so the business
        // is not ₦15 short of what it sold for.
        ->and($transaction->net_kobo)->toBe(97500)
        // The store's own balance is untouched: this money is not in the
        // business's provider account, it is in Storify's.
        ->and((int) $store->fresh()->balance)->toBe(0)
        // And it is owed — in `pending`, because the provider has not settled
        // it to us yet, which is the whole reason the bucket exists.
        ->and((int) $business->fresh()->pending_balance)->toBe(97500)
        ->and((int) $business->fresh()->available_balance)->toBe(0)
        ->and($transaction->settle_at)->not->toBeNull();

    // The ledger moved with the wallet, and it says what we are holding. No
    // `2210` line: this sale raised no payable to the provider, because the
    // provider is taking the fee out of a balance that is already ours.
    $entry = JournalEntry::where('idempotency_key', 'managed:collect:txn:'.$transaction->id)->sole();

    expect(pgLines($entry))->toBe([
        '1030:dr' => 100000,
        '2300:cr' => 97500,
        '4020:cr' => 2500,
    ]);

    // Our own cost, on our own books, the moment the fee is known. It is an
    // expense rather than a settlement of a liability, and it credits the same
    // clearing account — which was debited the full ₦1,000 while the provider
    // is only ever going to hand over ₦985.
    $feeEntry = JournalEntry::where('idempotency_key', 'managed:provider_fee:txn:'.$transaction->id)->sole();

    expect(pgLines($feeEntry))->toBe([
        '5510:dr' => 1500,
        '1030:cr' => 1500,
    ]);
});

test('a managed leg the provider did not price is credited anyway, and the cost lands later', function () {
    [, $business, $staff, $store, $product] = pgContext();
    pgPlatformProcessor(2.5);
    $business = pgCustody($business);
    pgFakePaystack('success', 100000);

    $reference = 'POS_MANAGED_UNPRICED';

    // No fee is handed over, because the provider said nothing about what it
    // charged. That used to be a reason to credit nothing: the wallet's share
    // was `gross - fee - commission`, and the middle term was missing. It is no
    // longer a reason at all, because on a sale Storify charged that term is
    // not the business's — so the same ₦975 is owed here as on the priced leg
    // above, and it is credited on the same terms.
    pgExecuteSale($store, $staff, $product, [
        ['method' => 'paystack', 'amount' => 1000, 'reference' => $reference],
    ], [], key: 'pg-custody-unpriced-leg');

    $transaction = Transaction::where('reference', $reference)->sole();

    expect($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        // Still null, and still not zero: null is "not known", and a zero would
        // be a claim that Paystack charged nothing.
        ->and($transaction->fee_kobo)->toBeNull()
        // But the business's figure does not wait on it, which is the whole
        // change: identical to the priced leg, credited just as promptly.
        ->and($transaction->net_kobo)->toBe(97500)
        ->and($transaction->settle_at)->not->toBeNull()
        ->and((int) $business->fresh()->pending_balance)->toBe(97500)
        ->and(JournalEntry::where('idempotency_key', 'managed:collect:txn:'.$transaction->id)->exists())->toBeTrue()
        // What waits is *our* side. Until the provider says what it charged
        // there is nothing to recognise, and the promote sweep picks it up off
        // the row once it does.
        ->and(JournalEntry::where('idempotency_key', 'managed:provider_fee:txn:'.$transaction->id)->exists())->toBeFalse();
});
