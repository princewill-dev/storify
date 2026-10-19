<?php

use App\Enums\TransactionStatus;
use App\Jobs\SettlePayouts;
use App\Models\Business;
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
| WS-62 — money waits in the wallet until it is worth sending.
|
| Every collected sale used to be paid out on its own, so a ₦400 sale bought a
| provider transfer that cost more than it moved. The platform now has a floor:
| a destination's balance stays in the wallet until it reaches the configured
| minimum, and then the whole accumulated balance goes in **one** transfer drawn
| from every sale behind it.
|
| Three properties are asserted here, and each is a way the feature can look
| right and be wrong:
|
| **Waiting is not a stall.** A balance below the floor must not spend the retry
| budget, alert an operator, or read as "being sent" anywhere. The send's own
| `sendable_kobo` is what separates owed from sendable, and the job reads it.
|
| **The floor is per transfer, therefore per destination.** Two payout accounts
| do not fund each other's threshold; each is a transfer with its own cost.
|
| **Lowering the floor releases what it held.** Nothing else would wake held
| money until the business's next sale, which on a quiet shop may be days, so
| the settings save dispatches the same settlement job a hold lift does.
|
| The fee that rides alongside this policy is in `ws63payoutfeeTest.php`.
*/

function mp62Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function mp62Platform(): void
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
 * `trial_ends_at` because the management payout routes sit behind the
 * subscription gate: without it the payload tests below are 403s from a
 * middleware that has nothing to do with payouts.
 *
 * @return array{0: Business, 1: Store, 2: Store, 3: StoreBank, 4: User}
 */
function mp62Business(): array
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
            'name' => 'MP62 Store '.$index,
            'slug' => 'mp62-'.$index.'-'.fake()->unique()->numerify('######'),
            'status' => Store::STATUS_ACTIVE,
            'payment_mode' => 'manual',
        ]);
    }

    return [$business->fresh(), $stores[0], $stores[1], $bank, $owner];
}

/**
 * A confirmed managed sale whose share is exactly `$kobo`. Zero fees, so the
 * net is the gross and every figure a test asserts is the number it chose.
 */
function mp62Sale(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'MP62-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'API_MP62_'.strtoupper(Str::random(10)),
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

/** Credit a sale to the wallet and offer to pay it — the whole path. */
function mp62Collect(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = mp62Sale($business, $kobo, $store);

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    app(InstantPayoutService::class)->afterCollection($sale);

    return $sale->fresh();
}

/** Bachs, with a spendable balance the template can dial. */
function mp62Provider(int $availableKobo = 50000000): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'account_id' => 'org_mp62',
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => number_format($availableKobo / 100, 2, '.', ''),
                'pending_balance' => '0.00',
            ]],
            'pending_settlements_by_day' => [],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'processing']),
    ]);
}

/**
 * The body of the one request the provider received at `$path`, or [].
 *
 * `$exact` compares the whole path: `/v1/payouts` is a prefix of the
 * destinations call and of the transfer lookup, so the loose form would hand
 * back the wrong request's body.
 */
function mp62Sent(string $path, bool $exact = false): array
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
function mp62Payouts(?Business $business = null): Collection
{
    return Payout::query()
        ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
        ->orderBy('id')
        ->get();
}

function mp62Token(User $user, string $audience = 'management'): string
{
    return $user->createToken('mp62-access', [$audience], now()->addHour())->plainTextToken;
}

/** A platform admin holding `$roleName` and nothing else. */
function mp62Admin(string $role = 'Finance Admin'): User
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

/** A superadmin, who may save platform settings. */
function mp62SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

/**
 * The settings form as the admin screen posts it, at its defaults. The sparse
 * keys are the ones `SettingsService` reads without a fallback.
 */
function mp62SettingsPayload(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Storify',
        'trial_enabled' => true,
        'trial_days' => 7,
        'greeting_modal_enabled' => false,
        'greeting_modal_frequency' => 'never',
    ], $overrides);
}

/* ------------------------------------------------------------- the floor */

test('a balance below the minimum waits in the wallet', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);

    [$business, $store] = mp62Business();
    mp62Provider();

    mp62Collect($business, 100000, $store);

    // Nothing was sent, and nothing was lost: the money is still in the wallet,
    // and it is mature rather than waiting on the provider.
    expect(mp62Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->available_balance)->toBe(100000)
        ->and((int) $business->fresh()->pending_balance)->toBe(0);

    // The read model says both things at once — owed, and not sendable — so no
    // screen has to guess which of the two it is looking at.
    $summary = app(WalletSummaryService::class)->forBusiness($business->fresh());

    expect($summary['payable_kobo'])->toBe(100000)
        ->and($summary['sendable_kobo'])->toBe(0)
        ->and($summary['below_minimum_kobo'])->toBe(100000)
        ->and($summary['minimum_payout_kobo'])->toBe(300000);
});

test('money accumulates and one payout carries every sale', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);

    [$business, $store] = mp62Business();
    mp62Provider();

    $first = mp62Collect($business, 100000, $store);
    $second = mp62Collect($business, 40000, $store);

    // Two collected sales, two holds, and no transfer anywhere.
    expect(mp62Payouts($business))->toHaveCount(0);

    $third = mp62Collect($business, 1200000, $store);

    // The third sale crossed the floor, and the transfer is the whole balance —
    // not the sale that crossed it.
    $payout = mp62Payouts($business)->sole();

    expect((int) $payout->amount_kobo)->toBe(1340000)
        ->and($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->allocations()->count())->toBe(3)
        // Every earlier sale is in the same transfer, oldest first.
        ->and($payout->allocations()->orderBy('id')->pluck('transaction_id')->all())
        ->toBe([(int) $first->id, (int) $second->id, (int) $third->id])
        // The wallet is empty because the money is either with the business or
        // on its way; the allocations add up to the gross.
        ->and((int) $business->fresh()->available_balance)->toBe(0)
        ->and((int) $payout->allocations()->sum('amount_kobo'))->toBe(1340000);
});

test('exactly the minimum is enough to send', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);

    [$business, $store] = mp62Business();
    mp62Provider();

    mp62Collect($business, 300000, $store);

    $payout = mp62Payouts($business)->sole();

    expect((int) $payout->amount_kobo)->toBe(300000)
        // The rail was asked for the whole gross; no fee is configured here.
        ->and(mp62Sent('/v1/payouts', exact: true)['amount'])->toBe('3000.00');
});

test('zero disables the floor and pays as before', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 0,
    ]);

    [$business, $store] = mp62Business();
    mp62Provider();

    mp62Collect($business, 100000, $store);

    $payout = mp62Payouts($business)->sole();

    expect((int) $payout->amount_kobo)->toBe(100000)
        ->and((int) $business->fresh()->available_balance)->toBe(0);
});

test('a settlement run leaves below-minimum money alone', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);

    [$business, $store] = mp62Business();
    mp62Provider();

    mp62Collect($business, 100000, $store);

    Mail::fake();

    // The job the sweep dispatches. Running it by hand is what a worker would
    // do; the assertion is that it decides there is nothing to send, rather
    // than treating a deliberate hold as a send that failed.
    (new SettlePayouts((int) $business->id))->handle(
        app(InstantPayoutService::class),
        app(WalletSummaryService::class),
        app(PayoutSweepService::class),
    );

    expect(mp62Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->available_balance)->toBe(100000);

    // No operator was told about money nobody was trying to move.
    Mail::assertNothingSent();
});

test('the floor is per destination, not per business', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);
    config(['custody.per_store_wallets' => true]);

    [$business, $first, $second] = mp62Business();
    mp62Provider();

    // Each store is a transfer with its own provider cost, so each gets its
    // own destination account.
    $firstBank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Access Bank',
        'bank_code' => '044',
        'account_number' => '1111111111',
        'account_name' => 'Ada Trading First',
        'is_primary' => false,
    ]);
    $secondBank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Zenith Bank',
        'bank_code' => '057',
        'account_number' => '2222222222',
        'account_name' => 'Ada Trading Second',
        'is_primary' => false,
    ]);

    $first->forceFill(['payout_bank_id' => $firstBank->id])->save();
    $second->forceFill(['payout_bank_id' => $secondBank->id])->save();

    // The first store is below its floor and holds; the second reaches its own
    // and is paid — only its own money, because one account's takings must not
    // fund another account's threshold.
    mp62Collect($business, 100000, $first);
    mp62Collect($business, 400000, $second);

    $payout = mp62Payouts($business)->sole();

    expect((int) $payout->amount_kobo)->toBe(400000)
        ->and($payout->account_number)->toBe('2222222222')
        ->and((int) $first->fresh()->available_balance)->toBe(100000)
        ->and((int) $second->fresh()->available_balance)->toBe(0);

    $summary = app(WalletSummaryService::class)->forBusiness($business->fresh());

    // The second account's money has gone; what is left owed is the first
    // store's below-floor balance, and none of it is sendable.
    expect($summary['payable_kobo'])->toBe(100000)
        ->and($summary['sendable_kobo'])->toBe(0)
        ->and($summary['below_minimum_kobo'])->toBe(100000);
});

test('lowering the minimum releases held money, and raising it does not', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);

    [$business, $store] = mp62Business();
    mp62Provider();

    mp62Collect($business, 100000, $store);

    expect(mp62Payouts($business))->toHaveCount(0);

    $admin = mp62SuperAdmin();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('mp62-admin', ['admin'], now()->addHour())->plainTextToken];

    // Lowered below what is already held: the save has to wake the settlement
    // job, because nothing else will until this business's next sale.
    Queue::fake();

    $this->putJson('/api/v1/admin/settings', mp62SettingsPayload([
        'minimum_payout_kobo' => 50000,
        'payout_processing_fee_kobo' => 0,
    ]), $headers)->assertOk();

    Queue::assertPushed(SettlePayouts::class, fn (SettlePayouts $job) => $job->businessId === (int) $business->id);

    // Raised again: money that was sendable a moment ago is now held, and
    // there is nothing to release — a second wake-up would be a job that can
    // only find the same floor it just read.
    Queue::fake();

    $this->putJson('/api/v1/admin/settings', mp62SettingsPayload([
        'minimum_payout_kobo' => 900000,
        'payout_processing_fee_kobo' => 0,
    ]), $headers)->assertOk();

    Queue::assertNothingPushed();
});

/* --------------------------------------------------------------- surfaces */

test('payout payloads say which payouts carried several sales', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);

    [$business, $store, , , $owner] = mp62Business();
    mp62Provider();

    mp62Collect($business, 100000, $store);
    mp62Collect($business, 40000, $store);
    mp62Collect($business, 1200000, $store);

    // The business's own list: one amount, three sales, and no fee configured.
    $list = $this->withToken(mp62Token($owner))
        ->getJson('/api/v1/management/payouts')
        ->assertOk()
        ->json('data');

    expect($list)->toHaveCount(1)
        ->and($list[0]['sales_count'])->toBe(3)
        ->and($list[0]['amount_kobo'])->toBe(1340000)
        ->and($list[0]['fee_kobo'])->toBe(0)
        ->and($list[0]['net_kobo'])->toBe(1340000);

    // The detail's count is the allocations it already sent, and agrees.
    $detail = $this->withToken(mp62Token($owner))
        ->getJson('/api/v1/management/payouts/'.$list[0]['reference'])
        ->assertOk()
        ->json('data.payout');

    expect($detail['sales_count'])->toBe(3)
        ->and($detail['allocations'])->toHaveCount(3);

    // The operator's console reads the same transfer.
    $admin = mp62Admin();
    $adminToken = $admin->createToken('mp62-admin-finance', ['admin'], now()->addHour())->plainTextToken;

    $wallet = $this->withToken($adminToken)
        ->getJson('/api/v1/admin/businesses/'.$business->business_code.'/wallet')
        ->assertOk()
        ->json('data');

    expect($wallet['payouts'])->toHaveCount(1)
        ->and($wallet['payouts'][0]['sales_count'])->toBe(3)
        ->and($wallet['payouts'][0]['fee_kobo'])->toBe(0)
        ->and($wallet['payouts'][0]['net_kobo'])->toBe(1340000)
        // The operator's read model is the business's own block, including the
        // policy figures, so a support call is answered from one set of numbers.
        ->and($wallet['wallet']['minimum_payout_kobo'])->toBe(300000)
        ->and($wallet['wallet']['sendable_kobo'])->toBe(0);
});

test('the platform queue separates owed from sendable', function () {
    mp62Platform();
    mp62Settings([
        'system_payment_processor' => 'bachs',
        'minimum_payout_kobo' => 300000,
    ]);

    [$business, $store] = mp62Business();
    mp62Provider();

    mp62Collect($business, 100000, $store);

    $queue = app(WalletSummaryService::class)->platformQueue();
    $row = collect($queue['businesses'])->firstWhere('id', (int) $business->id);

    expect($row)->not->toBeNull()
        ->and($row['payable_kobo'])->toBe(100000)
        ->and($row['sendable_kobo'])->toBe(0)
        ->and($row['below_minimum_kobo'])->toBe(100000)
        ->and($queue['totals']['payable_kobo'])->toBe(100000)
        ->and($queue['totals']['sendable_kobo'])->toBe(0)
        ->and($queue['totals']['below_minimum_kobo'])->toBe(100000)
        ->and($queue['totals']['minimum_payout_kobo'])->toBe(300000);
});
