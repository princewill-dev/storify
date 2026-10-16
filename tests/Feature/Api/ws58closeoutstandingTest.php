<?php

use App\Models\Business;
use App\Models\Payout;
use App\Services\Payments\Custody\PayoutSweepService;

/*
| WS-58 — closing out payouts that nothing is going to send.
|
| A payout row is a claim on money. `reserve()` takes the amount off the wallet and
| commits the sales behind it, and the *only* thing that gives either of them back
| is the row reaching a terminal status. So a row left open is real money a business
| cannot see, holding the promotion budget down and making whoever owns it look
| blocked — and once the queue has burned its three attempts and the operator alert
| has fired, nothing else will ever move that row.
|
| `custody:close-outstanding-payouts` is the way out, and this pins the two things
| that make it safe to run:
|
| **The money comes back where it is certainly ours.** A `pending` row was never
| handed to a provider, so closing it credits the wallet and releases the sales —
| the same treatment `fail()` gives a refusal. A `failed` row already had its
| reservation released, so closing it must move nothing at all: crediting it twice
| would invent money the provider never sent.
|
| **`processing` is not touched unless asked for.** That money may still be in
| flight; releasing the reservation of a transfer that lands pays the business
| twice. The default leaves those rows alone, and the flag that overrides it is a
| deliberate one, not a default.
*/

function co58Payout(Business $business, int $kobo, string $status = Payout::STATUS_PENDING): Payout
{
    $payout = Payout::reserve(
        businessId: $business->id,
        storeId: null,
        provider: 'bachs',
        accountNumber: '0123456789',
        bankCode: '033',
        accountName: 'Amina Okafor',
        amountKobo: $kobo,
    );

    if ($status !== Payout::STATUS_PENDING) {
        // The shape the row would be in after that transition. Written directly
        // because the transition itself is not what this file is testing, and
        // driving it would mean faking a provider to prove something about a
        // command that never calls one.
        $payout->forceFill(['status' => $status])->save();
    }

    return $payout->refresh();
}

/**
 * A business with settled money, and that money reserved against a payout.
 *
 * `debitAvailable` is the half of `reserve()` that takes the money off the wallet;
 * the other half is the row itself, which the tests create alongside it. Doing it
 * this way rather than through the sweep keeps these tests about the close-out and
 * not about the send.
 */
function co58Funded(int $kobo): Business
{
    [, $business] = createBusinessOwner();

    $business->creditPending($kobo);
    $business->promoteToAvailable($kobo);
    $business->debitAvailable($kobo);

    return $business->refresh();
}

test('a pending payout is closed and its money goes back to the wallet', function () {
    $business = co58Funded(100000);
    $payout = co58Payout($business, 100000);

    // Reserved: the business cannot see this money.
    expect($business->fresh()->available_balance)->toBe(0);

    $result = app(PayoutSweepService::class)->closeOutstanding();

    expect($payout->fresh()->status)->toBe(Payout::STATUS_CANCELLED)
        ->and($result['closed'])->toBe(1)
        ->and($result['by_status'][Payout::STATUS_PENDING])->toBe(1)
        ->and($result['restored_kobo'])->toBe(100000)
        ->and($business->fresh()->available_balance)->toBe(100000);
});

test('a failed payout is closed without moving any money', function () {
    $business = co58Funded(100000);
    $payout = co58Payout($business, 100000, Payout::STATUS_FAILED);

    // A refusal already gave the money back, so this is what the wallet looks like
    // before the command runs — the assertion after it is the one that matters.
    expect($business->fresh()->available_balance)->toBe(0);

    $result = app(PayoutSweepService::class)->closeOutstanding();

    expect($payout->fresh()->status)->toBe(Payout::STATUS_CANCELLED)
        ->and($result['closed'])->toBe(1)
        // Nothing restored, because nothing was owed by this call.
        ->and($result['restored_kobo'])->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a processing payout is left alone by default', function () {
    $business = co58Funded(100000);
    $payout = co58Payout($business, 100000, Payout::STATUS_PROCESSING);

    $result = app(PayoutSweepService::class)->closeOutstanding();

    expect($result['closed'])->toBe(0)
        // Still where it was: this money may be in flight, and releasing it would
        // pay the business twice.
        ->and($payout->fresh()->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a processing payout is closed when the operator asks for it', function () {
    $business = co58Funded(100000);
    $payout = co58Payout($business, 100000, Payout::STATUS_PROCESSING);

    $result = app(PayoutSweepService::class)->closeOutstanding(includeProcessing: true);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_CANCELLED)
        ->and($result['restored_kobo'])->toBe(100000)
        ->and($business->fresh()->available_balance)->toBe(100000);
});

test('closing twice does not credit the wallet twice', function () {
    $business = co58Funded(100000);
    co58Payout($business, 100000);

    $sweep = app(PayoutSweepService::class);

    $first = $sweep->closeOutstanding();
    $second = $sweep->closeOutstanding();

    expect($first['closed'])->toBe(1)
        // The row is claimed by condition, so the second run finds nothing to claim
        // and — the part that matters — releases nothing.
        ->and($second['closed'])->toBe(0)
        ->and($second['restored_kobo'])->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(100000);
});

test('a payout touched inside the quiet window is not closed', function () {
    $business = co58Funded(100000);
    $payout = co58Payout($business, 100000);

    $sweep = app(PayoutSweepService::class);

    // A row that has just been touched is the shape of a send in progress — the
    // stamp goes down before the provider is called — so it is skipped.
    expect($sweep->closeOutstanding(quiescentMinutes: 5)['closed'])->toBe(0)
        ->and($payout->fresh()->status)->toBe(Payout::STATUS_PENDING);

    // Written through the query builder, which does not touch `updated_at` the way
    // `save()` would.
    Payout::query()->whereKey($payout->id)->update(['updated_at' => now()->subMinutes(10)]);

    // And once it has gone quiet it is closed, which is what makes the window a
    // delay rather than a refusal.
    expect($sweep->closeOutstanding(quiescentMinutes: 5)['closed'])->toBe(1)
        ->and($payout->fresh()->status)->toBe(Payout::STATUS_CANCELLED)
        ->and($business->fresh()->available_balance)->toBe(100000);
});

test('the command closes the open payouts and reports the money it returned', function () {
    $business = co58Funded(100000);

    co58Payout($business, 100000);
    co58Payout($business, 50000, Payout::STATUS_FAILED);

    $this->artisan('custody:close-outstanding-payouts', ['--force' => true, '--older-than' => 0])
        ->expectsOutputToContain('Closed 2 payout(s).')
        ->assertSuccessful();

    expect($business->fresh()->available_balance)->toBe(100000);
});

test('the command can be limited to one business', function () {
    $closed = co58Funded(100000);
    $untouched = co58Funded(100000);

    co58Payout($closed, 100000);
    co58Payout($untouched, 100000);

    $this->artisan('custody:close-outstanding-payouts', [
        '--business' => $closed->id,
        '--force' => true,
        '--older-than' => 0,
    ])->assertSuccessful();

    expect($closed->fresh()->available_balance)->toBe(100000)
        ->and($untouched->fresh()->available_balance)->toBe(0);
});

test('a processing payout is reported as deliberately left open', function () {
    $business = co58Funded(100000);
    co58Payout($business, 100000, Payout::STATUS_PROCESSING);

    // The command must not look like it did nothing: a reader has to be told the
    // clear-up is unfinished, and what to type if they want it finished.
    $this->artisan('custody:close-outstanding-payouts', ['--force' => true, '--older-than' => 0])
        ->expectsOutputToContain('custody:close-outstanding-payouts --include-processing')
        ->assertSuccessful();

    expect($business->fresh()->available_balance)->toBe(0);
});
