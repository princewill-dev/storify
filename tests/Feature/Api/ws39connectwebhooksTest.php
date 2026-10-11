<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\ConnectWebhookEvent;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerMapping;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Connect\BachsEventCatalogue;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| WS-39 — the platform's endpoint for a business's connected account.
|
| `POST /webhooks/connect/bachs`. Connected-account deliveries are signed with
| the *platform's* connect secret, are only sent to an endpoint whose
| `event_source` is `connect`, and name an account rather than any connection of
| ours — which is why they cannot arrive at the per-store URL a merchant pastes
| into a dashboard.
|
| Settlement itself is not reimplemented here: the same idempotent processor the
| browser verify uses does it, so a sale cannot be settled twice by arriving
| down both paths. What is asserted is that, the ledger that follows a managed
| sale, and the one thing that must never happen — a store balance credited for
| money that is sitting in the business's own account at the provider.
*/

function cw39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'cw39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function cw39Order(Store $store, array $attributes = []): Order
{
    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'CW39-'.fake()->unique()->numerify('######'),
        'subtotal' => 2500,
        'total' => 2500,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function cw39Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function cw39Commission(float $percent = 2.5): void
{
    Setting::query()->updateOrCreate(['id' => 1], [
        'company_name' => 'Storify',
        'platform_commission_percent' => $percent,
        'platform_commission_fixed_kobo' => 0,
    ]);
}

/**
 * A connection the platform processes, in the state Bachs leaves it in once a
 * business has registered a bank.
 */
function cw39Managed(Business $business, string $accountId = 'acct_cw39'): void
{
    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => true,
            'gateway_account_id' => $accountId,
            'config' => json_encode(['managed' => [
                'status' => 'active',
                'capabilities' => [
                    'card_collection' => 'active',
                    'ngn_card_collection' => 'active',
                    'bank_transfer' => 'active',
                    'payouts' => 'active',
                ],
                'requirements' => [],
                'payout_destination' => [
                    'id' => 'pd_cw39',
                    'is_usable' => true,
                    'currency' => 'NGN',
                    'bank_name' => 'Guaranty Trust Bank',
                    'account_name' => 'ADA TRADING LTD',
                    'last4' => '6789',
                ],
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/**
 * Bachs, answering every call a managed sale makes.
 *
 * Routed by URL rather than by a single response, because the settlement path
 * makes three different calls and a fixture that answered them all identically
 * would prove nothing: the charge is verified, then the account is refreshed
 * before a payout, then its balance is read. The balance is deliberately zero —
 * "the charge settled" and "the money is movable" are different facts, and the
 * ordinary state right after a sale is the first without the second.
 *
 * @param  array<string, mixed>  $chargeOverrides
 * @param  array<string, mixed>|null  $payoutRefusal  the provider's error body, or null to accept
 */
function cw39FakeBachs(array $chargeOverrides = [], string $balance = '0.00', ?array $payoutRefusal = null): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($chargeOverrides, $balance, $payoutRefusal) {
        $url = $request->url();

        if (str_contains($url, '/balances')) {
            return Http::response([
                'balances' => [['currency' => 'NGN', 'available_balance' => $balance, 'pending_balance' => '2500.00']],
            ]);
        }

        if (str_contains($url, '/payouts') && $request->method() === 'POST') {
            return $payoutRefusal === null
                ? Http::response(['id' => 'po_cw39', 'status' => 'pending', 'amount' => $balance])
                : Http::response($payoutRefusal, 400);
        }

        if (str_contains($url, '/accounts/')) {
            return Http::response([
                'id' => 'acct_cw39',
                'status' => 'active',
                'capabilities' => [
                    'card_collection' => ['status' => 'active'],
                    'payouts' => ['status' => 'active'],
                ],
                'requirements' => [],
            ]);
        }

        if (str_contains($url, '/checkout-sessions')) {
            if ($request->method() === 'GET') {
                return Http::response(array_merge([
                    'checkout_id' => 'chk_cw39',
                    'status' => 'completed',
                    'charge' => array_merge([
                        'id' => 'chg_cw39',
                        'status' => 'succeeded',
                        // ₦2,500 with Bachs' own ₦50 processing fee, deducted at
                        // source from the business's account.
                        'amount' => '2500.00',
                        'currency' => 'NGN',
                        'processing_fee' => '50.00',
                    ], $chargeOverrides),
                ]));
            }

            return Http::response([
                'checkout_id' => 'chk_cw39',
                'checkout_url' => 'https://checkout.bachs.io/chk_cw39',
                'status' => 'open',
            ]);
        }

        return Http::response(['status' => 'ok']);
    });
}

/**
 * A delivery from Bachs about a connected account, signed the way the platform's
 * connect endpoint verifies one.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function cw39Signed(array $payload, string $secret = 'whsec_platform'): array
{
    $body = json_encode($payload);
    $timestamp = time();

    return [
        $body,
        ['HTTP_X_BACHS_SIGNATURE_V2' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)],
    ];
}

function cw39Post(TestCase $test, string $body, array $headers): TestResponse
{
    return $test->call('POST', '/webhooks/connect/bachs', [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $headers), $body);
}

/**
 * A sale charged through the platform, ready to be told about.
 *
 * A keyed array rather than a tuple: four things travel together here and a
 * mis-ordered destructuring would read as a wrong assertion rather than an
 * error.
 *
 * @return array{business: Business, store: Store, order: Order, transaction: Transaction}
 */
function cw39Charge(TestCase $test, string $accountId = 'acct_cw39'): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, $accountId);

    $store = cw39Store($owner);
    $order = cw39Order($store);

    $test->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ])->assertOk()->assertJsonPath('data.authorization_url', 'https://checkout.bachs.io/chk_cw39');

    return [
        'business' => $business,
        'store' => $store,
        'order' => $order,
        'transaction' => Transaction::where('order_id', $order->id)->sole(),
    ];
}

/**
 * What a ledger account was debited or credited, on a given entry.
 *
 * Read through the mapping rather than by account code: the codes are the
 * template's, and the point of the assertion is which *key* the money landed
 * on, which is what the posting service chose.
 */
function cw39Ledger(?int $businessId, string $idempotencyKey, string $accountKey, string $column = 'debit'): int
{
    $entry = JournalEntry::query()
        ->where('business_id', $businessId)
        ->where('idempotency_key', $idempotencyKey)
        ->first();

    if ($entry === null) {
        return 0;
    }

    $accountId = (int) LedgerMapping::query()
        ->where('business_id', $businessId)
        ->where('key', $accountKey)
        ->value('ledger_account_id');

    return (int) JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('ledger_account_id', $accountId)
        ->sum($column.'_kobo');
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/**
 * The business's connection row `config`, decoded.
 *
 * Read for every assertion about operational state — capabilities, requirements,
 * payouts, disputes — because all of it lives in that one JSON column and a test
 * that reached into it by hand would drift from the shape the services write.
 *
 * @return array<string, mixed>
 */
function cw39Config(Business $business): array
{
    $config = DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->where('payment_method_id', PaymentMethod::where('code', 'bachs')->value('id'))
        ->value('config');

    return is_string($config) ? (json_decode($config, true) ?? []) : [];
}

/**
 * The account state as stored, without the keys that do not belong to it.
 *
 * @return array<string, mixed>
 */
function cw39ManagedConfig(Business $business): array
{
    $managed = cw39Config($business)['managed'] ?? [];

    return is_array($managed) ? $managed : [];
}

/**
 * Record a withdrawal on the connection, as the payout service does when it
 * asks the provider for one.
 *
 * A delivery names a payout and expects to find it; without a row to fold into,
 * every payout assertion below would be testing the "not found" branch.
 *
 * @param  array<string, mixed>  $payout
 */
function cw39SeedPayout(Business $business, array $payout): void
{
    $config = cw39Config($business);
    $config['payouts'] = [$payout];

    DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->where('payment_method_id', PaymentMethod::where('code', 'bachs')->value('id'))
        ->update(['config' => json_encode($config)]);
}

function cw39PayoutStatus(Business $business, string $payoutId): ?string
{
    foreach ((array) (cw39Config($business)['payouts'] ?? []) as $entry) {
        if (is_array($entry) && ($entry['id'] ?? null) === $payoutId) {
            return is_string($entry['status'] ?? null) ? $entry['status'] : null;
        }
    }

    return null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function cw39Disputes(Business $business): array
{
    $disputes = cw39Config($business)['disputes'] ?? [];

    return is_array($disputes) ? array_values($disputes) : [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function cw39Refunds(Business $business): array
{
    $refunds = cw39Config($business)['refunds'] ?? [];

    return is_array($refunds) ? array_values($refunds) : [];
}

/**
 * Every action recorded in this business's activity trail.
 *
 * @return array<int, string>
 */
function cw39Actions(Business $business): array
{
    return DB::table('activity_logs')
        ->where('business_id', $business->id)
        ->pluck('action')
        ->all();
}

test('a connected-account delivery settles the sale and posts the business\'s books', function () {
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['business' => $business, 'store' => $store, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    expect($transaction->metadata['payment_mode'])->toBe('platform_managed')
        ->and($transaction->metadata['gateway_account_id'])->toBe('acct_cw39')
        // Recorded at initialize, not looked up at settlement: the rate in force
        // when the customer paid is the one that applies.
        ->and($transaction->metadata['platform_fee_kobo'])->toBe(6250);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        ->and($transaction->paid_at)->not->toBeNull()
        ->and((float) $order->fresh()->amount_paid)->toBe(2500.0);

    // The single most important assertion in this file: no balance of ours was
    // credited. The money is in the business's account at Bachs, and holding a
    // copy of it here is exactly what this model exists to avoid.
    expect((int) $store->fresh()->balance)->toBe(0)
        ->and($transaction->balance_updated_at)->toBeNull();

    $key = 'payment:txn:'.$transaction->id;
    $businessId = (int) $store->business_id;

    // ₦2,500 gross, less Bachs' ₦50 and our ₦62.50 — the net is what the
    // business has at the provider.
    expect(cw39Ledger($businessId, $key, 'gateway_clearing'))->toBe(238750)
        ->and(cw39Ledger($businessId, $key, 'accounts_receivable', 'credit'))->toBe(250000)
        // Both fees land on 5500, which had never been posted to before this:
        // to the business they are the same kind of thing, the cost of taking
        // this payment.
        ->and(cw39Ledger($businessId, $key, 'gateway_fees'))->toBe(11250);

    // And our side of the commission, on our own books.
    $platformKey = 'commission:txn:'.$transaction->id;

    expect(cw39Ledger(null, $platformKey, 'gateway_clearing'))->toBe(6250)
        ->and(cw39Ledger(null, $platformKey, 'service_charge_income', 'credit'))->toBe(6250);

    $entry = JournalEntry::where('business_id', $businessId)->where('idempotency_key', $key)->sole();

    expect($entry->isBalanced())->toBeTrue();
});

test('a replayed delivery settles nothing a second time', function () {
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['business' => $business, 'store' => $store, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');
    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'already processed');

    expect((float) $order->fresh()->amount_paid)->toBe(2500.0)
        ->and(JournalEntry::where('idempotency_key', 'payment:txn:'.$transaction->id)->count())->toBe(1)
        ->and(JournalEntry::where('idempotency_key', 'commission:txn:'.$transaction->id)->count())->toBe(1);
});

test('the browser verify and the delivery cannot both settle one sale', function () {
    // Bachs fires `checkout.completed` while the customer's browser is still on
    // its way back, so the two race. Whichever arrives first must be the only
    // one that counts, and the second must not post a second set of entries.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['business' => $business, 'store' => $store, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/verify', [
        'reference' => $transaction->reference,
    ])->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((int) $store->fresh()->balance)->toBe(0);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk();

    expect((float) $order->fresh()->amount_paid)->toBe(2500.0)
        ->and(JournalEntry::where('idempotency_key', 'payment:txn:'.$transaction->id)->count())->toBe(1);
});

test('a delivery signed with anything but the platform\'s connect secret is a 400', function () {
    // 400 rather than 401 because the same body with the same secret fails
    // again — a 4xx is how the provider is told not to spend its retry budget on
    // it. Nothing about the payment changes.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['business' => $business, 'store' => $store, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => ['checkout_id' => 'chk_cw39', 'payment_status' => 'paid'],
    ], 'not-the-platform-secret');

    cw39Post($this, $body, $headers)->assertStatus(400);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::PENDING)
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);
});

test('a delivery about an account we do not know is acknowledged and logged', function () {
    // Answering anything else makes the provider retry a delivery there is
    // nothing wrong with, and nothing about our state would change if it did.
    // The log line is what makes it visible.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    Log::spy();

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_someone_else',
        'data' => ['checkout_id' => 'chk_other', 'payment_status' => 'paid'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'unknown account');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'bachs.connect.unknown_account'
            && $context['account_id'] === 'acct_someone_else')
        ->atLeast()->once();
});

test('a delivery with no account is not a connected-account event', function () {
    // It verified, so it is ours to receive — it is simply about the platform's
    // own account, which this endpoint settles nothing for.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'data' => ['checkout_id' => 'chk_platform', 'payment_status' => 'paid'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'not a connected account');
});

test('an unconfigured connect secret is a 503, not a rejected delivery', function () {
    // Our misconfiguration, not a bad delivery — so the provider should retry
    // it, and once someone sets the value the same delivery verifies.
    cw39Platform();
    config(['services.bachs.webhook_secret' => null, 'services.bachs.connect_webhook_secret' => null]);
    cw39Commission();
    cw39FakeBachs();

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => ['checkout_id' => 'chk_cw39', 'payment_status' => 'paid'],
    ]);

    cw39Post($this, $body, $headers)->assertStatus(503);
});

test('an event this integration does not act on is accepted and dropped', function () {
    // A recognised delivery about a recognised account that is nothing to do
    // with settlement. Acknowledged so the provider stops, and nothing posted.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['transaction' => $transaction] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'type' => 'account.updated',
        'account' => 'acct_cw39',
        'data' => ['id' => 'acct_cw39', 'status' => 'active'],
    ]);

    cw39Post($this, $body, $headers)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::PENDING)
        ->and(JournalEntry::where('idempotency_key', 'payment:txn:'.$transaction->id)->exists())->toBeFalse();
});

test('a payout that Bachs reports paid posts the withdrawal, and a failed one posts nothing', function () {
    // The second half of the managed model. 1030 holds what the business has at
    // the provider and the payout is what empties it — posted when the provider
    // says the money arrived, not when the payout was requested, because the
    // debit at creation is provisional and a payout can still fail. A failure is
    // therefore nothing to unwind: the entry that would have needed reversing
    // was never written.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');

    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();
    $config = json_decode((string) DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->where('payment_method_id', $method->id)
        ->value('config'), true);

    $config['payouts'] = [
        ['id' => 'po_cw39_failed', 'reference' => 'STF-FAIL', 'amount_kobo' => 100000, 'status' => 'processing'],
        ['id' => 'po_cw39_paid', 'reference' => 'STF-PAID', 'amount_kobo' => 238750, 'status' => 'pending'],
    ];

    DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->where('payment_method_id', $method->id)
        ->update(['config' => json_encode($config)]);

    [$failedBody, $failedHeaders] = cw39Signed([
        'type' => 'payout.failed',
        'account' => 'acct_cw39',
        'data' => ['id' => 'po_cw39_failed', 'status' => 'failed', 'amount' => '1000.00', 'failure_reason' => 'Bank rejected the account.'],
    ]);

    cw39Post($this, $failedBody, $failedHeaders)->assertOk()->assertJsonPath('status', 'payout recorded');

    [$paidBody, $paidHeaders] = cw39Signed([
        'type' => 'payout.paid',
        'account' => 'acct_cw39',
        'data' => ['id' => 'po_cw39_paid', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $paidBody, $paidHeaders)->assertOk()->assertJsonPath('status', 'payout recorded');

    $history = collect(
        json_decode((string) DB::table('business_payment_method')
            ->where('business_id', $business->id)
            ->where('payment_method_id', $method->id)
            ->value('config'), true)['payouts'] ?? []
    )->keyBy('id');

    expect($history['po_cw39_failed']['status'])->toBe('failed')
        ->and($history['po_cw39_failed']['failure_reason'])->toBe('Bank rejected the account.')
        ->and($history['po_cw39_paid']['status'])->toBe('completed');

    // Only the paid one wrote to the books, and only once.
    expect(cw39Ledger((int) $business->id, 'payout:po_cw39_failed', 'bank'))->toBe(0)
        ->and(cw39Ledger((int) $business->id, 'payout:po_cw39_paid', 'bank'))->toBe(238750)
        ->and(cw39Ledger((int) $business->id, 'payout:po_cw39_paid', 'gateway_clearing', 'credit'))->toBe(238750);
});

test('a payout delivery about an account we do not hold is dropped', function () {
    cw39Platform();
    cw39FakeBachs();

    [$body, $headers] = cw39Signed([
        'type' => 'payout.paid',
        'account' => 'acct_stranger',
        'data' => ['id' => 'po_stranger', 'status' => 'completed', 'amount' => '100.00'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'unknown account');
});

test('the endpoint refuses any provider but Bachs', function () {
    $this->call('POST', '/webhooks/connect/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], '{}')->assertStatus(404);
});

test('a settled sale tries to withdraw at once, and neither that nor its failure is fatal', function () {
    // The charge settling and the money becoming movable are different facts,
    // and the ordinary state right after a sale is the first without the second.
    // Asking anyway costs one call and shortens the wait when the provider has
    // already made the funds available.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['transaction' => $transaction] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    // The balance was read, and nothing was paid because nothing has settled.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/balances'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payouts'));

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);
});

test('a refused withdrawal does not unsettle the sale', function () {
    // The delivery has already been answered by the time the withdrawal is
    // attempted, so a payout the provider refuses is the sweep's problem and
    // never a 500 that makes the provider send the same delivery again. The
    // customer's payment is settled either way.
    cw39Platform();
    cw39Commission();
    // Funds are available, and the provider still refuses the payout — the
    // refusal a business with a rejected destination or a debt would get.
    cw39FakeBachs(balance: '2500.00', payoutRefusal: ['code' => 'INSUFFICIENT_BALANCE', 'message' => 'Insufficient balance.']);

    ['store' => $store, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((float) $order->fresh()->amount_paid)->toBe(2500.0)
        ->and((int) $store->fresh()->balance)->toBe(0);

    // The attempt was made, refused, and recorded — not silently dropped.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/payouts'));

    expect(JournalEntry::where('idempotency_key', 'like', 'payout:%')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| At-least-once delivery, and deliveries that arrive out of order
|--------------------------------------------------------------------------
|
| Bachs states both plainly: the same event is delivered more than once until it
| is acknowledged, and events are **not** guaranteed to arrive in the order they
| happened. Neither is a fault, and both were live holes — a repeated
| `payout.paid` posts a second withdrawal, and a late `payout.created` walks a
| completed withdrawal back to "in flight" while its ledger entry stays exactly
| where it is.
|
| Everything below is one of those two shapes.
*/

test('the same event is worked once, however many times it is delivered', function () {
    // Deduplicated on the provider's own event id. This is the difference
    // between paying a business once and paying it twice, so it is claimed
    // *before* any work rather than recorded after it.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['transaction' => $transaction] = cw39Charge($this);

    $payload = [
        'id' => 'evt_cw39_repeat',
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ];

    [$body, $headers] = cw39Signed($payload);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    // The identical delivery again, byte for byte. Answered, and answered
    // without doing the work a second time.
    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'duplicate');

    $key = 'payment:txn:'.$transaction->id;

    expect(JournalEntry::where('idempotency_key', $key)->count())->toBe(1)
        ->and(cw39Ledger((int) $transaction->business_id, $key, 'accounts_receivable', 'credit'))->toBe(250000);

    // And the event is on record as handled, which is what makes the second
    // delivery cheap rather than merely harmless.
    $claim = ConnectWebhookEvent::query()->where('event_id', 'evt_cw39_repeat')->sole();

    expect($claim->status)->toBe('success')
        ->and($claim->account_id)->toBe('acct_cw39')
        ->and($claim->note)->not->toBeNull();
});

test('a delivery the provider names no id for is still processed', function () {
    // A claim cannot be taken on an event with no id, and refusing to process it
    // would be worse than the risk of doing one thing twice — the handlers
    // themselves tolerate a repeat, and dropping a settlement over a missing
    // field would lose the sale.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    ['transaction' => $transaction] = cw39Charge($this);

    Log::spy();

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_cw39',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED)
        ->and(ConnectWebhookEvent::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'bachs.connect.event_without_id')
        ->atLeast()->once();
});

test('a completed payout is not walked back by a delivery that arrives late', function () {
    // `payout.paid` then a redelivered `payout.created`. Without the guard the
    // history would read "in flight" for money the bank already has, and the
    // ledger entry — posted on the way *into* paid — would stay where it is,
    // leaving the two disagreeing with nothing to say which is right.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');
    cw39SeedPayout($business, ['id' => 'po_cw39_late', 'reference' => 'STF-LATE', 'amount_kobo' => 238750, 'status' => 'pending']);

    [$paidBody, $paidHeaders] = cw39Signed([
        'id' => 'evt_cw39_paid',
        'type' => 'payout.paid',
        'account' => 'acct_cw39',
        'data' => ['id' => 'po_cw39_late', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $paidBody, $paidHeaders)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect(cw39PayoutStatus($business, 'po_cw39_late'))->toBe('completed')
        ->and(cw39Ledger((int) $business->id, 'payout:po_cw39_late', 'bank'))->toBe(238750);

    // The late one. A different event id, so it is genuinely a second delivery
    // rather than a retry being deduplicated.
    [$lateBody, $lateHeaders] = cw39Signed([
        'id' => 'evt_cw39_created',
        'type' => 'payout.created',
        'account' => 'acct_cw39',
        'data' => ['id' => 'po_cw39_late', 'status' => 'processing', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $lateBody, $lateHeaders)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect(cw39PayoutStatus($business, 'po_cw39_late'))->toBe('completed')
        // Still one entry, not two: the guard returns before the posting.
        ->and(JournalEntry::where('idempotency_key', 'payout:po_cw39_late')->count())->toBe(1);
});

test('a payout delivery that names no account is matched by its id', function () {
    // The delivery pays a withdrawal by naming `withdrawal_id` — the name
    // `GET /v1/payouts/{withdrawal_id}` uses — and nothing else. Dropping it for
    // want of an account would leave our records saying money left when it did
    // not.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');
    cw39SeedPayout($business, ['id' => 'po_cw39_alias', 'reference' => 'STF-ALIAS', 'amount_kobo' => 238750, 'status' => 'pending']);

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_alias',
        'type' => 'payout.paid',
        'data' => ['withdrawal_id' => 'po_cw39_alias', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect(cw39PayoutStatus($business, 'po_cw39_alias'))->toBe('completed');
});

/*
|--------------------------------------------------------------------------
| The account's own state
|--------------------------------------------------------------------------
|
| `account.updated` and `capability.updated` only say *something* changed. What
| changed is answered by re-reading the account, which is also the only read that
| populates `capabilities` and `requirements` at all — so a storefront offering
| a capability the provider has since restricted would keep offering it until the
| next hourly sweep.
*/

test('an account-state delivery re-reads the account and stores what the provider now says', function () {
    cw39Platform();
    cw39Commission();

    // `is_active` rather than a `status` word: the provider reports the account's
    // standing as a boolean and has no status field at all. Read literally from
    // the document, every managed store would be refused a charge with a screen
    // insisting the account was still under review.
    Http::swap(new Factory);
    Http::fake(fn (Request $request) => str_contains($request->url(), '/accounts/')
        ? Http::response([
            'id' => 'acct_cw39',
            'is_active' => true,
            'capabilities' => [
                'card_collection' => ['status' => 'restricted', 'requested' => true],
                'bank_transfer' => ['status' => 'active'],
            ],
            // `entries` is the per-field view, and the only one that says whether
            // a requirement is ours to fill in. The four buckets beside it are a
            // bare list of field keys and carry no `resolution` at all.
            'requirements' => [
                'currently_due' => ['company.registration_number'],
                'entries' => [[
                    'field' => 'company.registration_number',
                    'status' => 'currently_due',
                    'resolution' => 'api',
                    'restricts_capabilities' => ['card_collection'],
                    'errors' => [['code' => 'missing', 'reason' => 'We could not find a registration number for this company.']],
                ]],
            ],
        ])
        : Http::response(['status' => 'ok']));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_account',
        'type' => 'capability.updated',
        'account' => 'acct_cw39',
        'data' => ['id' => 'acct_cw39', 'capability' => ['name' => 'card_collection', 'status' => 'restricted']],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'account state refreshed');

    $managed = cw39ManagedConfig($business);

    expect($managed['status'])->toBe('active')
        ->and($managed['capabilities']['card_collection'])->toBe('restricted')
        ->and($managed['capabilities']['bank_transfer'])->toBe('active')
        ->and($managed['requirements'])->toHaveCount(1)
        ->and($managed['requirements'][0]['field'])->toBe('company.registration_number')
        ->and($managed['requirements'][0]['resolution'])->toBe('api')
        // The destination is written by a different endpoint entirely, so a
        // refresh that dropped it would make a payable business look unpayable.
        ->and($managed['payout_destination']['id'])->toBe('pd_cw39');

    // And the change is in the trail, so "the capability went away on Tuesday"
    // is answerable afterwards.
    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'payment_gateway.account_state_changed',
    ]);
});

/*
|--------------------------------------------------------------------------
| Disputes and refunds
|--------------------------------------------------------------------------
*/

test('a dispute is recorded and raised, and never answered on the business\'s behalf', function () {
    // Money has already gone — the provider debits a flat fee the moment a
    // dispute is opened — and there is a deadline to answer. What this does is
    // record it faithfully and raise it. Submitting evidence is a human decision
    // about a real customer, so nothing here sends anything anywhere.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_dispute',
        'type' => 'dispute.created',
        'account' => 'acct_cw39',
        'data' => [
            'id' => 'dp_cw39',
            'charge_id' => 'chg_cw39',
            'amount' => '2500.00',
            'currency' => 'NGN',
            'status' => 'needs_response',
            'reason' => 'product_not_received',
            'is_response_editable' => true,
            'response_deadline_at' => '2026-11-01T00:00:00Z',
            'created_at' => '2026-10-11T09:00:00Z',
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute recorded');

    $disputes = cw39Disputes($business);

    expect($disputes)->toHaveCount(1)
        ->and($disputes[0]['id'])->toBe('dp_cw39')
        ->and($disputes[0]['amount_kobo'])->toBe(250000)
        ->and($disputes[0]['reason'])->toBe('product_not_received')
        // The window is the actionable part, and it is kept.
        ->and($disputes[0]['response_deadline_at'])->toBe('2026-11-01T00:00:00Z');

    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'payment_gateway.dispute_needs_response',
    ]);

    // Nothing was submitted. There is no code path from here that answers a
    // dispute, which is the assertion: no provider call at all.
    Http::assertNothingSent();
});

test('a dispute update replaces the entry rather than adding a second copy', function () {
    // Keyed on the provider's own object id, so a redelivery or an out-of-order
    // update is the latest state of one dispute rather than a growing list.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');

    foreach ([['needs_response', 'evt_cw39_dp1'], ['under_review', 'evt_cw39_dp2']] as [$status, $eventId]) {
        [$body, $headers] = cw39Signed([
            'id' => $eventId,
            'type' => 'dispute.updated',
            'account' => 'acct_cw39',
            'data' => ['id' => 'dp_cw39', 'amount' => '2500.00', 'currency' => 'NGN', 'status' => $status],
        ]);

        cw39Post($this, $body, $headers)->assertOk();
    }

    $disputes = cw39Disputes($business);

    expect($disputes)->toHaveCount(1)
        ->and($disputes[0]['status'])->toBe('under_review');
});

test('a refund that did not reach the customer is recorded and raised; one that did is recorded quietly', function () {
    // Our refund call returns as soon as the provider accepts it, which is not
    // the same as the customer being paid. Without this a failed refund looks
    // exactly like a successful one.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');

    [$paidBody, $paidHeaders] = cw39Signed([
        'id' => 'evt_cw39_rf_paid',
        'type' => 'refund.paid',
        'account' => 'acct_cw39',
        'data' => ['id' => 'rf_cw39_ok', 'charge_id' => 'chg_cw39', 'status' => 'paid', 'refunded_amount' => '500.00', 'currency' => 'NGN'],
    ]);

    cw39Post($this, $paidBody, $paidHeaders)->assertOk()->assertJsonPath('status', 'refund recorded');

    [$failedBody, $failedHeaders] = cw39Signed([
        'id' => 'evt_cw39_rf_failed',
        'type' => 'refund.failed',
        'account' => 'acct_cw39',
        'data' => ['id' => 'rf_cw39_bad', 'charge_id' => 'chg_cw39', 'status' => 'failed', 'requested_amount' => '500.00', 'currency' => 'NGN', 'failure_reason' => 'card_declined'],
    ]);

    cw39Post($this, $failedBody, $failedHeaders)->assertOk()->assertJsonPath('status', 'refund recorded');

    $refunds = collect(cw39Refunds($business))->keyBy('id');

    expect($refunds)->toHaveCount(2)
        ->and($refunds['rf_cw39_ok']['status'])->toBe('paid')
        ->and($refunds['rf_cw39_bad']['status'])->toBe('failed')
        ->and($refunds['rf_cw39_bad']['failure_reason'])->toBe('card_declined');

    // Only the failure is raised: a refund that worked is the expected end of a
    // refund somebody already made, and logging every one would bury the one
    // that matters.
    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'payment_gateway.refund_failed',
    ]);

    expect(cw39Actions($business))->not->toContain('payment_gateway.refund_paid');
});

/*
|--------------------------------------------------------------------------
| Every documented event
|--------------------------------------------------------------------------
*/

test('every event Bachs documents is answered without an error', function () {
    // The completeness assertion, walked rather than described: each slug in the
    // catalogue is delivered to a real connected account and must be answered
    // 2xx. A `4xx` would stop the provider retrying a delivery that may matter,
    // and a `5xx` would make it retry one that never will.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');

    $catalogue = BachsEventCatalogue::all();

    expect($catalogue)->toHaveCount(27);

    foreach ($catalogue as $row) {
        [$body, $headers] = cw39Signed([
            // Unique per slug, or the claim would answer every one after the
            // first as a duplicate and the walk would assert nothing.
            'id' => 'evt_cw39_walk_'.$row['type'],
            'type' => $row['type'],
            'account' => 'acct_cw39',
            'data' => [
                'id' => 'obj_cw39_walk',
                'charge_id' => 'chg_cw39',
                'status' => 'paid',
                'amount' => '2500.00',
                'currency' => 'NGN',
            ],
        ]);

        cw39Post($this, $body, $headers)
            ->assertOk()
            ->assertJsonPath('status', fn (string $status): bool => $status !== '');
    }

    // Counted against the table rather than the log, so the assertion is that
    // each one was worked, not merely that it was received.
    expect(ConnectWebhookEvent::query()->count())->toBe(27);
});

test('an event Bachs does not document is logged as a surprise, not absorbed', function () {
    // This is how a newly published slug announces itself. Treating it as one of
    // the quietly-unused events would hide it for a year.
    cw39Platform();
    cw39Commission();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cw39Managed($business, 'acct_cw39');

    Log::spy();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_new',
        'type' => 'something.never.documented',
        'account' => 'acct_cw39',
        'data' => ['id' => 'obj_cw39_new'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'unhandled event');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'bachs.connect.unhandled_event'
            && $context['type'] === 'something.never.documented')
        ->once();
});
