<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Payments\Connect\ConnectAccountEventService;
use App\Services\Payments\Custody\CustodyService;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| WS-45 — a collected sale that comes back.
|
| Storify collected the money, the business's wallet was credited with its share,
| and then the sale is undone: the business refunds the customer, or the
| customer's bank charges it back. Under split-at-source both of these were
| somebody else's problem — the money had settled to the business directly and
| the provider clawed it out of the business's own account. Under custody the
| money is Storify's, the customer is repaid from Storify's balance, and a
| business holding cash it is no longer owed is a debt nobody has written down.
|
| Three ways that goes wrong, and the tests are the answers to them:
|
| 1. **Moving one side and not the other.** The wallet says the debt is gone and
|    the journal still carries it as a liability, or the reverse. Every test here
|    reads both.
| 2. **Moving twice.** A refund retried after a provider timeout, a
|    `dispute.updated` redelivered — the provider repeats deliveries whenever
|    anything about an event changes, and a second movement is Storify paying a
|    business twice for one sale.
| 3. **Moving too early, or not at all.** A dispute that has been *opened* is a
|    question; only a lost one is an answer. And a chargeback on a platform charge
|    names no account we hold, so before this change it reached the webhook
|    endpoint and was dropped as "not a connected account" — money moving with
|    nothing recorded against it anywhere.
*/

function cu45Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function cu45Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * A business Storify collects for. The flag and the balances are written
 * directly: this suite is about what happens to a wallet, not how one is opened.
 *
 * @return array{0: Business, 1: Store}
 */
function cu45CustodyBusiness(int $pendingKobo = 0, int $availableKobo = 0): array
{
    [$owner, $business] = createBusinessOwner();

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
        'pending_balance' => $pendingKobo,
        'available_balance' => $availableKobo,
    ])->save();

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'cu45-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    return [$business->fresh(), $store];
}

/** The ₦2,500 sale the plan walks through: ₦50 provider fee, ₦62.50 commission. */
function cu45Sale(Business $business, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'reference' => 'TXN-'.strtoupper(Str::random(12)),
        'business_id' => $business->id,
        'amount' => 2500.00,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 5000,
        'platform_fee_kobo' => 6250,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ], $attributes));
}

/** @return array<string, int> */
function cu45Lines(?JournalEntry $entry): array
{
    if ($entry === null) {
        return [];
    }

    return $entry->lines()->get()->mapWithKeys(fn ($line) => [
        $line->account->code.($line->debit_kobo > 0 ? ':dr' : ':cr') => $line->debit_kobo > 0
            ? (int) $line->debit_kobo
            : (int) $line->credit_kobo,
    ])->all();
}

function cu45Entry(?int $businessId, string $key): ?JournalEntry
{
    return JournalEntry::query()
        ->where('business_id', $businessId)
        ->where('idempotency_key', $key)
        ->first();
}

/**
 * A delivery from Bachs about an account, signed the way the platform's connect
 * endpoint verifies one.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function cu45Signed(array $payload, string $secret = 'whsec_platform'): array
{
    $body = json_encode($payload);
    $timestamp = time();

    return [
        $body,
        ['HTTP_X_BACHS_SIGNATURE_V2' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)],
    ];
}

function cu45Post($test, string $body, array $headers): TestResponse
{
    return $test->call('POST', '/webhooks/connect/bachs', [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $headers), $body);
}

/**
 * A chargeback payload as Bachs sends it.
 *
 * @param  array<string, mixed>  $overrides
 */
function cu45Dispute(string $status, array $overrides = []): array
{
    return [
        'type' => 'dispute.updated',
        'account' => 'acct_cu45',
        'data' => array_merge([
            'id' => 'dp_cu45',
            'charge_id' => 'chg_cu45',
            'amount' => '2500.00',
            'currency' => 'NGN',
            'status' => $status,
            'reason' => 'product_not_received',
            'created_at' => '2026-10-11T09:00:00Z',
        ], $overrides),
    ];
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/* --------------------------------------------------- the wallet and the journal */

test('a refunded collection gives the money back and reverses the platform journal', function () {
    [$business] = cu45CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = cu45Sale($business);

    $custody->recordCollectedSale($transaction, 'bachs');

    expect($business->fresh()->pending_balance)->toBe(238750);

    $custody->unwindCollectedSale($transaction->fresh(), 'Refund '.$transaction->reference);

    expect($business->fresh()->pending_balance)->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0);

    // The original entry is reversed, line for line and account for account —
    // which is the point of reversing it rather than writing a second set of
    // lines by hand. A hand-written pair is how a fee line gets dropped from one
    // direction only, and the difference then sits on the platform's clearing
    // balance until somebody notices a business was paid too much.
    $original = cu45Entry(null, 'managed:collect:txn:'.$transaction->id);

    expect($original->status)->toBe(JournalEntry::STATUS_VOID)
        ->and(cu45Lines(cu45Entry(null, 'custody_return:txn:'.$transaction->id)))->toBe([
            '1030:cr' => 250000,
            '2300:dr' => 238750,
            '4020:dr' => 6250,
            '2210:dr' => 5000,
        ]);
});

test('a refund after promotion takes the money out of available', function () {
    [$business] = cu45CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = cu45Sale($business, ['paid_at' => now()->subDays(3)]);

    $custody->recordCollectedSale($transaction, 'bachs');
    $custody->promoteSettled();

    expect($business->fresh()->available_balance)->toBe(238750);

    $custody->unwindCollectedSale($transaction->fresh(), 'Refund '.$transaction->reference);

    // Which bucket it comes out of is read from the row rather than passed in:
    // a caller that had to know would be a caller that could get it wrong, and
    // the wrong bucket is a wallet that quietly keeps money it does not hold.
    expect($business->fresh()->available_balance)->toBe(0)
        ->and($business->fresh()->pending_balance)->toBe(0);
});

test('a refunded sale that was already paid out leaves the business owing Storify', function () {
    [$business] = cu45CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = cu45Sale($business, ['paid_at' => now()->subDays(3)]);

    $custody->recordCollectedSale($transaction, 'bachs');
    $custody->promoteSettled();
    $custody->releaseForPayout($business->fresh(), 238750);

    $custody->unwindCollectedSale($transaction->fresh(), 'Refund '.$transaction->reference);

    // The business has the cash and Storify repaid the customer out of its own
    // balance. Refusing to record the debt would leave it unrecorded rather than
    // recovered from the next sale, which is the worse of the two.
    expect($business->fresh()->available_balance)->toBe(-238750);
});

test('the same sale refunded twice is unwound once', function () {
    // A refund retried after a provider timeout, and a return-leg verify racing
    // the webhook, are both ordinary. The second one must find the claim taken
    // and move nothing.
    [$business] = cu45CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = cu45Sale($business);

    $custody->recordCollectedSale($transaction, 'bachs');

    expect($custody->unwindCollectedSale($transaction->fresh(), 'Refund one'))->toBeTrue()
        ->and($custody->unwindCollectedSale($transaction->fresh(), 'Refund two'))->toBeFalse();

    expect($business->fresh()->pending_balance)->toBe(0)
        // One reversal, keyed on the transaction — a second would have posted a
        // second one, which is the whole reason the claim exists.
        ->and(JournalEntry::query()
            ->whereNull('business_id')
            ->where('idempotency_key', 'custody_return:txn:'.$transaction->id)
            ->count())->toBe(1);
});

test('a refunded sale that was never credited is marked so the promote job cannot credit it later', function () {
    // The sale settled before the provider said what it charged, so the wallet
    // refused to guess and the catch-up pass is what would normally come back
    // for it. A refunded sale must be out of that pass's reach: the customer has
    // had the money back, and crediting the business afterwards would be Storify
    // inventing a debt to pay out.
    [$business] = cu45CustodyBusiness();
    $custody = app(CustodyService::class);

    $transaction = cu45Sale($business, ['fee_kobo' => null]);

    expect($custody->recordCollectedSale($transaction, 'bachs'))->toBeFalse()
        ->and($business->fresh()->pending_balance)->toBe(0);

    $custody->unwindCollectedSale($transaction->fresh(), 'Refund '.$transaction->reference);

    // The fee arrives late, and the catch-up pass runs.
    $transaction->fresh()->update(['fee_kobo' => 5000]);
    $custody->promoteSettled();

    expect($business->fresh()->pending_balance)->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0)
        ->and($transaction->fresh()->unwound_at)->not->toBeNull();
});

test('a sale the platform never collected is marked unwound and moves nothing', function () {
    // A charge with no wallet credit behind it — most often one whose provider
    // fee had not arrived when the refund came, so it was never credited. There
    // is no `managed:collect` entry to reverse and no balance to take back, and
    // the check has to come from the row rather than from the custody flag, which
    // may have been switched between the charge and the refund.
    [$business] = cu45CustodyBusiness(availableKobo: 500000);

    $transaction = cu45Sale($business, [
        'metadata' => ['payment_mode' => 'platform_managed'],
    ]);

    expect(app(CustodyService::class)->unwindCollectedSale($transaction, 'Refund'))->toBeTrue();

    expect($business->fresh()->available_balance)->toBe(500000)
        ->and(cu45Entry(null, 'custody_return:txn:'.$transaction->id))->toBeNull();
});

/* -------------------------------------------------------------- chargebacks */

test('a lost chargeback takes the business\'s share back out of its wallet', function () {
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    expect($business->fresh()->pending_balance)->toBe(238750);

    [$body, $headers] = cu45Signed(cu45Dispute('lost'));

    cu45Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute recorded');

    // The dispute is filed against Storify's merchant account and debits
    // Storify's balance. The business's share goes with it — the same movement a
    // refund makes, because on both books it is one fact.
    expect($business->fresh()->pending_balance)->toBe(0)
        ->and(cu45Lines(cu45Entry(null, 'custody_return:txn:'.$transaction->id)))->toBe([
            '1030:cr' => 250000,
            '2300:dr' => 238750,
            '4020:dr' => 6250,
            '2210:dr' => 5000,
        ]);
});

test('a chargeback that has only been opened moves nothing', function () {
    // `needs_response` is a question, not an answer. The provider may well have
    // already pulled the money out of our balance to hold it while it is asked —
    // a cash-flow fact for Storify, and not yet a debt for the business. Taking
    // it now and giving it back on `won` would be two movements for one outcome,
    // and the second is a credit to a business that may have been paid out
    // in between.
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    [$body, $headers] = cu45Signed(cu45Dispute('needs_response'));

    cu45Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute recorded');

    expect($business->fresh()->pending_balance)->toBe(238750)
        ->and($transaction->fresh()->unwound_at)->toBeNull();
});

test('a chargeback won leaves the money where it was', function () {
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    [$body, $headers] = cu45Signed(cu45Dispute('won'));

    cu45Post($this, $body, $headers)->assertOk();

    expect($business->fresh()->pending_balance)->toBe(238750);
});

test('a lost chargeback delivered twice moves the money once', function () {
    // Bachs re-sends `dispute.updated` whenever anything about the dispute
    // changes, and the status stays `lost` through most of them.
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    [$first, $headers] = cu45Signed(cu45Dispute('lost'));

    cu45Post($this, $first, $headers)->assertOk();

    // A second delivery, with its own event id so the endpoint's own dedupe does
    // not answer it — the claim on the transaction is what has to hold.
    [$second, $headers] = cu45Signed(cu45Dispute('lost', ['reason' => 'fraudulent']));

    cu45Post($this, $second, $headers)->assertOk();

    expect($business->fresh()->pending_balance)->toBe(0);
});

test('a chargeback on a platform charge naming no account is answered by the charge', function () {
    // Under custody the charge is Storify's own, so the delivery names either the
    // platform's account — which has no connection, because it is not a business
    // — or no account at all. Both used to fall through as "not ours", which for
    // a chargeback is the one delivery whose loss leaves money moving with
    // nothing recorded against it anywhere.
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    $payload = cu45Dispute('lost');
    unset($payload['account']);

    [$body, $headers] = cu45Signed($payload);

    cu45Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute recorded');

    expect($business->fresh()->pending_balance)->toBe(0);

    $this->assertDatabaseHas('activity_logs', [
        'business_id' => $business->id,
        'action' => 'payment_gateway.dispute_lost',
    ]);
});

test('a chargeback naming an account we hold no connection for is answered by the charge too', function () {
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    [$body, $headers] = cu45Signed(cu45Dispute('lost', ['charge_id' => 'chg_cu45']));

    cu45Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute recorded');

    expect($business->fresh()->pending_balance)->toBe(0);
});

test('a dispute about a charge we cannot place is recorded against nothing', function () {
    // The fall-through has to stay. The delivery names a charge with no
    // transaction behind it, so there is nothing to record and nothing to take
    // back. It is still owed an answer — 200, because the provider would retry a
    // 500 into the same dead end — and the service logs it as an error rather
    // than a warning: money has left Storify's balance and nobody is recorded as
    // owing it.
    cu45Platform();

    cu45CustodyBusiness();

    [$body, $headers] = cu45Signed(cu45Dispute('lost', ['charge_id' => 'chg_nobody']));

    cu45Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute not recorded');
});

test('the charge is found by the checkout id as well as the charge id', function () {
    // Providers name either id depending on the event, and Bachs' own settlement
    // deliveries use the checkout id. The lookup asks all of them: being wrong
    // here is Storify unable to recover money it has already paid back.
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chk_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    $payload = cu45Dispute('lost', ['charge_id' => 'chk_cu45']);
    unset($payload['account']);

    [$body, $headers] = cu45Signed($payload);

    cu45Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute recorded');

    expect($business->fresh()->pending_balance)->toBe(0);
});

test('a chargeback with no charge on it is recorded against nothing and moves nothing', function () {
    // Loudly logged, and nothing invented. Money has left the provider balance
    // and there is no transaction to hang the debt on; a guess would be worse
    // than an unrecorded debt somebody can go and find.
    cu45Platform();

    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_other']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    $payload = cu45Dispute('lost', ['charge_id' => 'chg_missing']);
    unset($payload['account']);

    [$body, $headers] = cu45Signed($payload);

    cu45Post($this, $body, $headers)->assertOk()->assertJsonPath('status', 'dispute not recorded');

    expect($business->fresh()->pending_balance)->toBe(238750)
        ->and($transaction->fresh()->unwound_at)->toBeNull();
});

/* ------------------------------------------- the charge is the only identifier */

test('a lost dispute is placed by its charge, and reaches the wallet with no account anywhere', function () {
    // Called with no account — and there is no connection it could have had, on
    // this business or any other. The charge is the only thing that identifies
    // the delivery, which is exactly the shape a chargeback on a Storify charge
    // arrives in: the account named in the payload is our own and identifies
    // nothing.
    cu45Platform();

    [$business] = cu45CustodyBusiness();
    $events = app(ConnectAccountEventService::class);

    expect($events->recordPlatformDispute(['id' => 'dp_cu45', 'charge_id' => 'chg_nobody', 'status' => 'lost'], 'dispute.created'))
        ->toBeFalse();

    $transaction = cu45Sale($business, ['gateway_reference' => 'chg_cu45']);

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    expect($events->recordPlatformDispute(['id' => 'dp_cu45', 'charge_id' => 'chg_cu45', 'status' => 'lost'], 'dispute.created'))
        ->toBeTrue()
        ->and($business->fresh()->pending_balance)->toBe(0)
        ->and($transaction->fresh()->unwound_at)->not->toBeNull();
});

/* ---------------------------------- the wallet against the account that records it */

/**
 * The store's own row out of an integrity report.
 *
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function cu45WalletRow(array $report, Store $store): array
{
    foreach ($report['wallet_rows'] as $row) {
        if ($row['store']->id === $store->id) {
            return $row;
        }
    }

    throw new RuntimeException('No wallet row for store '.$store->id.'.');
}

test('the wallet and the account that records it agree through the whole life of a sale', function () {
    [$business, $store] = cu45CustodyBusiness();
    $custody = app(CustodyService::class);
    $ledger = app(LedgerPostingService::class);
    $reports = app(LedgerReportService::class);

    // Paid three days ago, so promotion has something to promote.
    $transaction = cu45Sale($business, ['paid_at' => now()->subDays(3)]);

    // Both halves of one sale, which in production are posted by two different
    // paths — the platform's collection by the settlement, the business's books
    // by the payment webhook. The reconciliation is only meaningful with both in
    // place, and with only one it is supposed to disagree, which is the next
    // test.
    $custody->recordCollectedSale($transaction, 'bachs');
    $ledger->postManagedPaymentReceived($transaction->fresh(), 5000, 6250, null, true);

    expect($reports->integrity($business->id)['custody'])->toMatchArray([
        'pending' => 238750,
        'available' => 0,
        'wallet' => 238750,
        'ledger' => 238750,
        'difference' => 0,
    ]);

    // Promoted: the money changes bucket, and `1040` is not consulted about
    // buckets, so the invariant has to survive the move.
    $custody->promoteSettled();

    expect($reports->integrity($business->id)['custody'])->toMatchArray([
        'pending' => 0,
        'available' => 238750,
        'ledger' => 238750,
        'difference' => 0,
    ]);

    // Paid out. The wallet is emptied and `1040` is debited by the same amount —
    // two books, one movement, and both have to land at zero or one of them is
    // still claiming Storify holds money it has already sent.
    $custody->releaseForPayout($business->fresh(), 238750);
    $ledger->postGatewayWithdrawal($business->id, $store->id, 238750, 'PO-'.$business->id.'-cu45', null, true);
    $ledger->postCustodyPayoutOnPlatformBooks(238750, 'PO-'.$business->id.'-cu45', $store->id);

    expect($reports->integrity($business->id)['custody'])->toMatchArray([
        'wallet' => 0,
        'ledger' => 0,
        'difference' => 0,
    ]);

    // And then refunded, which the business can only do because the customer
    // asked for it after the cash had already gone. Both sides go negative by the
    // same amount: the business owes Storify, and its own books say so.
    $custody->unwindCollectedSale($transaction->fresh(), 'Refund '.$transaction->reference);
    $ledger->postRefund($transaction->fresh());

    expect($reports->integrity($business->id)['custody'])->toMatchArray([
        'wallet' => -238750,
        'ledger' => -238750,
        'difference' => 0,
    ]);
});

test('the reconciliation reports a difference when only one side of a sale moved', function () {
    [$business] = cu45CustodyBusiness();

    $transaction = cu45Sale($business, ['paid_at' => now()->subDays(3)]);

    // The wallet credited and the business's books never posted: the state a
    // dropped posting leaves behind. Without this the check could be vacuous —
    // a reconciliation that reports zero because it is reading the same number
    // twice proves nothing about the day the two numbers part company.
    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');

    expect(app(LedgerReportService::class)->integrity($business->id)['custody'])->toMatchArray([
        'wallet' => 238750,
        'ledger' => 0,
        'difference' => 238750,
    ]);
});

test('a managed sale is not counted against the store wallet', function () {
    [$business, $store] = cu45CustodyBusiness();

    // A sale taken before the business was flipped, so the money sat in the
    // business's own account at the provider and `stores.balance` was never
    // credited — but `1030` moved on the books, because that is where a managed
    // settlement clears. Comparing the one against the other reported a
    // difference on every managed sale the platform ever processed.
    $transaction = cu45Sale($business);

    app(LedgerPostingService::class)->postManagedPaymentReceived($transaction, 5000, 6250, null, false);

    expect(cu45WalletRow(app(LedgerReportService::class)->integrity($business->id), $store))
        ->toMatchArray(['wallet' => 0, 'ledger' => 0, 'difference' => 0]);
});
