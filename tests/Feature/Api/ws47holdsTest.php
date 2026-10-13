<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PayoutHold;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\BusinessLifecycleService;
use App\Services\Admin\StoreLifecycleService;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutHoldService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
| WS-47 — stopping payouts on purpose.
|
| The only switch that stopped a payout before this was `businesses.auto_payout`,
| which nothing in the application could set. These tests are about the three
| widths that replace that, and about the two properties that make a hold usable
| rather than merely present:
|
| **It stops money and never moves it.** A held business is still charged, still
| credited, still promoted, and its balance is untouched — so lifting a hold needs
| no repair, and a payout already with the provider still resolves. Both are pinned
| below, because a hold that quietly stranded an in-flight row would be worse than
| no hold at all: the money would be gone from the wallet with nothing able to
| finish it.
|
| **A store hold withholds that store's share and pays the rest.** That is the
| agreed behaviour, and the interesting half of it is the arithmetic: the withheld
| share is expressed by *narrowing the allocation walk*, not by subtracting a
| figure, because a wallet carrying a deficit behind it has no share to subtract.
*/

function ph47Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function ph47Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);
}

/**
 * A custody business with two stores, so one can be held while the other pays.
 *
 * @return array{0: Business, 1: Store, 2: Store}
 */
function ph47Business(int $availableKobo = 0): array
{
    [$owner, $business] = createBusinessOwner([], ['auto_payout' => true]);

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
    ]);

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
        'payout_bank_id' => $bank->id,
        'available_balance' => $availableKobo,
    ])->save();

    $stores = [];

    foreach ([1, 2] as $index) {
        $stores[] = Store::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'name' => 'Store '.$index.' '.fake()->unique()->numerify('####'),
            'slug' => 'ph47-'.$index.'-'.fake()->unique()->numerify('######'),
            'status' => Store::STATUS_ACTIVE,
            'payment_mode' => 'manual',
        ]);
    }

    return [$business->fresh(), $stores[0], $stores[1]];
}

/** A collected sale whose share is exactly `$kobo`. Zero fees, so net == amount. */
function ph47Sale(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'ORD-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'TXN-'.strtoupper(Str::random(12)),
        'business_id' => $business->id,
        'order_id' => $order?->id,
        'amount' => $kobo / 100,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ]);
}

function ph47Funded(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = ph47Sale($business, $kobo, $store);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($sale, 'bachs');
    $custody->promoteSettled();

    return $sale->fresh();
}

function ph47ProviderAccepts(): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
}

function ph47Payouts(?Business $business = null): Collection
{
    return Payout::query()
        ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
        ->orderBy('id')
        ->get();
}

function ph47Admin(): User
{
    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
    ]);
}

test('a store hold withholds that store\'s share and pays the business the rest', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    [$business, $open, $disputed] = ph47Business();

    $kept = ph47Funded($business, 100000, $open);
    $held = ph47Funded($business, 60000, $disputed);

    expect($business->fresh()->available_balance)->toBe(160000);

    app(PayoutHoldService::class)->holdStore($disputed, 'Chargeback under investigation');

    ph47ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payout = ph47Payouts($business)->firstOrFail();

    // The business is paid everything the open store earned, and nothing else.
    expect($payout->amount_kobo)->toBe(100000)
        ->and($payout->allocations()->pluck('transaction_id')->all())->toBe([(int) $kept->id])
        ->and($payout->allocations()->first()->store_id)->toBe((int) $open->id);

    // And the withheld money is still the business's. A hold stops it moving; it
    // does not touch the wallet, which is what makes lifting the hold pay it out on
    // the next run with nothing to undo.
    expect($business->fresh()->available_balance)->toBe(60000)
        ->and($held->fresh()->promoted_at)->not->toBeNull();
});

test('a hold on the only store leaves the business unheld, recorded, and unpaid', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ph47Business();

    ph47Funded($business, 90000, $store);

    app(PayoutHoldService::class)->holdStore($store, 'Store suspended pending review');

    ph47ProviderAccepts();

    $counts = app(PayoutSweepService::class)->sweep();

    // Not `skipped` and not `sent`: the whole of the balance belongs to a held
    // store, so this is a deliberate withholding rather than a race or a thin
    // record, and it is the count the run has to surface or nobody finds it.
    expect($counts)
        ->toMatchArray(['sent' => 0, 'held' => 0, 'on_hold' => 1, 'skipped' => 0, 'failed' => 0])
        ->and(ph47Payouts($business))->toHaveCount(0)
        ->and($business->fresh()->available_balance)->toBe(90000);
});

test('a business hold stops the sweep without clearing the balance', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ph47Business();

    ph47Funded($business, 120000, $store);

    $hold = app(PayoutHoldService::class)->holdBusiness($business, 'KYC under review');

    ph47ProviderAccepts();

    $counts = app(PayoutSweepService::class)->sweep();

    expect($counts['on_hold'])->toBe(1)
        ->and($counts['sent'])->toBe(0)
        ->and(ph47Payouts($business))->toHaveCount(0)
        // The money is still owed and still visible. Nothing about the hold is a
        // wallet operation, which is why there is nothing to repair afterwards.
        ->and($business->fresh()->available_balance)->toBe(120000)
        ->and($hold->scope)->toBe(PayoutHold::SCOPE_BUSINESS);

    // Lifting it pays everything that accumulated, on the very next run.
    app(PayoutHoldService::class)->releaseBusiness($business);

    app(PayoutSweepService::class)->sweep();

    expect(ph47Payouts($business)->firstOrFail()->amount_kobo)->toBe(120000)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('a system hold stops every business at once', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    [$first, $storeA] = ph47Business();
    [$second, $storeB] = ph47Business();

    ph47Funded($first, 50000, $storeA);
    ph47Funded($second, 70000, $storeB);

    $hold = app(PayoutHoldService::class)->holdSystem('Provider incident, pausing all payouts');

    ph47ProviderAccepts();

    $counts = app(PayoutSweepService::class)->sweep();

    expect($counts['on_hold'])->toBe(2)
        ->and($counts['sent'])->toBe(0)
        ->and(ph47Payouts())->toHaveCount(0);

    // The wider hold is the one reported for both, even if one of them also has a
    // hold of its own — "payouts are off everywhere" is a different message from
    // "this business is held", and the wider one is the one to act on.
    app(PayoutHoldService::class)->holdBusiness($second, 'Operator hold');

    expect(app(PayoutHoldService::class)->holdOn($second)->id)->toBe($hold->id)
        ->and(app(PayoutHoldService::class)->holdOn($first)->id)->toBe($hold->id);
});

test('a hold does not strand a payout already with the provider', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ph47Business();

    ph47Funded($business, 80000, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    $sweep = app(PayoutSweepService::class);
    $sweep->sweep();

    $payout = ph47Payouts($business)->firstOrFail();

    expect($payout->status)->toBe(Payout::STATUS_PROCESSING);

    // The investigation starts *after* the money has left. This is the state a hold
    // has to be safe in: the reservation is out of the wallet and the row is with
    // the provider, so a hold that also blocked resolution would leave money that
    // has gone with nothing able to confirm it.
    app(PayoutHoldService::class)->holdSystem('Incident opened mid-flight');

    Payout::query()->update(['requested_at' => now()->subHour()]);

    expect($sweep->reconcile()['settled'])->toBe(1);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('the walk lets a held store absorb none of a deficit behind the wallet', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    [$business, $open, $disputed] = ph47Business();

    $refunded = ph47Funded($business, 100000, $open);
    ph47Funded($business, 60000, $disputed);

    ph47ProviderAccepts();

    $sweep = app(PayoutSweepService::class);
    $sweep->sweep();

    expect(ph47Payouts($business)->firstOrFail()->amount_kobo)->toBe(160000);

    // A refund after the payout, and then the disputed store is held.
    app(CustodyService::class)->unwindCollectedSale($refunded, 'Refunded in full');

    app(PayoutHoldService::class)->holdStore($disputed, 'Chargeback under investigation');

    expect($business->fresh()->available_balance)->toBe(-100000);

    // A later sale in the open store covers the debt and leaves ₦500 genuinely
    // payable. It is ₦500 of *that* sale, and the withheld store's ₦600 absorbs
    // none of the deficit — which is the case that rules out computing the send as
    // "the balance minus the held store's share": there is no share to subtract
    // while the wallet is negative, and a share computed anyway would overpay.
    $later = ph47Funded($business, 150000, $open);

    expect($business->fresh()->available_balance)->toBe(50000);

    $sweep->sweep();

    $second = ph47Payouts($business)->last();

    expect($second->amount_kobo)->toBe(50000)
        ->and($second->allocations()->first()->transaction_id)->toBe((int) $later->id)
        ->and($second->allocations()->first()->amount_kobo)->toBe(50000);
});

test('a store hold does not swallow money no sale can account for', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    // A balance with nothing behind it and a store held. The held store has no
    // sales, so it explains none of the balance, and withholding money that no
    // sale accounts for because *some* store happens to be held would be reading
    // the hold as a claim rather than as a question.
    [$business, , $disputed] = ph47Business(availableKobo: 75000);

    app(PayoutHoldService::class)->holdStore($disputed, 'Nothing to do with this balance');

    ph47ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    expect(ph47Payouts($business)->firstOrFail()->amount_kobo)->toBe(75000)
        ->and($business->fresh()->available_balance)->toBe(0);
});

test('placing a hold twice is one hold, and released rows do not collide', function () {
    ph47Platform();

    [$business, $store] = ph47Business();

    $holds = app(PayoutHoldService::class);

    $first = $holds->holdBusiness($business, 'First look');
    $again = $holds->holdBusiness($business, 'Second look');

    // Idempotent: the second call is the same hold, and the reason is the one that
    // was actually recorded rather than the later one quietly overwriting it.
    expect($again->id)->toBe($first->id)
        ->and($again->reason)->toBe('First look')
        ->and(PayoutHold::query()->open()->count())->toBe(1);

    // The unique index is what makes that true under a race, and it is scoped to
    // the *open* rows: MySQL treats NULLs as distinct, so a second placement here
    // has to be refused by the database rather than by the read above it.
    expect(fn () => PayoutHold::query()->create([
        'scope' => PayoutHold::SCOPE_BUSINESS,
        'business_id' => $business->id,
        'store_id' => 0,
        'reason' => 'Raced',
        'source' => PayoutHold::SOURCE_MANUAL,
        'placed_at' => now(),
        'open_marker' => PayoutHold::OPEN,
    ]))->toThrow(QueryException::class);

    expect($holds->releaseBusiness($business))->toBe(1)
        ->and($holds->releaseBusiness($business))->toBe(0)
        ->and(PayoutHold::query()->open()->count())->toBe(0);

    // Held again after being released, which is the row that would be refused if
    // the index were built on `released_at` instead of on the open marker: a
    // subject could then only ever be held once in its lifetime, and the second
    // investigation into the same business would have nowhere to write itself down.
    $holds->holdBusiness($business, 'A second, later look');

    expect(PayoutHold::query()->count())->toBe(2)
        ->and(PayoutHold::query()->open()->count())->toBe(1);

    // A store-scope hold on the same business is a different subject, so it is
    // permitted alongside it.
    $holds->holdStore($store, 'One store');

    expect(PayoutHold::query()->open()->count())->toBe(2);
});

test('suspending a business holds its payouts, and activating lifts only that hold', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);
    Mail::fake();

    [$business, $store] = ph47Business();

    $admin = ph47Admin();
    $lifecycle = app(BusinessLifecycleService::class);

    // A hold somebody placed while investigating, which reactivation must not touch.
    $manual = app(PayoutHoldService::class)->holdBusiness($business, 'Operator look');

    $lifecycle->suspend($business, 'Chargeback rate above threshold', $admin);

    $suspension = PayoutHold::query()->open()
        ->where('scope', PayoutHold::SCOPE_BUSINESS)
        ->where('source', PayoutHold::SOURCE_SUSPENSION)
        ->firstOrFail();

    expect(PayoutHold::query()->open()->count())->toBe(2)
        ->and($suspension->reason)->toContain('Chargeback rate above threshold')
        ->and($suspension->placed_by)->toBe($admin->id);

    $lifecycle->activate($business, 'Investigation closed', $admin);

    // Only the suspension's own. Reactivating a business is not a statement that
    // somebody else's investigation is over, and releasing that too would pay out
    // money that is still being looked at.
    expect($suspension->fresh()->open_marker)->toBeNull()
        ->and($suspension->fresh()->released_at)->not->toBeNull()
        ->and($manual->fresh()->open_marker)->toBe(PayoutHold::OPEN)
        ->and(PayoutHold::query()->open()->count())->toBe(1);

    // And it is the manual hold that still stops the money.
    ph47Funded($business, 40000, $store);
    ph47ProviderAccepts();

    expect(app(PayoutSweepService::class)->sweep()['on_hold'])->toBe(1)
        ->and(ph47Payouts($business))->toHaveCount(0);
});

test('suspending a store withholds one store and leaves the business payable', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);
    Mail::fake();

    [$business, $open, $disputed] = ph47Business();

    $admin = ph47Admin();

    $kept = ph47Funded($business, 70000, $open);
    ph47Funded($business, 30000, $disputed);

    app(StoreLifecycleService::class)->suspend($disputed, 'Order fraud suspected', $admin);

    $hold = PayoutHold::query()->open()->where('scope', PayoutHold::SCOPE_STORE)->firstOrFail();

    expect($hold->store_id)->toBe((int) $disputed->id)
        ->and($hold->business_id)->toBe((int) $business->id)
        ->and($hold->source)->toBe(PayoutHold::SOURCE_SUSPENSION);

    ph47ProviderAccepts();

    $counts = app(PayoutSweepService::class)->sweep();

    // A dispute about one store's orders is not a business-wide payout outage: the
    // business is paid for its other store at once, and only the disputed store's
    // ₦300 waits for the investigation.
    expect($counts['sent'])->toBe(1)
        ->and($counts['on_hold'])->toBe(0)
        ->and(ph47Payouts($business)->firstOrFail()->amount_kobo)->toBe(70000)
        ->and(ph47Payouts($business)->firstOrFail()->allocations()->pluck('transaction_id')->all())
        ->toBe([(int) $kept->id]);

    app(StoreLifecycleService::class)->activate($disputed, 'Cleared', $admin);

    expect(PayoutHold::query()->open()->count())->toBe(0);

    app(PayoutSweepService::class)->sweep();

    expect(ph47Payouts($business)->last()->amount_kobo)->toBe(30000);
});

test('a deferred run reports a held business with its reason rather than as a bare count', function () {
    ph47Platform();
    ph47Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ph47Business();

    ph47Funded($business, 45000, $store);

    app(PayoutHoldService::class)->holdBusiness($business, 'Awaiting bank confirmation');

    $this->artisan('custody:payouts', ['--dry-run' => true])
        ->expectsOutputToContain('Awaiting bank confirmation')
        ->assertSuccessful();

    // Not a hold: the same command has to keep saying why a business is not being
    // paid however it is not being paid.
    app(PayoutHoldService::class)->releaseBusiness($business);
    $business->forceFill(['auto_payout' => false])->save();

    $this->artisan('custody:payouts', ['--dry-run' => true])
        ->expectsOutputToContain('held: automatic payouts off')
        ->assertSuccessful();
});

test('the hold command refuses to place a hold without a reason', function () {
    ph47Platform();

    [$business] = ph47Business();

    $this->artisan('custody:hold', ['--business' => $business->id])
        ->expectsOutputToContain('A reason is required')
        ->assertFailed();

    expect(PayoutHold::query()->count())->toBe(0);

    $this->artisan('custody:hold', [
        '--business' => $business->id,
        '--reason' => 'Manual review',
    ])->assertSuccessful();

    $hold = PayoutHold::query()->open()->firstOrFail();

    expect($hold->reason)->toBe('Manual review')
        ->and($hold->scope)->toBe(PayoutHold::SCOPE_BUSINESS);

    // And with no scope at all it lists, which is how the question "is anything
    // held right now" gets answered without knowing an id.
    $this->artisan('custody:hold')
        ->expectsOutputToContain('1 payout hold(s) are in force')
        ->assertSuccessful();

    $this->artisan('custody:release', ['--business' => $business->id])
        ->expectsOutputToContain('Lifted 1 hold(s)')
        ->assertSuccessful();

    $this->artisan('custody:hold')
        ->expectsOutputToContain('Nothing is held')
        ->assertSuccessful();
});
