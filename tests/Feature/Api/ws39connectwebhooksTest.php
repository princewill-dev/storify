<?php

use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\ConnectWebhookEvent;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerMapping;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
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
| WS-39 — the platform's endpoint for deliveries about a charge Storify made.
|
| `POST /webhooks/connect/{provider}`. These deliveries are signed with the
| *platform's* signing secret, are only sent to an endpoint whose `event_source`
| is `connect`, and name an account rather than any connection of ours — which is
| why they cannot arrive at the per-store URL a merchant pastes into a dashboard.
|
| The file used to be about a business's connected account, and most of it was
| therefore about resolving *whose* account a delivery was about, refreshing that
| account's capabilities and requirements, and folding payouts and disputes into a
| JSON column on its connection row. All of that is deleted with the rail that
| needed it: under collect-and-pay-out there is one account at the provider and it
| is Storify's, so a delivery naming an account identifies nothing and there is
| nowhere for an account's state to be kept.
|
| What is left is what the endpoint actually does now, and the tests are grouped
| by it: it settles a charge through the same idempotent processor the browser
| verify uses, it routes everything else by the provider's own vocabulary
| ({@see BachsEventCatalogue}), it records a payout against a row in `payouts`,
| and it attaches a chargeback or a refund outcome to a sale **by the charge** —
| the transaction carries whose sale it was and how much they are owed, which is
| everything any of these need and is the reason nothing here resolves a tenant.
*/

function cw39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'cw39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        // The public storefront API refuses a store that is not published, and
        // the charge helper in this file initialises payments through it.
        'has_website' => true,
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

/**
 * Write the singleton settings row the platform reads.
 *
 * `first()`-then-`update` rather than `updateOrCreate(['id' => 1])`, and the
 * difference is not stylistic: `id` is not fillable on `Setting`, so the lookup
 * constraint is applied to the query but dropped from the insert. A row is
 * therefore never created *with* id 1, the update branch never matches, and
 * every call inserts another one — while `SystemProcessor`, which reads the
 * first, keeps seeing whichever row happened to land lowest.
 */
function cw39Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

/**
 * The platform's Bachs account: its key, its signing secret, and the switch that
 * makes Bachs the processor every business is charged through.
 *
 * The switch is set rather than left off deliberately. It is what the storefront
 * reads to decide which provider to offer, and a suite that left it unset would
 * be exercising the "no processor selected" branch by accident — the same tests,
 * passing for a reason nobody chose.
 */
function cw39Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);

    cw39Settings([
        'system_payment_processor' => 'bachs',
        'platform_commission_percent' => 2.5,
        'platform_commission_fixed_kobo' => 0,
    ]);
}

/**
 * Bachs, answering the two calls a platform-processed charge makes.
 *
 * Routed by URL rather than by a single response, because they are different
 * questions: the initialize call opens a checkout, and the verify call reads the
 * charge back. The charge carries Bachs' own ₦50 processing fee inside a `fees`
 * object — the shape a checkout-session charge actually uses — which is what the
 * business's share is computed against. A fixture without it would make the net
 * wrong in a way every ledger assertion below would inherit; a fixture carrying it
 * under the webhook's flat `processing_fee` name would be a shape this endpoint
 * never sends, and the assertions would pass over a fee that was read as absent.
 *
 * There is no `/balances` and no `/payouts` route any more, and their absence is
 * load-bearing: a charge that still tried to withdraw at settlement would reach
 * a fake that answers nothing recognisable, and the `assertNotSent` calls in this
 * file would fail rather than pass quietly.
 *
 * @param  array<string, mixed>  $chargeOverrides
 */
function cw39FakeBachs(array $chargeOverrides = []): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($chargeOverrides) {
        if (str_contains($request->url(), '/checkout-sessions')) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'checkout_id' => 'chk_cw39',
                    'status' => 'completed',
                    'charge' => array_merge([
                        'id' => 'chg_cw39',
                        'status' => 'succeeded',
                        'amount' => '2500.00',
                        'currency' => 'NGN',
                        'fees' => [
                            'amount' => '50.00',
                            'currency' => 'NGN',
                            'breakdown' => [['type' => 'processing', 'amount' => '50.00']],
                        ],
                    ], $chargeOverrides),
                ]);
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
 * A delivery from Bachs, signed the way the platform's endpoint verifies one.
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
 * No account is created anywhere, and none is needed — the business is under
 * custody because the column defaults to it, and the charge the platform makes
 * names nothing of the business's. That is the change stated as a fixture.
 *
 * @return array{business: Business, store: Store, order: Order, transaction: Transaction}
 */
function cw39Charge(TestCase $test): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = cw39Store($owner);
    $order = cw39Order($store);

    $test->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ], storefrontCustomerHeaders($store))->assertOk()->assertJsonPath('data.authorization_url', 'https://checkout.bachs.io/chk_cw39');

    return [
        'business' => $business,
        'store' => $store,
        'order' => $order,
        'transaction' => Transaction::where('order_id', $order->id)->sole(),
    ];
}

/**
 * A sale already on the books, with no provider traffic behind it.
 *
 * For the deliveries that are not settlements — a chargeback, a refund outcome —
 * the only thing that matters is that a transaction exists whose charge id
 * matches, so building the row directly keeps those tests about the routing
 * rather than about the charge. It also makes `assertNothingSent()` a statement
 * about the webhook instead of about the fixture.
 */
function cw39Sale(Business $business, Store $store, string $chargeId = 'chg_cw39'): Transaction
{
    return Transaction::create([
        'reference' => 'CW39-'.fake()->unique()->numerify('########'),
        'business_id' => $business->id,
        'store_id' => $store->id,
        'amount' => 2500,
        'fee_kobo' => 5000,
        'net_kobo' => 238750,
        'platform_fee_kobo' => 6250,
        // A pre-cutover collection, stated rather than left to the column
        // default: the ₦2,500 less Bachs' ₦50 less our ₦62.50 the net above
        // encodes is the old rule, and the fee is the business's in it.
        'provider_fee_bearer' => ProviderFeeBearer::BUSINESS,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED->value,
        'gateway_reference' => $chargeId,
        'paid_at' => now(),
        'settle_at' => now()->addDay(),
    ]);
}

/**
 * A payout Storify reserved and sent, sitting in the state the provider's
 * delivery will move it out of.
 */
function cw39Payout(Business $business, string $providerPayoutId, int $amountKobo = 238750, string $status = Payout::STATUS_PROCESSING): Payout
{
    $payout = Payout::reserve(
        (int) $business->id,
        null,
        'bachs',
        '0123456789',
        '058',
        'ADA TRADING LTD',
        $amountKobo,
    );

    $payout->forceFill([
        'status' => $status,
        'provider_payout_id' => $providerPayoutId,
    ])->save();

    return $payout->fresh();
}

/**
 * What a ledger account was debited or credited, on a given entry.
 *
 * Read through the mapping rather than by account code: the codes are the
 * template's, and the point of the assertion is which *key* the money landed on,
 * which is what the posting service chose.
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

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/*
|--------------------------------------------------------------------------
| The charge: Storify made it, so Storify holds the money
|--------------------------------------------------------------------------
*/

test('a delivery settles the sale into a wallet Storify holds, and into both sets of books', function () {
    cw39Platform();
    cw39FakeBachs();

    ['business' => $business, 'store' => $store, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    // Recorded at initialize, not looked up at settlement: how the charge was
    // made is a fact about the request, and an account id would be a fact about
    // a thing that no longer exists on either side.
    expect($transaction->metadata['payment_mode'])->toBe('platform_managed')
        ->and($transaction->metadata)->not->toHaveKey('gateway_account_id')
        ->and($transaction->metadata['platform_fee_kobo'])->toBe(6250);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_platform',
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

    // No store balance was credited, and this assertion is unchanged from the
    // old model for the opposite reason: the money used to be in the business's
    // own account at the provider, and now it is in ours. Either way it is not
    // money a store of theirs is holding.
    expect((int) $store->fresh()->balance)->toBe(0)
        ->and($transaction->balance_updated_at)->toBeNull();

    $businessId = (int) $business->id;
    $key = 'payment:txn:'.$transaction->id;

    // ₦2,500 gross, less our ₦62.50 — and Bachs' ₦50 nowhere in it. Storify
    // charges on its own credentials, so that fee comes off our balance rather
    // than the business's. What the business is owed is an asset against Storify
    // rather than a clearing balance at a provider — which is the whole point of
    // the account, and why it is a key of its own.
    expect(cw39Ledger($businessId, $key, 'held_by_platform'))->toBe(243750)
        ->and(cw39Ledger($businessId, $key, 'accounts_receivable', 'credit'))->toBe(250000)
        // One line on 5500, holding the only cost the business was charged: our
        // commission.
        ->and(cw39Ledger($businessId, $key, 'gateway_fees'))->toBe(6250)
        ->and(cw39Ledger($businessId, $key, 'gateway_clearing'))->toBe(0);

    // Our side, and the entry that did not exist before this change: the gross
    // is in our provider balance, we earned the commission, and we owe the
    // business its share. What we do *not* owe anyone is a payable to the
    // provider — the fee is our own cost, recognised on 5510 the moment we know
    // its size, which on a storefront sale is now.
    $platformKey = 'managed:collect:txn:'.$transaction->id;
    $feeKey = 'managed:provider_fee:txn:'.$transaction->id;

    expect(cw39Ledger(null, $platformKey, 'gateway_clearing'))->toBe(250000)
        ->and(cw39Ledger(null, $platformKey, 'business_funds_held', 'credit'))->toBe(243750)
        ->and(cw39Ledger(null, $platformKey, 'service_charge_income', 'credit'))->toBe(6250)
        ->and(cw39Ledger(null, $platformKey, 'provider_fees_payable', 'credit'))->toBe(0)
        // The fee as an expense, against the same clearing account: the provider
        // has already taken it out of what it is holding for us, so 1030 was
        // debited the full gross while the provider's balance is short by
        // exactly this.
        ->and(cw39Ledger(null, $feeKey, 'provider_processing_fees'))->toBe(5000)
        // The commission is *inside* that entry, so posting it separately would
        // be counting our own income twice.
        ->and(JournalEntry::where('idempotency_key', 'commission:txn:'.$transaction->id)->exists())->toBeFalse();

    // And the wallet: owed, but not yet payable — the provider has not settled
    // the charge to us, so paying it out would have Storify funding the gap.
    // Credited at once all the same, because the business's share never depended
    // on the fee the provider has not reported to us yet.
    $fresh = $business->fresh();

    expect((int) $fresh->pending_balance)->toBe(243750)
        ->and((int) $fresh->available_balance)->toBe(0)
        ->and($transaction->settle_at)->not->toBeNull();

    expect(JournalEntry::where('business_id', $businessId)->where('idempotency_key', $key)->sole()->isBalanced())->toBeTrue();
});

test('a redelivered settlement is answered, and settles nothing twice', function () {
    cw39Platform();
    cw39FakeBachs();

    ['business' => $business, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    $charge = ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'];

    foreach (['evt_cw39_first', 'evt_cw39_second'] as $eventId) {
        [$body, $headers] = cw39Signed([
            'id' => $eventId,
            'type' => 'checkout.completed',
            'account' => 'acct_platform',
            'data' => ['checkout_id' => 'chk_cw39', 'payment_status' => 'paid', 'charge' => $charge],
        ]);

        cw39Post($this, $body, $headers)->assertOk();
    }

    // A second delivery is *not* a duplicate — it is a different event about the
    // same charge — so it is not refused. What stops it settling twice is the
    // transaction's own status, which both paths consult.
    expect((float) $order->fresh()->amount_paid)->toBe(2500.0)
        ->and(JournalEntry::where('idempotency_key', 'payment:txn:'.$transaction->id)->count())->toBe(1)
        ->and(JournalEntry::where('idempotency_key', 'managed:collect:txn:'.$transaction->id)->count())->toBe(1)
        ->and((int) $business->fresh()->pending_balance)->toBe(243750);
});

test('the browser verify and the delivery cannot both settle one sale', function () {
    // Bachs fires `checkout.completed` while the customer's browser is still on
    // its way back, so the two race. Whichever arrives first must be the only one
    // that counts, and the second must not post a second set of entries.
    cw39Platform();
    cw39FakeBachs();

    ['store' => $store, 'order' => $order, 'transaction' => $transaction] = cw39Charge($this);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/verify', [
        'reference' => $transaction->reference,
    ], storefrontCustomerHeaders($store))->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_platform',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk();

    expect((float) $order->fresh()->amount_paid)->toBe(2500.0)
        ->and(JournalEntry::where('idempotency_key', 'payment:txn:'.$transaction->id)->count())->toBe(1)
        ->and(JournalEntry::where('idempotency_key', 'managed:collect:txn:'.$transaction->id)->count())->toBe(1);
});

test('a delivery that names no account at all is settled anyway', function () {
    // The account field is not what the endpoint works from any more, so its
    // absence changes nothing: the delivery is identified by its charge and the
    // charge is all a settlement needs. A rewrite that re-introduced a guard on
    // this field would pass every other test in this file and fail this one.
    cw39Platform();
    cw39FakeBachs();

    ['business' => $business, 'transaction' => $transaction] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_anonymous',
        'type' => 'checkout.completed',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((int) $business->fresh()->pending_balance)->toBe(243750)
        // Nothing was named, so nothing was written down as having been.
        ->and(ConnectWebhookEvent::query()->where('event_id', 'evt_cw39_anonymous')->sole()->account_id)->toBeNull();
});

test('a delivery naming an account we have never seen is recorded, not turned away', function () {
    // This returned "unknown account" and did nothing, which was right when the
    // account decided whose sale it was. It no longer does: the name identifies
    // nothing, the charge does, and a delivery refused over an unknown account
    // would be a settlement dropped for a field we do not read. The log line is
    // what keeps the name visible for somebody reading a delivery back.
    cw39Platform();
    cw39FakeBachs();

    ['transaction' => $transaction] = cw39Charge($this);

    Log::spy();

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_someone_else',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []) => $message === 'bachs.connect.account_named'
            && $context['account_id'] === 'acct_someone_else')
        ->atLeast()->once();
});

test('a delivery signed with anything but the platform\'s secret is a 400', function () {
    // 400 rather than 401 because the same body with the same secret fails
    // again — a 4xx is how the provider is told not to spend its retry budget on
    // it. Nothing about the payment changes.
    cw39Platform();
    cw39FakeBachs();

    ['transaction' => $transaction, 'order' => $order] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_platform',
        'data' => ['checkout_id' => 'chk_cw39', 'payment_status' => 'paid'],
    ], 'not-the-platform-secret');

    cw39Post($this, $body, $headers)->assertStatus(400);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::PENDING)
        ->and((float) $order->fresh()->amount_paid)->toBe(0.0);
});

test('an unconfigured signing secret is a 503, not a rejected delivery', function () {
    // Our misconfiguration, not a bad delivery — so the provider should retry it,
    // and once someone sets the value the same delivery verifies.
    cw39Platform();
    config(['services.bachs.webhook_secret' => null, 'services.bachs.connect_webhook_secret' => null]);
    cw39FakeBachs();

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_platform',
        'data' => ['checkout_id' => 'chk_cw39', 'payment_status' => 'paid'],
    ]);

    cw39Post($this, $body, $headers)->assertStatus(503);
});

test('the same delivery twice is claimed by its event id, and the second is not worked', function () {
    // Deduplicated on the provider's own event id, and claimed *before* any work
    // rather than recorded after it — this is the difference between paying a
    // business once and paying it twice.
    cw39Platform();
    cw39FakeBachs();

    ['business' => $business, 'transaction' => $transaction] = cw39Charge($this);

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_repeat',
        'type' => 'checkout.completed',
        'account' => 'acct_platform',
        'data' => [
            'checkout_id' => 'chk_cw39',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_cw39', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    // Byte for byte the same delivery, so it is the same event and not a second
    // one about the same charge.
    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(JournalEntry::where('idempotency_key', 'managed:collect:txn:'.$transaction->id)->count())->toBe(1)
        ->and((int) $business->fresh()->pending_balance)->toBe(243750);

    // And the event is on record as handled, which is what makes the second
    // delivery cheap rather than merely harmless.
    $claim = ConnectWebhookEvent::query()->where('event_id', 'evt_cw39_repeat')->sole();

    expect($claim->status)->toBe('success')
        ->and($claim->account_id)->toBe('acct_platform')
        ->and($claim->note)->not->toBeNull();
});

test('a delivery the provider names no id for is still processed', function () {
    // A claim cannot be taken on an event with no id, and refusing to process it
    // would be worse than the risk of doing one thing twice — the handlers
    // themselves tolerate a repeat, and dropping a settlement over a missing
    // field would lose the sale.
    cw39Platform();
    cw39FakeBachs();

    ['transaction' => $transaction] = cw39Charge($this);

    Log::spy();

    [$body, $headers] = cw39Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_platform',
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

/*
|--------------------------------------------------------------------------
| What the catalogue says about everything else
|--------------------------------------------------------------------------
*/

test('an account event is acknowledged and read by nobody', function () {
    // `account.updated` used to send us to the provider for the account's
    // capabilities, because a restricted one had to stop the storefront offering
    // that method. The account is gone and nothing depends on the answer, so the
    // assertion is the absence of that call — a rewrite that kept refreshing
    // would pass a status check and fail here.
    cw39Platform();
    cw39FakeBachs();

    ['transaction' => $transaction] = cw39Charge($this);

    // Re-faked after the charge, so the recorder the assertion below reads holds
    // the delivery and not the two calls that opened the checkout.
    cw39FakeBachs();

    Log::spy();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_account',
        'type' => 'account.updated',
        'account' => 'acct_platform',
        'data' => ['id' => 'acct_platform', 'is_active' => true],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'noted');

    Http::assertNothingSent();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::PENDING)
        ->and(JournalEntry::where('idempotency_key', 'payment:txn:'.$transaction->id)->exists())->toBeFalse();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []) => $message === 'bachs.connect.noted'
            && $context['type'] === 'account.updated')
        ->once();
});

test('a capability event is acknowledged and read by nobody', function () {
    // The other half of the same absence, kept separate because they arrive from
    // different parts of the provider and a rewrite could plausibly restore one
    // without the other.
    cw39Platform();
    cw39FakeBachs();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_capability',
        'type' => 'capability.updated',
        'account' => 'acct_platform',
        'data' => ['id' => 'acct_platform', 'capability' => ['name' => 'card_collection', 'status' => 'restricted']],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'noted');

    Http::assertNothingSent();
});

test('an event about a Bachs product we never call is dropped without a warning', function () {
    // Understood, and nothing to do. It is not an unknown slug — the catalogue
    // names it — so it must not announce itself as a surprise, and it must not be
    // acted on either.
    cw39Platform();
    cw39FakeBachs();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_invoice',
        'type' => 'invoice.paid',
        'account' => 'acct_platform',
        'data' => ['id' => 'inv_cw39'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'ignored');
});

test('an event Bachs does not document is logged as a surprise, not absorbed', function () {
    // This is how a newly published slug announces itself. Treating it as one of
    // the quietly-unused events would hide it for a year.
    cw39Platform();
    cw39FakeBachs();

    Log::spy();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_new',
        'type' => 'something.never.documented',
        'account' => 'acct_platform',
        'data' => ['id' => 'obj_cw39_new'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'unhandled event');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'bachs.connect.unhandled_event'
            && $context['type'] === 'something.never.documented')
        ->once();
});

test('every event Bachs documents is answered without an error', function () {
    // The completeness assertion, walked rather than described: each slug in the
    // catalogue is delivered and must be answered 2xx. A `4xx` would stop the
    // provider retrying a delivery that may matter, and a `5xx` would make it
    // retry one that never will.
    cw39Platform();
    cw39FakeBachs();

    $catalogue = BachsEventCatalogue::all();

    expect($catalogue)->toHaveCount(28);

    foreach ($catalogue as $row) {
        [$body, $headers] = cw39Signed([
            // Unique per slug, or the claim would answer every one after the
            // first as a duplicate and the walk would assert nothing.
            'id' => 'evt_cw39_walk_'.$row['type'],
            'type' => $row['type'],
            'account' => 'acct_platform',
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
    expect(ConnectWebhookEvent::query()->count())->toBe(28);
});

test('the endpoint is keyed by the platform\'s own credentials', function () {
    // The key is the credential map and nothing else: holding a key for a
    // provider is exactly what declares the platform can charge through it, and
    // these deliveries are about charges the platform made. A provider we hold
    // no key for has nothing for a delivery here to be about, so it is refused
    // rather than quietly parsed by a driver that cannot settle against it.
    //
    // Both halves are asserted, because "accepts Paystack" and "refuses Monnify"
    // are the same fact seen from either side: a rewrite that opened the endpoint
    // to every provider would pass the first and fail the second.
    $this->call('POST', '/webhooks/connect/monnify', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], '{}')->assertStatus(404);

    // Accepted as a provider, then refused as a delivery: the empty body cannot
    // carry a signature that verifies, which is a 400 rather than the 404 an
    // unknown provider gets.
    $this->call('POST', '/webhooks/connect/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], '{}')->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| Money leaving: our balance, our payouts
|--------------------------------------------------------------------------
|
| A payout is no longer a line in a JSON array on a connection. It is a row in
| `payouts`, reserved before anything is sent, and these deliveries move it.
*/

test('a payout the provider reports paid posts the withdrawal on both sets of books', function () {
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $payout = cw39Payout($business, 'po_cw39_paid');

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_payout_paid',
        'type' => 'payout.paid',
        'account' => 'acct_platform',
        'data' => ['id' => 'po_cw39_paid', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'payout recorded');

    $fresh = $payout->fresh();

    expect($fresh->status)->toBe(Payout::STATUS_PAID)
        ->and($fresh->paid_at)->not->toBeNull();

    // The business's money left our hands for its bank…
    expect(cw39Ledger((int) $business->id, 'payout:'.$payout->reference, 'bank'))->toBe(238750)
        ->and(cw39Ledger((int) $business->id, 'payout:'.$payout->reference, 'held_by_platform', 'credit'))->toBe(238750);

    // …and we are no longer holding it.
    expect(cw39Ledger(null, 'custody_payout:'.$payout->reference, 'business_funds_held'))->toBe(238750)
        ->and(cw39Ledger(null, 'custody_payout:'.$payout->reference, 'gateway_clearing', 'credit'))->toBe(238750);
});

test('a payout the provider reports failed gives the reservation back and posts nothing', function () {
    // The send never moved the money, so the wallet is owed it again. No entry is
    // written, and none needs unwinding: the posting happens on the way *into*
    // paid, and a payout that never got there has nothing to reverse.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $payout = cw39Payout($business, 'po_cw39_failed');

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_payout_failed',
        'type' => 'payout.failed',
        'account' => 'acct_platform',
        'data' => [
            'id' => 'po_cw39_failed',
            'status' => 'failed',
            'amount' => '2387.50',
            'failure_reason' => 'Bank rejected the account.',
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'payout recorded');

    $fresh = $payout->fresh();

    expect($fresh->status)->toBe(Payout::STATUS_FAILED)
        ->and($fresh->failure_reason)->toBe('Bank rejected the account.')
        // The reservation is back, and it is *available*: nothing is in flight
        // for it any more, so the next sweep may send it again.
        ->and((int) $business->fresh()->available_balance)->toBe(238750);

    expect(JournalEntry::where('idempotency_key', 'payout:'.$payout->reference)->exists())->toBeFalse()
        ->and(JournalEntry::where('idempotency_key', 'custody_payout:'.$payout->reference)->exists())->toBeFalse();
});

test('a payout delivery we have no row for is answered, not errored', function () {
    // Either another environment's payout or one from before the table existed,
    // and neither is fixed by a retry. The log line inside the sweep is what
    // makes it visible; an error status would make the provider send it forever.
    cw39Platform();
    cw39FakeBachs();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_payout_stranger',
        'type' => 'payout.paid',
        'account' => 'acct_platform',
        'data' => ['id' => 'po_stranger', 'status' => 'completed', 'amount' => '100.00'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'payout not found');
});

test('a completed payout is not walked back by a delivery that arrives late', function () {
    // `payout.paid` then a redelivered `payout.created`. The row has left the
    // states a send can be confirmed from, so the late one is recorded and
    // changes nothing — without that, the row would read "in flight" for money
    // the bank already has, while the entries posted on the way into paid stayed
    // exactly where they were.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $payout = cw39Payout($business, 'po_cw39_late');

    [$paidBody, $paidHeaders] = cw39Signed([
        'id' => 'evt_cw39_paid',
        'type' => 'payout.paid',
        'account' => 'acct_platform',
        'data' => ['id' => 'po_cw39_late', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $paidBody, $paidHeaders)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);

    // The late one. A different event id, so it is genuinely a second delivery
    // rather than a retry being deduplicated.
    [$lateBody, $lateHeaders] = cw39Signed([
        'id' => 'evt_cw39_created',
        'type' => 'payout.created',
        'account' => 'acct_platform',
        'data' => ['id' => 'po_cw39_late', 'status' => 'processing', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $lateBody, $lateHeaders)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID)
        // Still one entry, not two.
        ->and(JournalEntry::where('idempotency_key', 'payout:'.$payout->reference)->count())->toBe(1);
});

test('a payout the provider reverses after it landed gives the money back and unwinds both entries', function () {
    // A recall is the one delivery that undoes something the provider has
    // already told us: `payout.paid` arrived, the row became `paid`, the entries
    // were posted — and then the bank sent the money back. So the row this
    // arrives about is `paid`, and claiming a reversal only from `processing`
    // matched nothing: the update changed no rows, the method returned at its
    // guard, and the endpoint still answered "payout recorded" while the wallet
    // showed the money gone and both sets of books showed it paid out.
    //
    // Both halves are asserted, because either one alone is a pass on a broken
    // system: the row has to move, and the money has to come back.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $payout = cw39Payout($business, 'po_cw39_returned');

    [$paidBody, $paidHeaders] = cw39Signed([
        'id' => 'evt_cw39_returned_paid',
        'type' => 'payout.paid',
        'account' => 'acct_platform',
        'data' => ['id' => 'po_cw39_returned', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $paidBody, $paidHeaders)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_returned_reversed',
        'type' => 'payout.reversed',
        'account' => 'acct_platform',
        'data' => [
            'id' => 'po_cw39_returned',
            'status' => 'reversed',
            'amount' => '2387.50',
            'failure_reason' => 'Recalled by the beneficiary bank.',
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'payout recorded');

    $fresh = $payout->fresh();

    expect($fresh->status)->toBe(Payout::STATUS_REVERSED)
        ->and($fresh->reversed_at)->not->toBeNull()
        // The business does not have the money and we do, so what we owe it goes
        // back up by exactly what we took out. Without this the payout is a
        // subtraction nothing records.
        ->and((int) $business->fresh()->available_balance)->toBe(238750);

    // Both entries that said "paid out" are voided…
    expect(JournalEntry::where('idempotency_key', 'payout:'.$payout->reference)->sole()->status)
        ->toBe(JournalEntry::STATUS_VOID)
        ->and(JournalEntry::where('idempotency_key', 'custody_payout:'.$payout->reference)->sole()->status)
        ->toBe(JournalEntry::STATUS_VOID);

    // …and the mirror of each is posted under a key of its own, so the books say
    // the money came back rather than merely that something was undone.
    expect(cw39Ledger((int) $business->id, 'payout_returned:'.$payout->reference, 'bank', 'credit'))->toBe(238750)
        ->and(cw39Ledger((int) $business->id, 'payout_returned:'.$payout->reference, 'held_by_platform'))->toBe(238750)
        ->and(cw39Ledger(null, 'custody_payout_returned:'.$payout->reference, 'business_funds_held', 'credit'))->toBe(238750)
        ->and(cw39Ledger(null, 'custody_payout_returned:'.$payout->reference, 'gateway_clearing'))->toBe(238750);
});

test('a refusal arriving after the transfer landed does not pay the business twice', function () {
    // Deliveries are not ordered, and `payout.paid` is not necessarily the last
    // one a provider sends: a `failed` for the same withdrawal can be delivered
    // after the completion was already handled. Acting on it would flip a `paid`
    // row back and credit the wallet for money the business has been sent — the
    // transfer *and* the credit, for one debt, with both sets of books agreeing
    // that neither happened.
    //
    // So the refusal is claimed by condition like every other transition, and a
    // row that has already reached a final status is left where it is.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $payout = cw39Payout($business, 'po_cw39_twice');

    [$paidBody, $paidHeaders] = cw39Signed([
        'id' => 'evt_cw39_twice_paid',
        'type' => 'payout.paid',
        'account' => 'acct_platform',
        'data' => ['id' => 'po_cw39_twice', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $paidBody, $paidHeaders)->assertOk();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_twice_failed',
        'type' => 'payout.failed',
        'account' => 'acct_platform',
        'data' => [
            'id' => 'po_cw39_twice',
            'status' => 'failed',
            'amount' => '2387.50',
            'failure_reason' => 'Bank rejected the account.',
        ],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'payout recorded');

    // Still paid, still nothing owed back, and the entry that says the money left
    // is still standing.
    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID)
        ->and($payout->fresh()->failed_at)->toBeNull()
        ->and((int) $business->fresh()->available_balance)->toBe(0)
        ->and(JournalEntry::where('idempotency_key', 'payout:'.$payout->reference)->sole()->status)
        ->toBe(JournalEntry::STATUS_POSTED);
});

test('a payout delivered by Paystack is routed to the payout row, not to the charge handler', function () {
    // Paystack calls these `transfer.*`, and its driver is where that vocabulary
    // belongs — the same place `charge.success` is translated. Before it was
    // translated, a `transfer.reversed` parsed as an event nothing acts on, so
    // the reversal reached no handler at all on this rail, and the sweep would
    // not find it either: it only re-reads rows it still believes are moving,
    // and this one is `paid`.
    cw39Platform();

    // Paystack signs with the secret key itself, so this one value is both the
    // signing secret and the credential the endpoint would charge on.
    config(['services.paystack.secret_key' => 'sk_test_platform_paystack']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $payout = cw39Payout($business, 'TRF_cw39_paystack');
    $payout->forceFill(['provider' => 'paystack', 'status' => Payout::STATUS_PAID, 'paid_at' => now()])->save();

    // The entries a settlement would have posted, so the reversal has something
    // to unwind.
    app(LedgerPostingService::class)->postGatewayWithdrawal(
        (int) $business->id,
        null,
        238750,
        (string) $payout->reference,
        custody: true,
    );

    $body = json_encode([
        'event' => 'transfer.reversed',
        'data' => [
            'id' => 99887766,
            'transfer_code' => 'TRF_cw39_paystack',
            'reference' => $payout->reference,
            'status' => 'reversed',
            'amount' => 238750,
        ],
    ]);

    $this->call('POST', '/webhooks/connect/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_platform_paystack'),
    ], $body)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_REVERSED)
        ->and((int) $business->fresh()->available_balance)->toBe(238750);
});

test('a payout delivery that names no account is matched by its id', function () {
    // The delivery pays a withdrawal by naming `withdrawal_id` — the name
    // `GET /v1/payouts/{withdrawal_id}` uses — and nothing else. Dropping it for
    // want of an account would leave our records saying money left when it did
    // not, and there is no account for it to name now in any case.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $payout = cw39Payout($business, 'po_cw39_alias');

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_alias',
        'type' => 'payout.paid',
        'data' => ['withdrawal_id' => 'po_cw39_alias', 'status' => 'completed', 'amount' => '2387.50'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'payout recorded');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);
});

/*
|--------------------------------------------------------------------------
| Disputes and refunds
|--------------------------------------------------------------------------
|
| Both arrive against **Storify's** merchant account now, so both are placed by
| the charge rather than by an account. A chargeback is money leaving our balance
| with a business's share going with it; a refund outcome says whether money that
| was already refunded reached the customer.
*/

test('a dispute is recorded by its charge, and never answered on the business\'s behalf', function () {
    // Money has already gone — the provider debits a flat fee the moment a
    // dispute is opened — and there is a deadline to answer. What this does is
    // record it faithfully and raise it. Submitting evidence is a human decision
    // about a real customer, so nothing here sends anything anywhere.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = cw39Store($owner);
    $sale = cw39Sale($business, $store);

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_dispute',
        'type' => 'dispute.created',
        'account' => 'acct_platform',
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

    // Filed against the charge, and the charge is what somebody answering it
    // will be looking for — there is no connection row to hang it on and none is
    // wanted.
    $log = DB::table('activity_logs')
        ->where('business_id', $business->id)
        ->where('action', 'payment_gateway.dispute_needs_response')
        ->sole();

    $payload = json_decode((string) $log->new_values, true);

    expect($payload['dispute_id'])->toBe('dp_cw39')
        ->and($payload['charge_id'])->toBe('chg_cw39')
        ->and($payload['reason'])->toBe('product_not_received')
        // The window is the actionable part, and it is kept.
        ->and($payload['response_deadline_at'])->toBe('2026-11-01T00:00:00Z');

    expect(json_decode((string) $log->metadata, true)['charge'])->toBe($sale->reference);

    // A dispute that is only *asked* moves nothing: the money is gone from our
    // provider balance while it is being answered, which is a cash-flow fact,
    // not yet a debt for the business.
    expect($sale->fresh()->unwound_at)->toBeNull()
        ->and((int) $business->fresh()->available_balance)->toBe(0);

    // Nothing was submitted.
    Http::assertNothingSent();
});

test('a dispute we cannot attach to a charge is reported rather than guessed at', function () {
    // Money has left Storify's balance and there is nobody recorded as owing it.
    // Answered 200 all the same — a retry walks into the same dead end — and the
    // service logs it as an error rather than a warning, which is the difference
    // between a delivery somebody reads and one that scrolls past.
    cw39Platform();
    cw39FakeBachs();

    Log::spy();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_dispute_orphan',
        'type' => 'dispute.created',
        'account' => 'acct_platform',
        'data' => ['id' => 'dp_cw39_orphan', 'charge_id' => 'chg_nobody_knows', 'status' => 'needs_response'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute not recorded');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'bachs.dispute.unknown_charge')
        ->once();
});

test('a refund that did not reach the customer is recorded and raised; one that did is recorded quietly', function () {
    // Our refund call returns as soon as the provider accepts it, which is not
    // the same as the customer being paid. Without this a failed refund looks
    // exactly like a successful one.
    cw39Platform();
    cw39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = cw39Store($owner);
    cw39Sale($business, $store);

    [$paidBody, $paidHeaders] = cw39Signed([
        'id' => 'evt_cw39_rf_paid',
        'type' => 'refund.paid',
        'account' => 'acct_platform',
        'data' => ['id' => 'rf_cw39_ok', 'charge_id' => 'chg_cw39', 'status' => 'paid', 'refunded_amount' => '500.00', 'currency' => 'NGN'],
    ]);

    cw39Post($this, $paidBody, $paidHeaders)->assertOk()->assertJsonPath('status', 'refund recorded');

    [$failedBody, $failedHeaders] = cw39Signed([
        'id' => 'evt_cw39_rf_failed',
        'type' => 'refund.failed',
        'account' => 'acct_platform',
        'data' => ['id' => 'rf_cw39_bad', 'charge_id' => 'chg_cw39', 'status' => 'failed', 'requested_amount' => '500.00', 'currency' => 'NGN', 'failure_reason' => 'card_declined'],
    ]);

    cw39Post($this, $failedBody, $failedHeaders)->assertOk()->assertJsonPath('status', 'refund recorded');

    // Only the failure is raised: a refund that worked is the expected end of a
    // refund somebody already made, and logging every one would bury the one
    // that matters.
    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'payment_gateway.refund_failed',
    ]);

    expect(cw39Actions($business))->not->toContain('payment_gateway.refund_paid');
});

test('a refund outcome for a charge we cannot place is reported, not recorded', function () {
    // The refund was posted by whoever made it; this delivery only says whether
    // it arrived. One that names a charge we cannot find is money that did not
    // reach a customer with nothing on our side to attach it to.
    cw39Platform();
    cw39FakeBachs();

    Log::spy();

    [$body, $headers] = cw39Signed([
        'id' => 'evt_cw39_rf_orphan',
        'type' => 'refund.failed',
        'account' => 'acct_platform',
        'data' => ['id' => 'rf_cw39_orphan', 'charge_id' => 'chg_nobody_knows', 'status' => 'failed'],
    ]);

    cw39Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'refund not recorded');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'bachs.refund.unknown_charge')
        ->once();
});
