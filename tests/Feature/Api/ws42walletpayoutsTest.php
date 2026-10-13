<?php

use App\Models\Business;
use App\Models\Payout;
use Illuminate\Database\QueryException;

/*
| WS-42 — what Storify owes a business, and how it leaves.
|
| Collect-and-pay-out puts two questions in our hands that a provider used to
| answer. The first is *how much do we owe this business*, which is now a wallet
| rather than a provider balance. The second is *is it safe to hand over yet*,
| which is why the wallet has two buckets: a confirmed sale is owed immediately,
| but the provider has not necessarily settled it to us, so the money sits in
| `pending_balance` until it has and only `available_balance` is ever swept.
|
| Getting that wrong in either direction is expensive. Paying out of pending
| means Storify advances money it does not have, and several businesses paying
| out before settlement overdraw the platform's own provider balance. Treating
| the whole wallet as payable is the same bug with a shorter fuse.
|
| The other half is the payout row. It is reserved — written, with a unique
| reference — *before* the provider is told anything, because the key that used
| to identify a payout was a hash of the account, destination, amount and date.
| That was only safe while the provider balance emptied itself. A wallet does not,
| so two genuine payouts of the same amount on the same day are ordinary, and
| that key would have quietly dropped the second one.
|
| What is asserted here is therefore narrow and load-bearing: the buckets stay
| separate, promotion moves money without creating or destroying it, a payout
| cannot overdraw but a refund can, and two payouts are two payouts.
*/

function wp42Reserve(Business $business, int $kobo = 238750, ?int $storeId = null): Payout
{
    return Payout::reserve(
        businessId: $business->id,
        storeId: $storeId,
        provider: 'bachs',
        accountNumber: '0123456789',
        bankCode: '033',
        accountName: 'Amina Okafor',
        amountKobo: $kobo,
    );
}

test('a new business wallet is empty in both buckets', function () {
    [, $business] = createBusinessOwner();

    expect($business->walletBalance())->toBe(0)
        ->and($business->payoutableBalance())->toBe(0)
        ->and($business->pending_balance)->toBe(0)
        ->and($business->available_balance)->toBe(0);
});

test('a collected sale is owed immediately but is not yet payable', function () {
    [, $business] = createBusinessOwner();

    $business->creditPending(238750);

    // Owed: ₦2,387.50 of the customer's ₦2,500 is the business's.
    expect($business->fresh()->walletBalance())->toBe(238750);

    // Payable: nothing, because the provider has not settled it to us yet.
    // This is the whole reason the buckets exist — a sweep that read the total
    // would hand over money Storify is still waiting on.
    expect($business->fresh()->payoutableBalance())->toBe(0);
});

test('settlement moves money from pending to available without changing the total', function () {
    [, $business] = createBusinessOwner();

    $business->creditPending(238750);
    $business->promoteToAvailable(238750);

    $fresh = $business->fresh();

    expect($fresh->pending_balance)->toBe(0)
        ->and($fresh->available_balance)->toBe(238750)
        ->and($fresh->payoutableBalance())->toBe(238750)
        // The total is unchanged: promoting reclassifies, it does not create.
        ->and($fresh->walletBalance())->toBe(238750)
        ->and($fresh->balance_updated_at)->not->toBeNull();
});

test('promotion cannot exceed what is pending', function () {
    [, $business] = createBusinessOwner();

    $business->creditPending(100000);

    // A second promote of the same sale is a bug, and one that would mint money
    // out of nothing. It fails loudly rather than quietly absorbing.
    expect(fn () => $business->promoteToAvailable(150000))
        ->toThrow(Exception::class, 'Cannot promote more than is pending');

    expect($business->fresh()->pending_balance)->toBe(100000)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a payout cannot overdraw available money', function () {
    [, $business] = createBusinessOwner();

    // Pending is deliberately not spendable: there is ₦2,387.50 owed and still
    // nothing that can be paid.
    $business->creditPending(238750);

    expect(fn () => $business->debitAvailable(238750))
        ->toThrow(Exception::class, 'Insufficient available balance');

    $business->promoteToAvailable(238750);
    $business->debitAvailable(238750);

    expect($business->fresh()->available_balance)->toBe(0)
        ->and($business->fresh()->payoutableBalance())->toBe(0);
});

test('a refund after payout may leave the business owing Storify', function () {
    [, $business] = createBusinessOwner();

    $business->creditPending(238750);
    $business->promoteToAvailable(238750);

    // Paid out, and then the customer is refunded. The money is gone from the
    // business's bank, so Storify has refunded from its own balance and the
    // business owes it back. This has to be representable — refusing would leave
    // the debt unrecorded rather than recovered from the next sale.
    $business->debitAvailable(238750);
    $business->debitAvailable(50000, allowNegative: true);

    expect($business->fresh()->available_balance)->toBe(-50000)
        ->and($business->fresh()->walletBalance())->toBe(-50000);
});

test('a pending debit cannot overdraw what has not settled', function () {
    [, $business] = createBusinessOwner();

    $business->creditPending(100000);

    expect(fn () => $business->debitPending(150000))
        ->toThrow(Exception::class, 'Insufficient pending balance');

    expect($business->fresh()->pending_balance)->toBe(100000);
});

test('a reserved payout records where the money is going', function () {
    [, $business] = createBusinessOwner();

    $payout = wp42Reserve($business);

    expect($payout->status)->toBe(Payout::STATUS_PENDING)
        ->and($payout->reference)->toStartWith('po-'.$business->id.'-')
        ->and($payout->amount_kobo)->toBe(238750)
        ->and($payout->currency)->toBe('NGN')
        // Snapshotted onto the row, not read through the business: a payout that
        // has already left must still say where it went after the bank changes.
        ->and($payout->account_number)->toBe('0123456789')
        ->and($payout->bank_code)->toBe('033')
        ->and($payout->account_name)->toBe('Amina Okafor')
        // Reserved, not sent. Nothing has gone anywhere yet.
        ->and($payout->requested_at)->not->toBeNull()
        ->and($payout->sent_at)->toBeNull()
        ->and($payout->provider_payout_id)->toBeNull();
});

test('a payout asked for by the sweep has no user behind it', function () {
    [$owner, $business] = createBusinessOwner();

    $automatic = wp42Reserve($business);
    $manual = Payout::reserve(
        businessId: $business->id,
        storeId: null,
        provider: 'paystack',
        accountNumber: '0123456789',
        bankCode: '058',
        accountName: 'Amina Okafor',
        amountKobo: 100000,
        requestedBy: $owner->id,
    );

    // The only way to tell an automated payout from someone pressing a button
    // when reviewing it afterwards.
    expect($automatic->requested_by)->toBeNull()
        ->and($manual->requested_by)->toBe($owner->id);
});

test('a payout reference is one Paystack will accept', function () {
    [, $business] = createBusinessOwner();

    $reference = wp42Reserve($business)->reference;

    // Not a style preference. Paystack rejects a transfer whose reference is not
    // lowercase — "a unique identifier (in lowercase)... only -,_ and alphanumeric
    // characters" — and it rejects it as a business error, so an uppercase
    // reference would stop every Paystack payout dead while the row, the log and
    // the console all looked perfectly ordinary. Bachs is indifferent, so the
    // stricter provider sets the format for both rails.
    expect($reference)->toBe(strtolower($reference))
        ->and($reference)->toMatch('/^[a-z0-9_-]+$/')
        ->and(strlen($reference))->toBeGreaterThanOrEqual(16)
        ->and(strlen($reference))->toBeLessThanOrEqual(50);
});

test('two payouts of the same amount on the same day are two payouts', function () {
    [, $business] = createBusinessOwner();

    $first = wp42Reserve($business, 238750);
    $second = wp42Reserve($business, 238750);

    // The regression this whole table exists for. The key it replaced hashed the
    // account, destination, amount and date, so these two would have collided and
    // the second would have been silently dropped.
    expect($first->reference)->not->toBe($second->reference)
        ->and(Payout::query()->where('business_id', $business->id)->count())->toBe(2);
});

test('the database refuses a duplicate payout reference', function () {
    [, $business] = createBusinessOwner();

    $payout = wp42Reserve($business);

    // Uniqueness is enforced by the index rather than by a read-then-write, so
    // two workers claiming the same reference cannot both succeed.
    expect(fn () => Payout::query()->create([
        'business_id' => $business->id,
        'provider' => 'bachs',
        'account_number' => '0123456789',
        'amount_kobo' => 100000,
        'status' => Payout::STATUS_PENDING,
        'reference' => $payout->reference,
    ]))->toThrow(QueryException::class);
});
