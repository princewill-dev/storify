<?php

use App\Models\Business;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Services\Payments\Custody\PayoutSweepService;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-44 — flipping a business onto collect-and-pay-out, and back off again.
|
| The flag is one boolean, and since the account machinery was deleted that is
| the whole of the operation. The command below used to spend most of its length
| on a drain check: read the balance still sitting in the business's own account
| at the provider and refuse while there was any, because an account orphaned
| mid-flight is money nobody is watching. That account is what collect-and-pay-out
| exists to delete, so with the rail gone there is nothing to leave behind and
| nothing to detect.
|
| Which is the thing this file has to pin, because a shorter command reads like a
| neglected one. The tests below therefore assert the *absences* — that the
| provider is asked nothing at all, and that a business with no bank is flipped
| and warned rather than refused — because those are the behaviours a future
| change would most plausibly reintroduce by accident.
|
| The rollback is the other half. It has to be available in a hurry, which is why
| it does not clear the wallet: a business rolled back after a sale is still owed
| that money, and zeroing it would keep it.
*/

/**
 * Nothing on this path may reach out, so anything that does is a failure rather
 * than a fixture. Faked empty so a stray call shows up as a `ConnectionException`
 * naming the URL instead of as a real request to a sandbox.
 */
function cf44NoProvider(): void
{
    Http::swap(new Factory);
    Http::fake();
}

/** A bank the business has named as where its money should go. */
function cf44Bank(Business $business): StoreBank
{
    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
    ]);

    $business->forceFill(['payout_bank_id' => $bank->id])->save();

    return $bank;
}

/**
 * A business Storify is not collecting for.
 *
 * Written explicitly, because the column now defaults to on: every business on
 * the platform is collected for, and it is being *off* that is the exception the
 * `custody:enable` tests start from.
 */
function cf44NotCollected(Business $business): Business
{
    $business->forceFill(['custody_enabled' => false, 'custody_enabled_at' => null])->save();

    return $business->fresh();
}

/** A custody business, with whatever it is owed already sitting in the wallet. */
function cf44Custody(int $availableKobo = 0, int $pendingKobo = 0): Business
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    cf44Bank($business);

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
        'available_balance' => $availableKobo,
        'pending_balance' => $pendingKobo,
    ])->save();

    return $business->fresh();
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/* ------------------------------------------------------------- the flip */

test('the dry run reports the flip and changes nothing', function () {
    // The dry run is how an operator finds out what a rollout would do, so it has
    // to report per business and it must not write the flag. Both halves are
    // asserted, because a dry run that flipped would be the worst possible
    // version of this command: silent, and only discovered by its consequences.
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cf44Bank($business);
    cf44NotCollected($business);

    $this->artisan('custody:enable', ['businesses' => [(string) $business->id], '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('would be flipped');

    expect($business->fresh()->isUnderCustody())->toBeFalse();
});

test('flipping writes the flag and asks the provider nothing', function () {
    // The whole operation, and the assertion that keeps the deleted drain from
    // creeping back. There is no account at a provider to read a balance from any
    // more, so a request leaving this command is a request about a business
    // state that no longer exists.
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cf44Bank($business);
    cf44NotCollected($business);

    $this->artisan('custody:enable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful();

    expect($business->fresh()->isUnderCustody())->toBeTrue();

    Http::assertNothingSent();
});

test('a business with nowhere to send the money is flipped and warned, not refused', function () {
    // It looks like a gate and is deliberately not one. Under custody the business
    // can sell all day and Storify holds the proceeds, so a flip without a bank
    // creates a balance the sweep skips every hour — which is a payout delay. It
    // was once a refusal, because the old rail could still settle the business and
    // holding it back kept its money moving; that rail is gone, so refusing now
    // would leave the business on no rail at all. It is flipped, and told.
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cf44NotCollected($business);

    $this->artisan('custody:enable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('no payout bank');

    expect($business->fresh()->isUnderCustody())->toBeTrue();
});

test('a payout bank with no account number on it is warned about too', function () {
    // The other half of the same note. A `store_banks` row with a pointer but no
    // numbers is a destination the sweep will read and be unable to pay, which is
    // indistinguishable from having none until it is tried.
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cf44NotCollected($business);

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '',
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
    ]);

    $business->forceFill(['payout_bank_id' => $bank->id])->save();

    $this->artisan('custody:enable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('account number');

    expect($business->fresh()->isUnderCustody())->toBeTrue();
});

test('a wallet that already holds money is reported rather than silently kept', function () {
    // Reachable on a business flipped on, off, and on again: the rollback does not
    // clear the wallet, so a figure there is money somebody is still owed. The
    // flip neither clears it nor hides it.
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cf44Bank($business);

    $business->forceFill([
        'custody_enabled' => false,
        'available_balance' => 50000,
    ])->save();

    $this->artisan('custody:enable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('500.00');

    $fresh = $business->fresh();

    expect($fresh->isUnderCustody())->toBeTrue()
        // Untouched: the money was owed before the flip and is owed after it.
        ->and((int) $fresh->available_balance)->toBe(50000);
});

test('a business already under custody is left alone', function () {
    cf44NoProvider();

    $business = cf44Custody();
    $before = $business->custody_enabled_at;

    $this->artisan('custody:enable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('already collecting');

    expect($business->fresh()->isUnderCustody())->toBeTrue()
        // And the timestamp is whatever it was: re-running a rollout must not
        // restate when the business was flipped.
        ->and($business->fresh()->custody_enabled_at->equalTo($before))->toBeTrue();
});

test('a business id that matches nothing fails before anything is flipped', function () {
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cf44Bank($business);
    cf44NotCollected($business);

    // Two arguments, the second bad. Nothing at all is flipped — not even the
    // one that would have been fine — because a rollout that half-applied is
    // worse to reason about than one that never started.
    $this->artisan('custody:enable', ['businesses' => [(string) $business->id, 'NO-SUCH-BUSINESS']])
        ->assertFailed();

    expect($business->fresh()->isUnderCustody())->toBeFalse();
});

test('putting a business back warns when the provider keeps refusing its bank', function () {
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = cf44Bank($business);
    cf44NotCollected($business);

    // The state the sweep's ceiling leaves behind: the bank is on file and has
    // been refused as many times as allowed, so payouts are blocked until somebody
    // looks at it. Said here, to the operator already looking at this business,
    // rather than left to be discovered when the money does not arrive.
    foreach (range(1, 3) as $ignored) {
        Payout::reserve(
            $business->id,
            null,
            'bachs',
            (string) $bank->account_number,
            (string) $bank->bank_code,
            (string) $bank->account_name,
            50000,
        )->forceFill([
            'status' => Payout::STATUS_FAILED,
            'failure_code' => 'destination_unusable',
            'failed_at' => now(),
        ])->save();
    }

    $this->artisan('custody:enable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('keeps refusing its payout bank');
});

/* --------------------------------------------------------------- the rollback */

test('the rollback stops the collection and leaves what is owed in the wallet', function () {
    cf44NoProvider();

    // ₦500 payable and ₦250 not yet settled — both owed, and both still on their
    // way out: the flag stops new charges, not the payout run.
    $business = cf44Custody(availableKobo: 50000, pendingKobo: 25000);

    $this->artisan('custody:disable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('750.00')
        // The sentence the operator has to be able to trust, and the one this
        // command used to get backwards: it promised the money would have to be
        // paid by hand, from a console that had already been deleted.
        ->expectsOutputToContain('still paying this business');

    $fresh = $business->fresh();

    expect($fresh->isUnderCustody())->toBeFalse()
        // Untouched, deliberately: zeroing it would keep money the business is
        // owed, and there is no second rail left to notice.
        ->and((int) $fresh->available_balance)->toBe(50000)
        ->and((int) $fresh->pending_balance)->toBe(25000)
        ->and(DB::table('payouts')->count())->toBe(0);
});

test('a rollback of a business with an empty wallet says there is nothing owing', function () {
    cf44NoProvider();

    $business = cf44Custody();

    $this->artisan('custody:disable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('nothing left owing');
});

test('the rollback counts the sales it is leaving inside their settlement window', function () {
    // The figure an operator needs to size the problem by hand: the money is
    // reported by amount, but "how many sales is that" is what decides whether it
    // is one payout or a night of them.
    cf44NoProvider();

    $business = cf44Custody(pendingKobo: 25000);

    Transaction::create([
        'reference' => 'TXN-CF44-SETTLING',
        'business_id' => $business->id,
        'amount' => 250,
        'currency' => 'NGN',
        'status' => 'confirmed',
        'paid_at' => now(),
        'settle_at' => now()->addDay(),
    ]);

    $this->artisan('custody:disable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('1 sale(s)');
});

test('rolling back a business that is not under custody changes nothing', function () {
    cf44NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    cf44NotCollected($business);

    $this->artisan('custody:disable', ['businesses' => [(string) $business->id]])
        ->assertSuccessful()
        ->expectsOutputToContain('not collecting through Storify');

    expect($business->fresh()->isUnderCustody())->toBeFalse();
});

test('the rollback dry run reports what would be left owing without rolling back', function () {
    cf44NoProvider();

    $business = cf44Custody(availableKobo: 50000);

    $this->artisan('custody:disable', ['businesses' => [(string) $business->id], '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('500.00');

    expect($business->fresh()->isUnderCustody())->toBeTrue();
});

test('a rolled-back business is still paid out of the wallet', function () {
    // The thing a rollback used to get wrong, and the reason it is worth its own
    // test rather than an assertion inside the output one. `custody:disable` says
    // the sweep is still paying the business; this is that sentence being true.
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);

    $settings = Setting::query()->first() ?? Setting::query()->create(['company_name' => 'Storify']);
    $settings->update(['system_payment_processor' => 'bachs']);

    $business = cf44Custody(availableKobo: 50000);

    $this->artisan('custody:disable', ['businesses' => [(string) $business->id]])->assertSuccessful();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);

    $counts = app(PayoutSweepService::class)->sweep();

    expect($counts['sent'])->toBe(1)
        ->and($counts['held'])->toBe(0)
        // The flag is still off: nothing about paying what was owed switched it back.
        ->and($business->fresh()->isUnderCustody())->toBeFalse();

    $payout = Payout::query()->where('business_id', $business->id)->firstOrFail();

    expect($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->amount_kobo)->toBe(50000)
        // The reservation is spent, so the money really moved rather than being
        // left as a payable balance the sweep would report forever.
        ->and((int) $business->fresh()->available_balance)->toBe(0);
});
