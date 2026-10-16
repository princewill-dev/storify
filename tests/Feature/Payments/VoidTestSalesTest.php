<?php

use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Payout;
use App\Models\PayoutAllocation;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyService;
use Illuminate\Support\Str;

/*
| `custody:void-test-sales` — taking a test platform's balances off the books.
|
| The command exists because a wallet cannot forget. A collected sale is the
| business's money until the customer is given it back, so the only way to stop
| owing it is to leave the mark a refund leaves — which is why this is an
| operator's one-time tool and not a feature.
|
| What is pinned here is what makes it safe to point at a database:
|
| - **The two movements cancel.** Closing an open payout credits the wallet; the
|   void debits it again. A run that did one and not the other would either pay
|   the business or invent a debt, and both look like success in the output.
| - **Money that left is not taken back.** A sale a payout paid is in a bank
|   account, and debiting the wallet for it leaves the business owing Storify for
|   money it received. Same for one a payout is still carrying.
| - **A sale that was never credited is still marked.** It moves no balance, so it
|   is the case easiest to skip — and the one where skipping means the catch-up
|   pass credits it the next time it runs, leaving the platform owing money again
|   minutes after it was cleared.
*/

/** A custody business, with no money on it yet. */
function vtsBusiness(): Business
{
    [, $business] = createBusinessOwner();

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
    ])->save();

    return $business->refresh();
}

/**
 * The ₦2,500 managed sale these tests walk: a ₦62.50 commission, so ₦2,437.50 net.
 *
 * The provider's fee is Storify's own cost on a row like this, which is what the
 * bearer says — so the business's share does not wait on a fee report, and the net
 * is known the moment the sale is.
 */
function vtsSale(Business $business, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'reference' => 'TXN-'.strtoupper(Str::random(12)),
        'business_id' => $business->id,
        'amount' => 2500.00,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'platform_fee_kobo' => 6250,
        'provider_fee_bearer' => ProviderFeeBearer::PLATFORM,
        'metadata' => ['payment_mode' => 'platform_managed'],
    ], $attributes));
}

/** What the business is owed for one: ₦2,500 less our ₦62.50. */
function vtsNet(): int
{
    return 243750;
}

/** A sale the wallet has credited — the state every case below starts from. */
function vtsCollected(Business $business, array $attributes = []): Transaction
{
    $sale = vtsSale($business, $attributes);

    // Through the wallet's own writer rather than by hand: `settle_at` and the
    // credit are one fact, and a fixture that split them would be testing a row no
    // code path can produce.
    expect(app(CustodyService::class)->recordCollectedSale($sale, 'bachs'))->toBeTrue();

    return $sale->refresh();
}

/** A credited sale whose money has matured into the spendable balance. */
function vtsPromoted(Business $business): Transaction
{
    $sale = vtsCollected($business);

    // Bachs' window is nothing, so a sale is due the moment it is collected.
    app(CustodyService::class)->promoteSettled();

    return $sale->refresh();
}

/**
 * A payout carrying this sale — the row, the claim, and the wallet movement.
 *
 * `reserve()` takes the amount off the wallet and `PayoutAllocationService` writes
 * the join; this writes both halves directly because driving the send would mean
 * faking a provider to prove something about a command that never calls one.
 */
function vtsPayout(Business $business, Transaction $sale, string $status = Payout::STATUS_PENDING): Payout
{
    $payout = Payout::reserve(
        businessId: $business->id,
        storeId: null,
        provider: 'bachs',
        accountNumber: '0123456789',
        bankCode: '033',
        accountName: 'Amina Okafor',
        amountKobo: vtsNet(),
    );

    PayoutAllocation::create([
        'payout_id' => $payout->id,
        'transaction_id' => $sale->id,
        'business_id' => $business->id,
        'store_id' => null,
        'amount_kobo' => vtsNet(),
        'currency' => 'NGN',
        'status' => PayoutAllocation::STATUS_RESERVED,
    ]);

    $business->debitAvailable(vtsNet());

    if ($status !== Payout::STATUS_PENDING) {
        $payout->forceFill(['status' => $status])->save();
    }

    return $payout->refresh();
}

/**
 * Run the command the way an operator would, minus the prompt and the quiet window.
 *
 * The window is disabled because every fixture here is seconds old and would
 * otherwise be inside it — the window itself is covered by the close-out tests.
 */
function vtsRun($test, array $options = [])
{
    return $test->artisan('custody:void-test-sales', array_merge([
        '--force' => true,
        '--older-than' => 0,
    ], $options));
}

test('a sale whose money is spendable is voided, and the wallet stops owing it', function () {
    $business = vtsBusiness();
    $sale = vtsPromoted($business);

    expect($business->fresh()->available_balance)->toBe(vtsNet());

    vtsRun($this)
        ->expectsOutputToContain('Voided 1 sale(s).')
        ->expectsOutputToContain('took back from available')
        ->assertSuccessful();

    expect($sale->fresh()->unwound_at)->not->toBeNull()
        ->and($business->fresh()->available_balance)->toBe(0)
        // The state a settled sale reads as. It is a consequence rather than a
        // claim about the customer: `unwound_at` is the only mark that stops money
        // being credited for this sale later.
        ->and($sale->fresh()->settlementState())->toBe('refunded');
});

test('a sale still settling is taken back out of the pending balance', function () {
    $business = vtsBusiness();
    $sale = vtsCollected($business);

    // Credited, not yet matured: the money is owed and not spendable, which is why
    // it is debited from a different bucket than the case above.
    expect($business->fresh()->pending_balance)->toBe(vtsNet())
        ->and($business->fresh()->available_balance)->toBe(0);

    vtsRun($this)
        ->expectsOutputToContain('took back from pending')
        ->assertSuccessful();

    expect($sale->fresh()->unwound_at)->not->toBeNull()
        ->and($business->fresh()->pending_balance)->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('an open payout is closed and the sale behind it voided, so nothing is left owed', function () {
    $business = vtsBusiness();
    $sale = vtsPromoted($business);

    // Reserved: the money has left the wallet and the sale is claimed by a transfer
    // that is never going to complete.
    $payout = vtsPayout($business, $sale);

    expect($business->fresh()->available_balance)->toBe(0);

    vtsRun($this)
        ->expectsOutputToContain('Closed 1 payout(s)')
        ->expectsOutputToContain('Voided 1 sale(s).')
        ->assertSuccessful();

    // The close credits the wallet and gives the claim up; the void debits it
    // again. Either one alone would leave the platform owing money or the business
    // holding money it was never sent.
    expect($payout->fresh()->status)->toBe(Payout::STATUS_CANCELLED)
        ->and(PayoutAllocation::query()->where('payout_id', $payout->id)->sole()->status)
        ->toBe(PayoutAllocation::STATUS_RELEASED)
        ->and($sale->fresh()->unwound_at)->not->toBeNull()
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a sale a payout already paid is left alone', function () {
    $business = vtsBusiness();
    $sale = vtsPromoted($business);

    $payout = vtsPayout($business, $sale);

    // The transfer landed. The money is in the business's bank account, and the
    // wallet gave it up at reservation.
    $payout->forceFill(['status' => Payout::STATUS_PAID])->save();
    PayoutAllocation::query()
        ->where('payout_id', $payout->id)
        ->update(['status' => PayoutAllocation::STATUS_SETTLED, 'settled_at' => now()]);

    vtsRun($this)
        ->expectsOutputToContain('Left alone')
        ->assertSuccessful();

    // Debiting this would be the platform charging a business for money it was
    // genuinely paid — the one direction this command must never move in.
    expect($sale->fresh()->unwound_at)->toBeNull()
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a sale a payout is still carrying is left alone', function () {
    $business = vtsBusiness();
    $sale = vtsPromoted($business);

    $payout = vtsPayout($business, $sale, Payout::STATUS_PROCESSING);

    vtsRun($this)->assertSuccessful();

    // A `processing` row is not closed by default, so its claim on the sale stands
    // and the sale is untouched: the transfer may still land.
    expect($sale->fresh()->unwound_at)->toBeNull()
        ->and($payout->fresh()->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a sale the provider collected but never credited is marked so no credit can land', function () {
    $business = vtsBusiness();

    // Paid, platform-managed, and never credited — the row the catch-up pass
    // credits the moment the provider's fee arrives. Nothing is owed on it yet,
    // which is exactly why leaving it alone would put the platform back in debt
    // shortly after this command cleared it.
    $sale = vtsSale($business);

    expect($sale->settle_at)->toBeNull();

    vtsRun($this)
        ->expectsOutputToContain('Voided 1 sale(s).')
        ->assertSuccessful();

    expect($sale->fresh()->unwound_at)->not->toBeNull()
        // Still uncredited, and now uncreditable: nothing moved because nothing was
        // being held.
        ->and($business->fresh()->pending_balance)->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('--business limits the run to one business', function () {
    $mine = vtsBusiness();
    $theirs = vtsBusiness();

    $mySale = vtsPromoted($mine);
    $theirSale = vtsPromoted($theirs);

    vtsRun($this, ['--business' => $mine->id])->assertSuccessful();

    expect($mySale->fresh()->unwound_at)->not->toBeNull()
        ->and($mine->fresh()->available_balance)->toBe(0)
        ->and($theirSale->fresh()->unwound_at)->toBeNull()
        ->and($theirs->fresh()->available_balance)->toBe(vtsNet());
});

test('a platform owing nothing has nothing to do', function () {
    vtsBusiness();

    vtsRun($this)
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();
});

test('a run that is not confirmed changes nothing', function () {
    $business = vtsBusiness();
    $sale = vtsPromoted($business);

    // The default answer to the prompt is no. A command that clears balances must
    // not be able to act on a stray return press.
    $this->artisan('custody:void-test-sales', ['--older-than' => 0])
        ->expectsConfirmation('Close these payouts and void these sales now?', 'no')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertSuccessful();

    expect($sale->fresh()->unwound_at)->toBeNull()
        ->and($business->fresh()->available_balance)->toBe(vtsNet());
});
