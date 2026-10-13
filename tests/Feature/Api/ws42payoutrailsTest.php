<?php

use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Payouts\BachsPayoutRail;
use App\Services\Payments\Payouts\PaystackPayoutRail;
use App\Services\Payments\Payouts\TransferOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| WS-42 — the two rails that take money out of Storify's balance.
|
| These are the calls that make a processor switch invisible, and the thing being
| asserted is mostly *absence*. The old model addressed a business's connected
| account, so the switchover problem was that the account had to exist before a
| sale could happen — and every request carried its id. A rail that quietly
| inherited an account header would still work, still get a 200, and still pay out
| of the wrong balance; nothing in production would report it. So the tests below
| assert that the platform's own organisation is the one being asked.
|
| The larger half is the outcome taxonomy, and it is the half that costs money.
| There are three answers, not two: the provider took it, the provider refused it,
| or we do not know. Only the second means the money definitely did not move, and
| therefore only the second may release a wallet reservation. A rail that reported
| a timeout as a failure would have the caller release the reservation and send a
| second, genuine payout — to a business that had already been paid, with two
| perfectly plausible rows and no alert anywhere. Every test from "unreachable"
| down exists to make that specific mistake impossible.
*/

function pr42Bachs(): GatewayCredentials
{
    return GatewayCredentials::make('bachs', [
        'api_key' => 'sk_sandbox_platform1234',
        'webhook_secret' => 'whsec_platform',
    ], ['api_key', 'webhook_secret']);
}

function pr42Paystack(): GatewayCredentials
{
    return GatewayCredentials::make('paystack', [
        'secret_key' => 'sk_test_platform1234',
        'public_key' => 'pk_test_platform1234',
    ], ['secret_key']);
}

test('the Bachs rail pays the platform and never names a business account', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'pd_new', 'is_usable' => true])]);

    $ref = (new BachsPayoutRail)->ensureDestination(pr42Bachs(), '0123456789', '058', 'Ada Trading Ltd');

    expect($ref->ok())->toBeTrue()
        ->and($ref->payable())->toBeTrue()
        ->and($ref->id)->toBe('pd_new');

    Http::assertSent(function (Request $request) {
        // The header is what makes a Bachs call one made *as* a business. Its
        // absence is the entire difference between this rail and the one it
        // replaces, and a 200 either way — so it is asserted, not assumed.
        expect($request->hasHeader('X-Account-Id'))->toBeFalse();

        return $request->hasHeader('Authorization')
            && str_ends_with($request->url(), '/payouts/destinations')
            && $request->data()['account_number'] === '0123456789'
            && $request->data()['bank_code'] === '058'
            && $request->data()['type'] === 'bank_account';
    });
});

test('a Bachs destination the provider marks unusable is not treated as payable', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'id' => 'pd_odd',
        'is_usable' => false,
    ])]);

    $ref = (new BachsPayoutRail)->ensureDestination(pr42Bachs(), '0123456789', '058', 'Ada Trading Ltd');

    // Registered, so there is an id to store — but the provider has already said
    // money would not go to it, and a green light here would produce a payout row
    // that fails at the transfer instead.
    expect($ref->ok())->toBeTrue()
        ->and($ref->payable())->toBeFalse();
});

test('a Bachs destination without an id is a refusal, not a registered destination', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['is_usable' => true])]);

    $ref = (new BachsPayoutRail)->ensureDestination(pr42Bachs(), '0123456789', '058', 'Ada Trading Ltd');

    // An id-less success would otherwise be stored as a destination that can
    // never be transferred to.
    expect($ref->ok())->toBeFalse()
        ->and($ref->payable())->toBeFalse()
        ->and($ref->message)->toContain('did not return its id');
});

test('the Bachs rail sends naira as a decimal string keyed on our reference', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'pay_1', 'status' => 'pending'])]);

    $outcome = (new BachsPayoutRail)->send(pr42Bachs(), 'pd_new', 238750, 'po-7-abcdef');

    expect($outcome->isAccepted())->toBeTrue()
        ->and($outcome->transferId)->toBe('pay_1')
        ->and($outcome->providerStatus)->toBe('pending');

    Http::assertSent(function (Request $request) {
        expect($request->hasHeader('X-Account-Id'))->toBeFalse();

        return str_ends_with($request->url(), '/payouts')
            // Bachs does not take kobo, and sending 238750 would pay a business
            // two thousand times what it is owed.
            && $request->data()['amount'] === '2387.50'
            && $request->data()['destination'] === 'pd_new'
            && $request->header('Idempotency-Key')[0] === 'po-7-abcdef';
    });
});

test('a Bachs payout already in flight is accepted, not refused', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'code' => 'IDEMPOTENCY_IN_PROGRESS',
        'message' => 'This payout is already being processed.',
    ], 409)]);

    $outcome = (new BachsPayoutRail)->send(pr42Bachs(), 'pd_new', 238750, 'po-7-abcdef');

    // Reporting this as a refusal is how a business gets paid twice: the caller
    // releases the reservation and the next run sends a second, genuine payout.
    // The provider already has it; it just has not told us the id yet.
    expect($outcome->isRefused())->toBeFalse()
        ->and($outcome->isAccepted())->toBeTrue()
        ->and($outcome->providerStatus)->toBe('processing')
        ->and($outcome->isUnresolved())->toBeTrue();
});

test('a Bachs refusal carries the provider code the remedy depends on', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'code' => 'INSUFFICIENT_BALANCE',
        'message' => 'Your balance is too low for this payout.',
    ], 422)]);

    $outcome = (new BachsPayoutRail)->send(pr42Bachs(), 'pd_new', 238750, 'po-7-abcdef');

    expect($outcome->isRefused())->toBeTrue()
        ->and($outcome->failureCode)->toBe('INSUFFICIENT_BALANCE')
        ->and($outcome->isUnresolved())->toBeFalse()
        ->and($outcome->message)->toContain('balance');
});

test('an unreachable Bachs leaves the payout indeterminate, not refused', function () {
    Http::fake(['sandbox-api.bachs.io/*' => fn () => throw new RuntimeException('connection reset')]);

    $outcome = (new BachsPayoutRail)->send(pr42Bachs(), 'pd_new', 238750, 'po-7-abcdef');

    // The single most expensive mistake available here. A reset after the request
    // was written is indistinguishable from one before, and Bachs may have created
    // the payout. "Not refused" is what stops the caller releasing the reservation
    // and paying the same debt again.
    expect($outcome->isIndeterminate())->toBeTrue()
        ->and($outcome->isRefused())->toBeFalse()
        ->and($outcome->isUnresolved())->toBeTrue();
});

test('a Bachs server error leaves the payout indeterminate, not refused', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Internal error'], 503)]);

    $outcome = (new BachsPayoutRail)->send(pr42Bachs(), 'pd_new', 238750, 'po-7-abcdef');

    // A 5xx says the provider is broken, not that it declined. Only a 4xx is an
    // answer about this payout.
    expect($outcome->isIndeterminate())->toBeTrue()
        ->and($outcome->isRefused())->toBeFalse();
});

test('the Bachs rail refuses to guess about a payout it has no id for', function () {
    Http::fake();

    $outcome = (new BachsPayoutRail)->lookUp(pr42Bachs(), 'po-7-abcdef');

    // Bachs has no lookup by our reference, so a send that timed out before
    // returning an id cannot be resolved here. The answer is "I cannot tell you",
    // and it must not be a refusal — that would release the reservation on the
    // strength of a missing id. The `payout.paid` webhook carries the reference
    // we sent, and that is what resolves this case.
    expect($outcome)->toBeNull();

    Http::assertNothingSent();
});

test('the Bachs rail reads a payout back by its provider id', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'pay_1', 'status' => 'completed'])]);

    $outcome = (new BachsPayoutRail)->lookUp(pr42Bachs(), 'po-7-abcdef', 'pay_1');

    expect($outcome->isAccepted())->toBeTrue()
        ->and($outcome->providerStatus)->toBe('completed');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payouts/pay_1')
        && $request->method() === 'GET');
});

test('a Bachs payout the provider reports as failed reads back as refused', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'id' => 'pay_1',
        'status' => 'failed',
        'failure_code' => 'ACCOUNT_CLOSED',
        'failure_reason' => 'The destination account is closed.',
    ])]);

    $outcome = (new BachsPayoutRail)->lookUp(pr42Bachs(), 'po-7-abcdef', 'pay_1');

    // The one case a lookup is allowed to release money on: the provider has
    // explicitly said the payout failed.
    expect($outcome->isRefused())->toBeTrue()
        ->and($outcome->failureCode)->toBe('ACCOUNT_CLOSED');
});

test('the Paystack rail registers a recipient from the same stored bank details', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => ['recipient_code' => 'RCP_abc123', 'active' => true],
    ])]);

    $ref = (new PaystackPayoutRail)->ensureDestination(pr42Paystack(), '0123456789', '058', 'Ada Trading Ltd');

    expect($ref->id)->toBe('RCP_abc123')
        ->and($ref->payable())->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transferrecipient')
        && $request->data()['type'] === 'nuban'
        // The same code the Bachs rail took. This is why a switch needs nothing
        // from the business: the bank it named once is a destination on both.
        && $request->data()['bank_code'] === '058'
        && $request->data()['account_number'] === '0123456789');
});

test('a Paystack 200 with status false is a refusal, not a registered recipient', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => false,
        'message' => 'Account number is invalid',
    ])]);

    $ref = (new PaystackPayoutRail)->ensureDestination(pr42Paystack(), '0000000000', '058', 'Ada Trading Ltd');

    // Paystack reports business errors as successful HTTP calls. Trusting the
    // status code would store a destination that does not exist.
    expect($ref->ok())->toBeFalse()
        ->and($ref->message)->toBe('Account number is invalid');
});

test('the Paystack rail transfers the balance in integer kobo', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => ['transfer_code' => 'TRF_xyz789', 'status' => 'pending'],
    ])]);

    $outcome = (new PaystackPayoutRail)->send(pr42Paystack(), 'RCP_abc123', 238750, 'po-7-abcdef');

    expect($outcome->isAccepted())->toBeTrue()
        ->and($outcome->transferId)->toBe('TRF_xyz789')
        ->and($outcome->providerStatus)->toBe('pending');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfer')
        && $request->data()['source'] === 'balance'
        // Kobo, not naira: the opposite convention to Bachs, one rail apart.
        && $request->data()['amount'] === 238750
        && $request->data()['recipient'] === 'RCP_abc123'
        && $request->data()['reference'] === 'po-7-abcdef');
});

test('a Paystack transfer refused for a spent reference is accepted, not refused', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => false,
        'message' => 'Please provide a unique reference. Reference already exists on a transfer',
    ])]);

    $outcome = (new PaystackPayoutRail)->send(pr42Paystack(), 'RCP_abc123', 238750, 'po-7-abcdef');

    // Paystack has no idempotency header — the reference is the key, and it
    // enforces it by refusing the second attempt. The transfer exists.
    expect($outcome->isAccepted())->toBeTrue()
        ->and($outcome->isRefused())->toBeFalse();
});

test('a Paystack transfer refusal carries its own code', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => false,
        'message' => 'Insufficient balance',
        'data' => ['code' => 'insufficient_balance'],
    ])]);

    $outcome = (new PaystackPayoutRail)->send(pr42Paystack(), 'RCP_abc123', 238750, 'po-7-abcdef');

    expect($outcome->isRefused())->toBeTrue()
        ->and($outcome->failureCode)->toBe('insufficient_balance');
});

test('an unreachable Paystack leaves the payout indeterminate, not refused', function () {
    Http::fake(['api.paystack.co/*' => fn () => throw new RuntimeException('read timeout')]);

    $outcome = (new PaystackPayoutRail)->send(pr42Paystack(), 'RCP_abc123', 238750, 'po-7-abcdef');

    expect($outcome->isIndeterminate())->toBeTrue()
        ->and($outcome->isRefused())->toBeFalse();
});

test('the Paystack rail can settle an unknown payout by our own reference', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => ['transfer_code' => 'TRF_xyz789', 'status' => 'success'],
    ])]);

    $outcome = (new PaystackPayoutRail)->lookUp(pr42Paystack(), 'po-7-abcdef');

    expect($outcome->isAccepted())->toBeTrue()
        ->and($outcome->providerStatus)->toBe('success')
        ->and($outcome->transferId)->toBe('TRF_xyz789');

    // This is the capability Bachs lacks, and the reason a timed-out Paystack send
    // does not need an operator to untangle it.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfer/verify/po-7-abcdef'));
});

test('a Paystack transfer the provider reports as failed reads back as refused', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => ['transfer_code' => 'TRF_xyz789', 'status' => 'failed', 'reason' => 'Transfer failed'],
    ])]);

    $outcome = (new PaystackPayoutRail)->lookUp(pr42Paystack(), 'po-7-abcdef');

    expect($outcome->isRefused())->toBeTrue();
});

test('a Paystack lookup that finds nothing refuses to guess', function () {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Not found'], 404)]);

    $outcome = (new PaystackPayoutRail)->lookUp(pr42Paystack(), 'po-7-abcdef');

    // A reference Paystack has never seen most likely means no transfer was made —
    // but it can equally mean the lookup ran inside the window before a
    // just-created transfer became readable, and the two are indistinguishable.
    // Reporting "not found" as a refusal would release the reservation on the
    // strength of a race, so this says "I cannot tell you".
    expect($outcome)->toBeNull();
});

test('a reversal reads back as a payout that happened, not one that never did', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => ['transfer_code' => 'TRF_xyz789', 'status' => 'reversed'],
    ])]);

    $outcome = (new PaystackPayoutRail)->lookUp(pr42Paystack(), 'po-7-abcdef');

    // `reversed` is money that landed and came back. Both `refused` and a plain
    // "in flight" would misreport it, so the provider's own word is carried
    // through for the caller to map onto its own reversed state.
    expect($outcome->isAccepted())->toBeTrue()
        ->and($outcome->isRefused())->toBeFalse()
        ->and($outcome->providerStatus)->toBe('reversed');
});

test('both rails answer to the registry code they serve', function () {
    // The sweep picks a rail from the payout row's `provider` column, so these
    // strings are the join between the table and the code.
    expect((new BachsPayoutRail)->code())->toBe('bachs')
        ->and((new PaystackPayoutRail)->code())->toBe('paystack');
});

test('only a refusal permits money to be released', function () {
    // The invariant the whole taxonomy exists for, asserted directly against the
    // outcome object rather than through a rail: exactly one of the three
    // outcomes may unlock a wallet reservation.
    $accepted = TransferOutcome::accepted('pay_1', 'pending');
    $refused = TransferOutcome::refused('No.');
    $unknown = TransferOutcome::indeterminate('No answer.');

    expect($accepted->isUnresolved())->toBeTrue()
        ->and($unknown->isUnresolved())->toBeTrue()
        ->and($refused->isUnresolved())->toBeFalse()
        ->and($accepted->isRefused())->toBeFalse()
        ->and($unknown->isRefused())->toBeFalse()
        ->and($refused->isRefused())->toBeTrue();
});

/*
| Reading what the provider is actually holding.
|
| This is the number the promote run is gated on, so the failure mode to guard
| against is not "it returned the wrong figure" — it is "it returned a figure when
| it had no business to". Every unreadable answer here must be `null`, because a
| `0` would be read as the provider saying "you are broke" and would freeze the
| platform's payouts until somebody noticed. The three ways to be unreadable are
| tested separately because they are three different code paths: a successful
| response with nothing usable in it, a refusal, and a connection that never
| happened.
*/

test('the Bachs rail reads the platform balance without naming an account', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'balances' => [
            ['currency' => 'USD', 'available_balance' => '900.00'],
            ['currency' => 'NGN', 'available_balance' => '1250000.50', 'pending_balance' => '40000.00'],
        ],
    ])]);

    $kobo = (new BachsPayoutRail)->availableBalance(pr42Bachs());

    // Naira, at the currency's precision — not kobo. Reading `available_balance`
    // as kobo would divide every payout headroom by a hundred, and the promote run
    // would refuse to mature money the provider is holding.
    expect($kobo)->toBe(125000050);

    Http::assertSent(function (Request $request) {
        // The same absence the destination call is asserted for, and it matters
        // more here: asked as a business, this endpoint answers with that
        // business's account balance, and the gate would then be measuring the
        // wrong pot of money.
        expect($request->hasHeader('X-Account-Id'))->toBeFalse();

        return str_ends_with($request->url(), '/balances') && $request->method() === 'GET';
    });
});

test('a Bachs response with no naira entry is unreadable rather than empty', function () {
    // The provider answered — it just answered about another currency. Zero would
    // be a claim it never made; the promote run treats null as "ask the clock
    // instead" and keeps promoting on the fallback window.
    Http::fake(['sandbox-api.bachs.io/*' => Http::response([
        'balances' => [['currency' => 'USD', 'available_balance' => '900.00']],
    ])]);

    expect((new BachsPayoutRail)->availableBalance(pr42Bachs()))->toBeNull();
});

test('a Bachs balance the provider refuses is unreadable rather than empty', function () {
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    expect((new BachsPayoutRail)->availableBalance(pr42Bachs()))->toBeNull();
});

test('a Bachs balance that cannot be reached is unreadable rather than empty', function () {
    // A DNS blip must not be reported as a platform-wide zero balance, or the
    // next promote run matures nothing and every business's payout stalls behind
    // a provider that is up again a second later.
    Http::fake(['sandbox-api.bachs.io/*' => fn () => throw new ConnectionException('Could not resolve host')]);

    expect((new BachsPayoutRail)->availableBalance(pr42Bachs()))->toBeNull();
});

test('the Paystack rail reads balance as kobo, not naira', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => [
            ['currency' => 'USD', 'balance' => 90000],
            ['currency' => 'NGN', 'balance' => 250000000],
        ],
    ])]);

    // Integer kobo, the same unit the transfer amount is sent in and the same one
    // the wallet holds. Multiplying by a hundred here would gate the run on a
    // balance a hundred times too large, which is the one direction of error that
    // lets money leave against nothing.
    expect((new PaystackPayoutRail)->availableBalance(pr42Paystack()))->toBe(250000000);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/balance'));
});

test('a Paystack balance the provider denies is unreadable rather than empty', function () {
    // Paystack reports business-level errors as HTTP 200 with `status: false`, so
    // the success check alone would have read this as a real answer — and a body
    // with no `data` would have been read as zero.
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => false,
        'message' => 'Invalid key',
    ])]);

    expect((new PaystackPayoutRail)->availableBalance(pr42Paystack()))->toBeNull();
});

test('a Paystack balance the provider lists in another currency only is unreadable', function () {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => [['currency' => 'USD', 'balance' => 90000]],
    ])]);

    expect((new PaystackPayoutRail)->availableBalance(pr42Paystack()))->toBeNull();
});
