<?php

use App\Enums\TransactionStatus;
use App\Jobs\SettlePayouts;
use App\Mail\PayoutPaidMail;
use App\Models\Business;
use App\Models\BusinessNotification;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\InstantPayoutService;
use App\Services\Payments\Custody\PayoutSweepService;
use App\Services\Payments\Custody\WalletSummaryService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| WS-63 — the payout processing fee.
|
| A flat fee is taken out of each transfer, so the amount the wallet is debited
| and the amount the business's bank receives are not the same number. The
| differences this file pins:
|
| **The fee is a snapshot, not a policy lookup.** It is written onto the payout
| row at reservation and read from there for the rest of that payout's life.
| Changing the setting while a transfer is in flight must not change its terms —
| the business agreed to a figure, and the row is the record of it.
|
| **The wallet stays gross.** The debit and the allocations still sum to the
| whole amount the sales earned; only the rail is asked for the net. A fee is a
| cost taken out of the transfer, not a second, invisible payout.
|
| **Both sets of books split it.** The business records its own processing
| expense, and the platform records the fee as service-charge income, so the
| platform's clearing identity `1030 = 2300 + 4020 − 5510` stays true.
|
| **A payout that never happened charges nothing.** A refusal restores the gross
| to the wallet, and the next attempt takes the fee that is current then.
*/

function pf63Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function pf63Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * A custody business with a bank and two stores.
 *
 * @return array{0: Business, 1: Store, 2: Store, 3: StoreBank, 4: User}
 */
function pf63Business(): array
{
    [$owner, $business] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['auto_payout' => true],
    );

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
    ])->save();

    $stores = [];

    foreach ([1, 2] as $index) {
        $stores[] = Store::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'name' => 'PF63 Store '.$index,
            'slug' => 'pf63-'.$index.'-'.fake()->unique()->numerify('######'),
            'status' => Store::STATUS_ACTIVE,
            'payment_mode' => 'manual',
        ]);
    }

    return [$business->fresh(), $stores[0], $stores[1], $bank, $owner];
}

/** A confirmed managed sale whose share is exactly `$kobo`, with no fees of its own. */
function pf63Sale(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'PF63-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'API_PF63_'.strtoupper(Str::random(10)),
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

/** Credit a sale to the wallet and offer to pay it. */
function pf63Collect(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = pf63Sale($business, $kobo, $store);

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    app(InstantPayoutService::class)->afterCollection($sale);

    return $sale->fresh();
}

/**
 * Bachs: a readable balance, a usable destination, and a transfer it accepts.
 *
 * The payouts half is a separate helper so a test can sequence two answers for
 * the same URL — `Http::fake()` merges stubs, and the first match wins.
 */
function pf63Provider(): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_pf63',
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => '500000.00',
                'pending_balance' => '0.00',
            ]],
            'pending_settlements_by_day' => [],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
    ]);

    Http::fakeSequence('sandbox-api.bachs.io/v1/payouts')
        ->push(['id' => 'pay_1', 'status' => 'processing']);
}

/** The body of the one request the provider received at `$path`, or []. */
function pf63Sent(string $path, bool $exact = false): array
{
    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent, $path, $exact) {
        $url = $request->url();

        $matches = $exact
            ? str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', $path)
            : str_contains($url, $path);

        if (! $matches) {
            return false;
        }

        $sent ??= $request->data();

        return true;
    });

    return $sent ?? [];
}

/** @return Collection<int, Payout> */
function pf63Payouts(?Business $business = null): Collection
{
    return Payout::query()
        ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
        ->orderBy('id')
        ->get();
}

/**
 * Put the one transfer this fixture sent back in flight and ask the provider
 * what happened to it. The fake for the lookup is installed by the caller,
 * because `Http::fake()` merges stubs rather than replacing them — a second
 * answer needs `Http::fakeSequence()`, not a second `fake()`.
 */
function pf63Reconcile(Payout $payout): array
{
    Payout::query()->whereKey($payout->id)->update([
        'requested_at' => now()->subHour(),
        // A settled row is not one `reconcile()` will look at, and the reversal
        // test needs a second answer about the same transfer.
        'status' => Payout::STATUS_PROCESSING,
    ]);

    return app(PayoutSweepService::class)->reconcile();
}

/** @return array<string, int> */
function pf63Lines(?JournalEntry $entry): array
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

/** A superadmin, who may save platform settings. */
function pf63SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

/** A platform admin holding `$role`. */
function pf63Admin(string $role = 'Finance Admin'): User
{
    (new SpatiePermissionSeeder)->run();

    $admin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $admin->assignRole($role);

    return $admin;
}

function pf63SettingsPayload(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Storify',
        'trial_enabled' => true,
        'trial_days' => 7,
        'greeting_modal_enabled' => false,
        'greeting_modal_frequency' => 'never',
    ], $overrides);
}

/* ------------------------------------------------------------- the split */

test('the fee is recorded on the payout and the rail receives the net', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
        'payout_processing_fee_kobo' => 5000,
    ]);

    [$business, $store] = pf63Business();
    pf63Provider();

    pf63Collect($business, 340000, $store);

    $payout = pf63Payouts($business)->sole();

    expect((int) $payout->amount_kobo)->toBe(340000)
        ->and($payout->feeKobo())->toBe(5000)
        ->and($payout->netKobo())->toBe(335000)
        // The rail is asked for what the bank should receive, not the gross.
        ->and(pf63Sent('/v1/payouts', exact: true)['amount'])->toBe('3350.00')
        // The wallet was debited the whole amount and the allocations explain
        // all of it: the fee is part of the payout, not a second one.
        ->and((int) $payout->allocations()->sum('amount_kobo'))->toBe(340000)
        ->and((int) $business->fresh()->available_balance)->toBe(0);
});

test('the payout mail and notice quote what the bank received, not the gross', function () {
    // The wallet is debited gross and the rail is asked for the net, so the two
    // figures are different whenever a fee applies. The business reconciles this
    // against its bank statement: quoting the gross makes it subtract the fee by
    // hand, and makes a correct payout look short.
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
        'payout_processing_fee_kobo' => 5000,
    ]);

    [$business, $store] = pf63Business();
    pf63Provider();

    pf63Collect($business, 340000, $store);

    $payout = pf63Payouts($business)->sole();

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    pf63Reconcile($payout);

    $payout->refresh();

    expect($payout->status)->toBe(Payout::STATUS_PAID)
        ->and($payout->amount_kobo)->toBe(340000)
        ->and($payout->feeKobo())->toBe(5000)
        ->and($payout->netKobo())->toBe(335000);

    $mail = (new PayoutPaidMail($business->owner, $business, $payout, 'Guaranty Trust Bank'))->render();

    expect($mail)
        ->toContain('NGN 3,350.00')
        ->not->toContain('3,400.00');

    $notice = BusinessNotification::query()
        ->where('business_id', $business->id)
        ->where('type', BusinessNotification::TYPE_PAYOUT_PAID)
        ->sole();

    expect($notice->title)->toContain('3,350.00')
        ->and($notice->body)->toContain('3,350.00')
        ->and((int) $notice->amount_kobo)->toBe(335000);
});

test('a later policy change cannot alter an in-flight payout', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
        'payout_processing_fee_kobo' => 5000,
    ]);

    [$business, $store] = pf63Business();
    pf63Provider();

    pf63Collect($business, 340000, $store);

    $payout = pf63Payouts($business)->sole();

    // The admin triples the fee while the transfer is with the provider.
    pf63Settings(['payout_processing_fee_kobo' => 20000]);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    pf63Reconcile($payout);
    $payout->refresh();

    // The row still carries the fee it was reserved at, and both books split
    // that figure — not the one the setting has now.
    expect($payout->status)->toBe(Payout::STATUS_PAID)
        ->and($payout->feeKobo())->toBe(5000)
        ->and($payout->netKobo())->toBe(335000);

    $businessEntry = JournalEntry::query()
        ->where('idempotency_key', 'payout:'.$payout->reference)
        ->firstOrFail();

    expect(pf63Lines($businessEntry))->toBe([
        '1020:dr' => 335000,
        '5500:dr' => 5000,
        '1040:cr' => 340000,
    ]);
});

test('a zero fee is exactly the old behaviour', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 0,
        'payout_processing_fee_kobo' => 0,
    ]);

    [$business, $store] = pf63Business();
    pf63Provider();

    pf63Collect($business, 100000, $store);

    $payout = pf63Payouts($business)->sole();

    expect(pf63Sent('/v1/payouts', exact: true)['amount'])->toBe('1000.00')
        ->and($payout->feeKobo())->toBe(0)
        ->and($payout->netKobo())->toBe(100000);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    pf63Reconcile($payout);

    $businessEntry = JournalEntry::query()
        ->where('idempotency_key', 'payout:'.$payout->reference)
        ->firstOrFail();
    $platformEntry = JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'custody_payout:'.$payout->reference)
        ->firstOrFail();

    // Two lines each: no expense and no income entry exists to hold a fee.
    expect(pf63Lines($businessEntry))->toBe(['1020:dr' => 100000, '1040:cr' => 100000])
        ->and(pf63Lines($platformEntry))->toBe(['2300:dr' => 100000, '1030:cr' => 100000]);
});

test('a refused payout charges no fee and the retry takes the current one', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
        'payout_processing_fee_kobo' => 5000,
    ]);

    [$business, $store] = pf63Business();

    // Two answers for the same endpoint, in order: the first attempt is
    // refused, the retry is accepted. A 422 and not a 5xx — the provider is
    // speaking about this transfer rather than being unreachable, which is
    // what leaves a row in flight waiting on a lookup.
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'balances' => [['currency' => 'NGN', 'available_balance' => '500000.00']],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
    ]);

    Http::fakeSequence('sandbox-api.bachs.io/v1/payouts')
        ->push(['message' => 'Transfer declined'], 422)
        ->push(['id' => 'pay_1', 'status' => 'processing']);

    pf63Collect($business, 340000, $store);

    $failed = pf63Payouts($business)->sole();

    expect($failed->status)->toBe(Payout::STATUS_FAILED)
        // The gross went back, not the net: a payout that never happened must
        // not cost the business its fee.
        ->and((int) $business->fresh()->available_balance)->toBe(340000)
        // Nothing was posted, so no fee was recognised as income either.
        ->and(JournalEntry::query()->where('reference', $failed->reference)->count())->toBe(0);

    // An admin lowers the fee in the meantime. The retry is the second answer
    // in the sequence above, so the provider accepts it.
    pf63Settings(['payout_processing_fee_kobo' => 1000]);

    app(PayoutSweepService::class)->sweep();

    $retried = pf63Payouts($business)->firstWhere('status', Payout::STATUS_PROCESSING);

    expect($retried)->not->toBeNull()
        ->and((int) $retried->amount_kobo)->toBe(340000)
        // A fresh reservation, at the policy current when it was made.
        ->and($retried->feeKobo())->toBe(1000)
        ->and($retried->netKobo())->toBe(339000)
        ->and((int) $business->fresh()->available_balance)->toBe(0);
});

test('both sets of books split the fee, and a reversal takes it back', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
        'payout_processing_fee_kobo' => 5000,
    ]);

    [$business, $store] = pf63Business();
    pf63Provider();

    pf63Collect($business, 340000, $store);

    $payout = pf63Payouts($business)->sole();

    // One fake for both answers, in order: the transfer lands, then the
    // provider recalls it. A second `Http::fake()` would only add a stub beside
    // this one, and the first answer would keep winning.
    Http::fakeSequence('sandbox-api.bachs.io/v1/payouts/pay_1')
        ->push(['id' => 'pay_1', 'status' => 'completed'])
        ->push(['id' => 'pay_1', 'status' => 'reversed']);

    pf63Reconcile($payout);

    $businessEntry = JournalEntry::query()
        ->where('idempotency_key', 'payout:'.$payout->reference)
        ->firstOrFail();
    $platformEntry = JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'custody_payout:'.$payout->reference)
        ->firstOrFail();

    // The business: its bank got the net, its Processing Fee carries the cost,
    // and its money is no longer with us by the gross.
    expect(pf63Lines($businessEntry))->toBe([
        '1020:dr' => 335000,
        '5500:dr' => 5000,
        '1040:cr' => 340000,
    ]);

    // Storify: the liability falls by the gross, clearing falls only by the net,
    // and the difference is income — which keeps 1030 = 2300 + 4020 − 5510.
    expect(pf63Lines($platformEntry))->toBe([
        '2300:dr' => 340000,
        '1030:cr' => 335000,
        '4020:cr' => 5000,
    ]);

    // The provider recalls it. Reversing by key reverses every line, the fee
    // included: a payout that came back was not a payout the business paid to
    // make, and the wallet is restored the gross it was debited.
    pf63Reconcile($payout);
    $payout->refresh();

    expect($payout->status)->toBe(Payout::STATUS_REVERSED)
        ->and((int) $business->fresh()->available_balance)->toBe(340000)
        ->and($businessEntry->fresh()->status)->toBe(JournalEntry::STATUS_VOID)
        ->and($platformEntry->fresh()->status)->toBe(JournalEntry::STATUS_VOID);

    $businessReturned = JournalEntry::query()
        ->where('idempotency_key', 'payout_returned:'.$payout->reference)
        ->firstOrFail();
    $platformReturned = JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'custody_payout_returned:'.$payout->reference)
        ->firstOrFail();

    expect(pf63Lines($businessReturned))->toBe([
        '1020:cr' => 335000,
        '5500:cr' => 5000,
        '1040:dr' => 340000,
    ])->and(pf63Lines($platformReturned))->toBe([
        '2300:cr' => 340000,
        '1030:dr' => 335000,
        '4020:dr' => 5000,
    ]);
});

test('a balance the fee would swallow never sends', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 0,
        'payout_processing_fee_kobo' => 10000,
    ]);

    [$business, $store] = pf63Business();
    pf63Provider();

    pf63Collect($business, 6000, $store);

    // A transfer that would send nothing is not a transfer. The money stays
    // held, and the wallet says which policy is holding it.
    expect(pf63Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->available_balance)->toBe(6000);

    $summary = app(WalletSummaryService::class)->forBusiness($business->fresh());

    expect($summary['fee_blocked_kobo'])->toBe(6000)
        ->and($summary['sendable_kobo'])->toBe(0)
        ->and($summary['below_minimum_kobo'])->toBe(0);

    // And nothing treats it as a failed send.
    Mail::fake();

    (new SettlePayouts((int) $business->id))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
        app(PayoutSweepService::class),
    );

    expect(pf63Payouts($business))->toHaveCount(0);
    Mail::assertNothingSent();

    // Lowering the fee makes it sendable, so the save wakes the same job a hold
    // lift does.
    $admin = pf63SuperAdmin();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('pf63-admin', ['admin'], now()->addHour())->plainTextToken];

    Queue::fake();

    $this->putJson('/api/v1/admin/settings', pf63SettingsPayload([
        'minimum_payout_kobo' => 0,
        'payout_processing_fee_kobo' => 1000,
    ]), $headers)->assertOk();

    Queue::assertPushed(SettlePayouts::class, fn (SettlePayouts $job) => $job->businessId === (int) $business->id);
});

test('payout payloads carry the fee and the net', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
        'payout_processing_fee_kobo' => 5000,
    ]);

    [$business, $store, , , $owner] = pf63Business();
    pf63Provider();

    pf63Collect($business, 340000, $store);

    $token = $owner->createToken('pf63-owner', ['management'], now()->addHour())->plainTextToken;

    $list = $this->withToken($token)
        ->getJson('/api/v1/management/payouts')
        ->assertOk()
        ->json('data');

    expect($list[0]['amount_kobo'])->toBe(340000)
        ->and($list[0]['fee_kobo'])->toBe(5000)
        ->and($list[0]['net_kobo'])->toBe(335000)
        ->and($list[0]['sales_count'])->toBe(1);

    $detail = $this->withToken($token)
        ->getJson('/api/v1/management/payouts/'.$list[0]['reference'])
        ->assertOk()
        ->json('data.payout');

    expect($detail['fee_kobo'])->toBe(5000)
        ->and($detail['net_kobo'])->toBe(335000)
        ->and($detail['sales_count'])->toBe(count($detail['allocations']))
        ->and($detail['sales_count'])->toBe(1);

    // The operator's console reads the same split, so a support call about the
    // difference between the payout and the bank statement has one answer.
    $admin = pf63Admin();
    $adminToken = $admin->createToken('pf63-admin-finance', ['admin'], now()->addHour())->plainTextToken;

    $recent = $this->withToken($adminToken)
        ->getJson('/api/v1/admin/businesses/'.$business->business_code.'/wallet')
        ->assertOk()
        ->json('data.payouts');

    expect($recent[0]['amount_kobo'])->toBe(340000)
        ->and($recent[0]['fee_kobo'])->toBe(5000)
        ->and($recent[0]['net_kobo'])->toBe(335000)
        ->and($recent[0]['sales_count'])->toBe(1);
});

test('the wallet reports what has actually reached the bank, net of the fee', function () {
    pf63Platform();
    pf63Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
        'payout_processing_fee_kobo' => 5000,
    ]);

    [$business, $store, , , $owner] = pf63Business();
    pf63Provider();

    pf63Collect($business, 340000, $store);

    $token = $owner->createToken('pf63-owner-lifetime', ['management'], now()->addHour())->plainTextToken;

    // A transfer out with the provider has not arrived, so nothing counts yet.
    $wallet = $this->withToken($token)->getJson('/api/v1/management/wallet')->assertOk()->json('data.wallet');

    expect($wallet['total_paid_kobo'])->toBe(0);

    $payout = pf63Payouts($business)->sole();

    Http::fakeSequence('sandbox-api.bachs.io/v1/payouts/pay_1')
        ->push(['id' => 'pay_1', 'status' => 'completed']);

    pf63Reconcile($payout);

    $wallet = $this->withToken($token)->getJson('/api/v1/management/wallet')->assertOk()->json('data.wallet');

    // Net, not gross: the fee never reached the bank, so it is not money the
    // business has been paid.
    expect($wallet['total_paid_kobo'])->toBe(335000)
        ->and((float) $wallet['total_paid'])->toEqualWithDelta(3350.0, 0.001);

    // A reversal takes it back out. The money reached the account and returned,
    // so the business has not been paid it — and the wallet already has it.
    Http::fakeSequence('sandbox-api.bachs.io/v1/payouts/pay_1')
        ->push(['id' => 'pay_1', 'status' => 'reversed']);

    pf63Reconcile($payout->fresh());

    $wallet = $this->withToken($token)->getJson('/api/v1/management/wallet')->assertOk()->json('data.wallet');

    expect($wallet['total_paid_kobo'])->toBe(0);
});
