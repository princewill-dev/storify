<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerSetupService;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| WS-41 — the transaction says what the business was paid.
|
| Storify collects the sale, so the money is divided here rather than at the
| provider: the customer's ₦2,500 is charged on Storify's account, Bachs takes
| its fee, our commission is kept, and ₦2,387.50 is owed to the business and paid
| out of its wallet. The transaction used to report only part of that.
| `net_kobo` was written as `amount - fee_kobo`, with no commission anywhere in
| the expression, so ₦2,450 was presented as what reached the bank when ₦2,387.50
| did — and the commission itself lived only in a metadata key no endpoint
| returned.
|
| These tests pin the replacement contract at every surface a business reads:
|
|     amount         = what the customer paid          (2500.00)
|     processing_fee = Bachs' fee + Storify's cut      ( 112.50)
|     net            = what the business is owed       (2387.50)
|
| and the one case where no honest net exists at all — a till sale the provider
| has not settled yet, which must report nothing rather than something wrong.
|
| The commission stays a single combined figure in front of the business. That
| it is really two costs is recorded (on the row and on our own platform books)
| rather than shown.
*/

function pf41Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * Write the singleton settings row the platform reads.
 *
 * `first()`-then-`update` rather than `updateOrCreate(['id' => 1])`, and the
 * difference is not stylistic: `id` is not fillable on `Setting`, so the lookup
 * constraint is applied to the query but dropped from the insert. A row is
 * therefore never created *with* id 1, the update branch never matches, and
 * every call inserts another row — while `SystemProcessor`, which reads the
 * first one, keeps seeing the empty row that landed lowest. InnoDB does not
 * reset `AUTO_INCREMENT` on rollback, so this only bites from the second test in
 * the file onwards: the first call happens to get id 1 and works.
 */
function pf41Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function pf41Commission(float $percent = 2.5): void
{
    pf41Settings([
        'platform_commission_percent' => $percent,
        'platform_commission_fixed_kobo' => 0,
    ]);
}

/**
 * A business Storify collects for through Bachs.
 *
 * That is the whole of it now. This used to seed an account at the provider and
 * the `managed` blob the resolver read out of a payment-method row; both are
 * gone, and the fact lives on the business itself. No payment-method row is
 * written, and that is deliberate rather than convenient — a collected business
 * needs none to be charged, so seeding one would hide the regression where a
 * business that never opened the screen stops being chargeable.
 */
function pf41Managed(Business $business): void
{
    pf41Settings(['system_payment_processor' => 'bachs']);

    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();
}

function pf41Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'pf41-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function pf41Order(Store $store, array $attributes = []): Order
{
    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'PF41-'.fake()->unique()->numerify('######'),
        'subtotal' => 2500,
        'total' => 2500,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

/**
 * Bachs, answering every call a managed sale makes.
 *
 * ₦2,500 charged, Bachs' own ₦50 processing fee, split at source. The balance it
 * reports is zero on purpose: "the charge settled" and "the money is movable"
 * are different facts, and the state right after a sale is the first without the
 * second.
 */
function pf41FakeBachs(array $chargeOverrides = []): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($chargeOverrides) {
        $url = $request->url();

        if (str_contains($url, '/balances')) {
            return Http::response([
                'balances' => [['currency' => 'NGN', 'available_balance' => '0.00', 'pending_balance' => '2500.00']],
            ]);
        }

        if (str_contains($url, '/payouts') && $request->method() === 'POST') {
            return Http::response(['id' => 'po_pf41', 'status' => 'pending', 'amount' => '0.00']);
        }

        if (str_contains($url, '/accounts/')) {
            return Http::response([
                'id' => 'acct_pf41',
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
                return Http::response([
                    'checkout_id' => 'chk_pf41',
                    'status' => 'completed',
                    'charge' => array_merge([
                        'id' => 'chg_pf41',
                        'status' => 'succeeded',
                        'amount' => '2500.00',
                        'currency' => 'NGN',
                        'processing_fee' => '50.00',
                    ], $chargeOverrides),
                ]);
            }

            return Http::response([
                'checkout_id' => 'chk_pf41',
                'checkout_url' => 'https://checkout.bachs.io/chk_pf41',
                'status' => 'open',
            ]);
        }

        return Http::response(['status' => 'ok']);
    });
}

/**
 * @return array{0: string, 1: array<string, string>}
 */
function pf41Signed(array $payload, string $secret = 'whsec_platform'): array
{
    $body = json_encode($payload);
    $timestamp = time();

    return [
        $body,
        ['HTTP_X_BACHS_SIGNATURE_V2' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)],
    ];
}

function pf41Post(TestCase $test, string $body, array $headers): TestResponse
{
    return $test->call('POST', '/webhooks/connect/bachs', [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $headers), $body);
}

/**
 * A delivery telling us a managed sale settled.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function pf41Settlement(): array
{
    return pf41Signed([
        'type' => 'checkout.completed',
        'account' => 'acct_pf41',
        'data' => [
            'checkout_id' => 'chk_pf41',
            'payment_status' => 'paid',
            'charge' => ['id' => 'chg_pf41', 'status' => 'succeeded', 'amount' => '2500.00', 'currency' => 'NGN'],
        ],
    ]);
}

/**
 * A ₦2,500 sale charged through the platform, ready to be told about.
 *
 * @return array{owner: User, business: Business, store: Store, order: Order, transaction: Transaction}
 */
function pf41Charge(TestCase $test): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pf41Managed($business);

    $store = pf41Store($owner);
    $order = pf41Order($store);

    $test->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ])->assertOk()->assertJsonPath('data.authorization_url', 'https://checkout.bachs.io/chk_pf41');

    return [
        'owner' => $owner,
        'business' => $business,
        'store' => $store,
        'order' => $order,
        'transaction' => Transaction::where('order_id', $order->id)->sole(),
    ];
}

function pf41Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * What a ledger account was debited on a given entry.
 *
 * Read through the mapping, not by account code: which *key* the money landed on
 * is what the posting service chose, and that is the thing under test.
 */
function pf41Ledger(?int $businessId, string $idempotencyKey, string $accountKey, string $column = 'debit'): int
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

test('a managed sale reports the combined fee and the money that reached the bank', function () {
    // The header case. ₦2,500 paid; Bachs takes ₦50, we keep ₦62.50, and
    // ₦2,387.50 is what the business is owed. Before this change the row said
    // ₦2,450 — the commission simply absent from the sum.
    pf41Platform();
    pf41Commission();
    pf41FakeBachs();

    ['business' => $business, 'store' => $store, 'transaction' => $transaction] = pf41Charge($this);

    [$body, $headers] = pf41Settlement();
    pf41Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'success');

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        // The provider's own charge, on its own column.
        ->and($transaction->fee_kobo)->toBe(5000)
        // Our cut, promoted out of metadata into a column so it can be summed.
        ->and($transaction->platform_fee_kobo)->toBe(6250)
        // Both, as one figure, which is what the business is shown.
        ->and($transaction->processingFeeKobo())->toBe(11250)
        // And what actually arrived. This is the assertion the whole change is
        // about: it is 238750, not 245000.
        ->and($transaction->net_kobo)->toBe(238750)
        ->and($transaction->netKobo())->toBe(238750);

    // The charge-time record stays where it was. It is the historical statement
    // of what was charged, and the only thing a pre-column row can be backfilled
    // from.
    expect($transaction->metadata['platform_fee_kobo'])->toBe(6250);
});

test('the browser return trip writes the same net the webhook would', function () {
    // Settlement has two doors and a sale arrives through whichever gets there
    // first. This one wrote `fee_kobo` and then stopped — no net at all — so a
    // payment settled by the customer's own return trip showed an empty Net
    // forever, whatever the fee was.
    pf41Platform();
    pf41Commission();
    pf41FakeBachs();

    ['store' => $store, 'transaction' => $transaction] = pf41Charge($this);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/verify', [
        'reference' => $transaction->reference,
    ])->assertOk();

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        ->and($transaction->fee_kobo)->toBe(5000)
        ->and($transaction->platform_fee_kobo)->toBe(6250)
        ->and($transaction->net_kobo)->toBe(238750)
        // A managed sale credits no store balance of ours — the money is in the
        // business's own account at Bachs.
        ->and((int) $store->fresh()->balance)->toBe(0);
});

test('the two fees post as one processing fee line', function () {
    // The business's books hold one combined cost, matching what it sees on the
    // transaction. Two debits to the same account with different descriptions
    // made the ledger the only place our margin was visible — on the business's
    // own books, of all places.
    pf41Platform();
    pf41Commission();
    pf41FakeBachs();

    ['business' => $business, 'transaction' => $transaction] = pf41Charge($this);

    [$body, $headers] = pf41Settlement();
    pf41Post($this, $body, $headers)->assertOk();

    $key = 'payment:txn:'.$transaction->id;

    // The business holds a claim on Storify rather than a balance at the
    // provider, so the debit is 1040 — not 1030, which is what a sale charged
    // through the business's *own* keys lands on. The two are kept apart on
    // purpose: only one of them is money Storify is holding for somebody.
    expect(pf41Ledger($business->id, $key, 'held_by_platform'))->toBe(238750)
        ->and(pf41Ledger($business->id, $key, 'accounts_receivable', 'credit'))->toBe(250000);

    // One line, carrying both fees. Summed through the same mapping helper the
    // rest of the suite uses, so a second line would still add up — hence the
    // count below, which is what actually pins "combined".
    expect(pf41Ledger($business->id, $key, 'gateway_fees'))->toBe(11250)
        ->and(pf41ProcessingFeeLines($business->id, $key))->toBe(1)
        ->and(pf41ProcessingFeeDescription($business->id, $key))->toBe('Processing fee '.$transaction->reference);

    // Ours, on our own books: the whole charge lands in our clearing account and
    // is split across what we owe the business, our commission, and the fee the
    // provider will take. There is no second commission entry — the provider no
    // longer takes our cut off before the money reaches us, so recognising it
    // here *and* there would count the same income twice.
    $platformKey = 'managed:collect:txn:'.$transaction->id;

    expect(pf41Ledger(null, $platformKey, 'gateway_clearing'))->toBe(250000)
        ->and(pf41Ledger(null, $platformKey, 'business_funds_held', 'credit'))->toBe(238750)
        ->and(pf41Ledger(null, $platformKey, 'service_charge_income', 'credit'))->toBe(6250)
        ->and(pf41Ledger(null, $platformKey, 'provider_fees_payable', 'credit'))->toBe(5000)
        ->and(pf41Ledger(null, 'commission:txn:'.$transaction->id, 'gateway_clearing'))->toBe(0);
});

test('a till sale records the commission but claims no net until the provider settles', function () {
    // A POS leg is written the moment the till closes the sale; Bachs does not
    // report what it charged until the charge settles, days later. There is no
    // net to state, and writing `amount - commission` would put a number in a
    // column meaning "reached the bank" that no bank ever saw.
    pf41Platform();
    pf41Commission();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // The row is built to the exact shape ProcessPosSale writes for a managed
    // leg: the commission recorded, the provider's fee absent because it does
    // not exist yet.
    $transaction = Transaction::create([
        'reference' => 'TXN-PF41TILL',
        'business_id' => $business->id,
        'amount' => 2500,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'platform_fee_kobo' => 6250,
        'metadata' => ['payment_mode' => 'platform_managed', 'platform_fee_kobo' => 6250],
    ]);

    expect($transaction->platformFeeKobo())->toBe(6250)
        // Not 0, and not 6250: the fee is unknown, so the column says so.
        ->and($transaction->processingFeeKobo())->toBeNull()
        ->and($transaction->netKobo())->toBeNull()
        // The shared rule agrees, from the other direction — this is what the
        // writers call before the row carries these values.
        ->and(Transaction::netKoboFor(250000, null, 6250))->toBeNull();

    $token = pf41Token($owner);
    $totals = $this->withToken($token)->getJson('/api/v1/management/transactions?status=confirmed')
        ->assertOk()
        ->json('data.totals');

    // The bar says the net is incomplete rather than quietly reporting a smaller
    // one, which is the whole reason this count exists.
    //
    // `toEqual` rather than `toBe` on the money: PHP encodes a whole-naira float
    // as `2500` and it decodes back as an int, so an integral amount is never
    // identical to the float it was built from.
    expect($totals['fees_pending_count'])->toBe(1)
        ->and($totals['amount'])->toEqual(2500.0)
        ->and($totals['processing_fee'])->toEqual(62.5)
        ->and($totals['net'])->toEqual(2437.5);
});

test('a sale on the business\'s own keys carries no commission at all', function () {
    // The shared rule, from the other side: with no commission the processing
    // fee is the provider's alone, which is what every own-key settlement in the
    // codebase now computes through. Its net was never wrong, so nothing about
    // those rows moves.
    $transaction = new Transaction([
        'amount' => 1000,
        'fee_kobo' => 1500,
        'metadata' => ['payment_mode' => 'own_keys'],
    ]);

    expect($transaction->platformFeeKobo())->toBe(0)
        ->and($transaction->processingFeeKobo())->toBe(1500)
        ->and($transaction->netKobo())->toBe(98500)
        // Same expression the two own-key webhook paths call.
        ->and(Transaction::netKoboFor(100000, 1500, 0))->toBe(98500);
});

test('the list, the CSV and the totals agree on one processing fee', function () {
    pf41Platform();
    pf41Commission();
    pf41FakeBachs();

    ['owner' => $owner, 'transaction' => $transaction] = pf41Charge($this);

    [$body, $headers] = pf41Settlement();
    pf41Post($this, $body, $headers)->assertOk();

    $token = pf41Token($owner);

    $list = $this->withToken($token)->getJson('/api/v1/management/transactions')
        ->assertOk()
        ->assertJsonStructure(['data' => ['transactions', 'totals' => ['amount', 'processing_fee', 'net', 'fees_pending_count']]]);

    $row = collect($list->json('data.transactions'))->firstWhere('reference', $transaction->reference);

    expect($row['amount'])->toEqual(2500.0)
        ->and($row['processing_fee'])->toEqual(112.5)
        ->and($row['net'])->toEqual(2387.5)
        // The deprecated alias carries the same figure, so an SPA deployed on
        // either side of the API rename still shows a number here.
        ->and($row['fee'])->toEqual(112.5);

    expect($list->json('data.totals'))->toEqual([
        'amount' => 2500.0,
        'processing_fee' => 112.5,
        'net' => 2387.5,
        'fees_pending_count' => 0,
    ]);

    // The detail read model inherits the same three fields from the list row.
    $detail = $this->withToken($token)->getJson('/api/v1/management/transactions/'.$transaction->reference)
        ->assertOk();

    expect($detail->json('data.transaction.processing_fee'))->toBe(112.5)
        ->and($detail->json('data.transaction.net'))->toBe(2387.5);

    // And the CSV, which is the same resource rendered as columns.
    $csv = $this->withToken($token)->get('/api/v1/management/transactions/export')->streamedContent();

    expect($csv)->toContain('Processing Fee')
        ->and($csv)->toContain('112.50')
        ->and($csv)->toContain('2387.50');
});

test('renaming 5500 reaches existing businesses and spares a name they chose', function () {
    // The chart is seeded per business, and `ensureForBusiness()` only sets a
    // name when it creates the account. So changing the template's label
    // reaches nobody who already has books — which is every live business. This
    // data migration is what carries it to them.
    //
    // Run against the class directly rather than through `artisan migrate`: the
    // migration is already recorded as run for the test database, so the command
    // would skip it and this test would pass without executing a line.
    $migration = fn () => require database_path(
        'migrations/2026_10_12_000004_rename_gateway_fees_account_to_processing_fee.php'
    );

    [, $business] = createBusinessOwner();

    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    $account = LedgerAccount::where('business_id', $business->id)->where('code', '5500')->sole();

    // The template's new label, and the mapping key the posting engine resolves
    // this account through, which does not move with it.
    expect($account->name)->toBe('Processing Fee')
        ->and($account->subtype)->toBe('gateway_fees')
        // The mapping row the engine actually reads is untouched too.
        ->and(LedgerMapping::where('business_id', $business->id)->where('key', 'gateway_fees')->exists())->toBeTrue();

    // A business that has books from before the rename.
    $account->update(['name' => 'Bank & Gateway Fees']);

    $migration()->up();

    expect($account->fresh()->name)->toBe('Processing Fee');

    $migration()->down();

    expect($account->fresh()->name)->toBe('Bank & Gateway Fees');

    // And a business that called 5500 something of its own keeps it: the whole
    // reason the match is on the name and not only the code.
    $account->update(['name' => 'Card charges']);

    $migration()->up();

    expect($account->fresh()->name)->toBe('Card charges');
});

test('the backfill fills the commission column and corrects the net without --apply changing nothing', function () {
    pf41Platform();
    pf41Commission();

    [$owner, $business] = createBusinessOwner();

    // A row as the old code left it: the commission only in metadata, and a net
    // that subtracted the provider's fee and nothing else.
    $stale = Transaction::create([
        'reference' => 'TXN-PF41STALE',
        'business_id' => $business->id,
        'amount' => 2500,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 5000,
        'net_kobo' => 245000,
        'metadata' => ['payment_mode' => 'platform_managed', 'platform_fee_kobo' => 6250],
    ]);

    // A sale on the business's own keys, whose net was never wrong.
    $ownKeys = Transaction::create([
        'reference' => 'TXN-PF41OWN',
        'business_id' => $business->id,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 1500,
        'net_kobo' => 98500,
        'metadata' => ['payment_mode' => 'own_keys'],
    ]);

    // A managed leg with no provider fee: nothing to compute, and the run must
    // say so rather than write a guess.
    $awaiting = Transaction::create([
        'reference' => 'TXN-PF41TILL',
        'business_id' => $business->id,
        'amount' => 2500,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'metadata' => ['payment_mode' => 'platform_managed'],
    ]);

    $this->artisan('payments:backfill-processing-fees')
        ->expectsOutputToContain('Transactions examined: 3')
        ->expectsOutputToContain('Commission columns to fill: 1')
        ->expectsOutputToContain('Net amounts to correct: 1')
        ->expectsOutputToContain('Managed sales awaiting the provider\'s fee: 1')
        ->assertSuccessful();

    // A dry run is a dry run. Nothing above was written.
    expect($stale->fresh()->platform_fee_kobo)->toBeNull()
        ->and($stale->fresh()->net_kobo)->toBe(245000);

    $this->artisan('payments:backfill-processing-fees --apply')->assertSuccessful();

    $stale->refresh();
    $ownKeys->refresh();
    $awaiting->refresh();

    expect($stale->platform_fee_kobo)->toBe(6250)
        ->and($stale->net_kobo)->toBe(238750)
        // Untouched, because it was already right — and because this command
        // does not move money it was not asked to move.
        ->and($ownKeys->net_kobo)->toBe(98500)
        ->and($ownKeys->platform_fee_kobo)->toBeNull()
        ->and($awaiting->net_kobo)->toBeNull()
        ->and($awaiting->platform_fee_kobo)->toBeNull();

    // The columns it must never touch, on any row.
    expect($stale->amount)->toBe('2500.00')
        ->and($stale->fee_kobo)->toBe(5000)
        ->and($stale->currency)->toBe('NGN')
        ->and($stale->status)->toBe(TransactionStatus::CONFIRMED)
        ->and($stale->paid_at)->not->toBeNull();
});

/*
| Helpers used by the assertions above, kept at the bottom so the tests read as
| the contract rather than as plumbing.
*/

/**
 * How many lines on this entry sit on the 5500 mapping.
 */
function pf41ProcessingFeeLines(?int $businessId, string $idempotencyKey): int
{
    $entry = JournalEntry::query()
        ->where('business_id', $businessId)
        ->where('idempotency_key', $idempotencyKey)
        ->sole();

    $accountId = (int) LedgerMapping::query()
        ->where('business_id', $businessId)
        ->where('key', 'gateway_fees')
        ->value('ledger_account_id');

    return JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('ledger_account_id', $accountId)
        ->count();
}

function pf41ProcessingFeeDescription(?int $businessId, string $idempotencyKey): string
{
    $entry = JournalEntry::query()
        ->where('business_id', $businessId)
        ->where('idempotency_key', $idempotencyKey)
        ->sole();

    $accountId = (int) LedgerMapping::query()
        ->where('business_id', $businessId)
        ->where('key', 'gateway_fees')
        ->value('ledger_account_id');

    return (string) JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('ledger_account_id', $accountId)
        ->value('description');
}
