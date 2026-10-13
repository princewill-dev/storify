<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\BusinessNotification;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PayoutHold;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutHoldService;
use App\Services\Payments\Custody\PayoutSweepService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-49 — the API surface for the custody model.
|
| Everything the money model records was, until this, invisible from the product:
| a business had no wallet endpoint, no payout list, no way to see which transfer
| carried which sale, and no route anywhere that could stop a payout. The figures
| did exist — `businesses.pending_balance` / `available_balance`, `settle_at`,
| `promoted_at`, `payout_allocations`, `payout_holds` — behind screens that
| rendered `stores.balance`, a column a collected sale never credits.
|
| Two properties are asserted in almost every test here, because both are ways the
| surface can be wrong while looking right:
|
| **It says which figure is true.** `balance_source` is on every store payload,
| because a business charged on its own provider account has real money in
| `stores.balance` while one under custody has money in the wallet instead, and a
| client that renders one number for both is wrong for one of them.
|
| **It cannot move money.** Every route here is a read, or a hold — and a hold is a
| stop. The sweep is still the only thing that pays. That is why the wallet tests
| assert figures *before* the payout and the payout tests assert them after: the
| API reports what the money model did, and never does it.
*/

function w49Token(User $user, string $audience = 'management'): string
{
    return $user->createToken('w49-access', [$audience], now()->addHour())->plainTextToken;
}

function w49Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function w49Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);
}

/**
 * A business under custody with a payout bank on file, plus one store.
 *
 * @return array{0: User, 1: Business, 2: Store}
 */
function w49Business(bool $custody = true): array
{
    // `trial_ends_at` because the management routes sit behind the subscription
    // gate: without it every request in this file is a 403 from
    // EnsureManagementSubscription and nothing here would be about the wallet.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['auto_payout' => true]);

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
    ]);

    $business->forceFill([
        'custody_enabled' => $custody,
        'custody_enabled_at' => now(),
        'payout_bank_id' => $bank->id,
    ])->save();

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'w49-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    return [$owner, $business->fresh(), $store];
}

function w49Sale(Business $business, int $kobo, ?Store $store = null, bool $platformManaged = true): Transaction
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
        // Zero fees, so the sale's net is exactly its amount and every figure in
        // these tests is an integer a person can check by hand.
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        // The `payment_mode` marker is how a *past* charge records that Storify
        // collected it. Absent means the business's own provider account carried the
        // sale, which is a sale with no wallet leg at all.
        'metadata' => $platformManaged ? ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'] : [],
    ]);
}

/** A sale that has been collected and matured, so its share is payable. */
function w49Funded(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = w49Sale($business, $kobo, $store);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($sale, 'bachs');
    // With no budget passed, promotion is decided by the settlement window alone —
    // which is the branch an unreadable provider balance falls back to. These tests
    // are about what the API reports, not about the gate, so the money matures on
    // the window and the gate has its own suite.
    $custody->promoteSettled();

    return $sale->fresh();
}

function w49ProviderAccepts(): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
}

function w49Admin(string $role = 'Finance Admin'): User
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

// ---------------------------------------------------------------------------
// The wallet
// ---------------------------------------------------------------------------

test('the wallet reports what is settling, what is payable and where it is going', function () {
    w49Platform();

    [$owner, $business, $store] = w49Business();
    w49Funded($business, 100000, $store);

    $response = $this->withToken(w49Token($owner))->getJson('/api/v1/management/wallet');

    $response->assertOk();

    $wallet = $response->json('data.wallet');

    // Promotion moves the money across the wallet, so the whole of it is payable
    // and none of it is still settling. Both figures are sent in kobo as well as
    // formatted, because the SPA does arithmetic with the integer.
    expect($wallet['pending_kobo'])->toBe(0)
        ->and($wallet['available_kobo'])->toBe(100000)
        ->and($wallet['balance_kobo'])->toBe(100000)
        ->and($wallet['payable_kobo'])->toBe(100000)
        ->and($wallet['withheld_kobo'])->toBe(0)
        ->and($wallet['unattributed_kobo'])->toBe(0)
        ->and($wallet['balance'])->toEqual(1000.0)
        ->and($wallet['payable'])->toEqual(1000.0)
        // Money is in the wallet rather than in `stores.balance`, and the payload
        // says so — the flag the SPA picks its figure from.
        ->and($wallet['balance_source'])->toBe('wallet')
        ->and($wallet['custody_enabled'])->toBeTrue()
        ->and($wallet['auto_payout'])->toBeTrue()
        // Nothing is stopping it, which is a different statement from "there is
        // nothing to send" and is why this is null rather than an empty string.
        ->and($wallet['blocker'])->toBeNull()
        ->and($wallet['hold'])->toBeNull();

    // The per-store breakdown is the same walk, grouped — so it cannot sum to more
    // than the payable figure it is drawn from.
    expect($wallet['stores'])->toHaveCount(1)
        ->and($wallet['stores'][0]['id'])->toBe((int) $store->id)
        ->and($wallet['stores'][0]['attributed_kobo'])->toBe(100000)
        ->and($wallet['stores'][0]['held'])->toBeFalse();

    // Where it is going, on the same screen that says how much: masked, because
    // this is a display payload and the full number belongs to the screen that
    // owns the bank account.
    expect($response->json('data.destination.configured'))->toBeTrue()
        ->and($response->json('data.destination.bank_code'))->toBe('058')
        ->and($response->json('data.destination.bank_name'))->toBe('Guaranty Trust Bank')
        ->and($response->json('data.destination.account_name'))->toBe('Ada Trading Ltd')
        // The model's own accessor, which every other management payload that shows
        // a bank account reads — one masking rule, not a second one here.
        ->and($response->json('data.destination.masked_account_number'))->toBe('012*****89');
});

test('a store hold withholds that store\'s share and still reports the rest as payable', function () {
    w49Platform();

    [$owner, $business, $store] = w49Business();

    $second = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Second Store',
        'slug' => 'w49-second'.fake()->unique()->numerify('####'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    w49Funded($business, 60000, $store);
    w49Funded($business, 40000, $second);

    // Held through the service rather than the admin route, which has its own
    // test: what this one is about is what a business is shown.
    app(PayoutHoldService::class)->holdStore($store, 'Orders under review');

    $wallet = $this->withToken(w49Token($owner))
        ->getJson('/api/v1/management/wallet')
        ->assertOk()
        ->json('data.wallet');

    // The held store's 600.00 is not sent and is named as withheld; the other
    // store's 400.00 is paid on the next run, which is the agreed behaviour: a
    // dispute about one store does not punish sales that have nothing to do with it.
    expect($wallet['payable_kobo'])->toBe(40000)
        ->and($wallet['withheld_kobo'])->toBe(60000)
        ->and($wallet['available_kobo'])->toBe(100000)
        // A store hold is not a business-level blocker: the business is not
        // stopped, it is paid the rest.
        ->and($wallet['blocker'])->toBeNull();

    $byStore = collect($wallet['stores'])->keyBy('id');

    // The held store's block reads zero attributed *and* names what it is being
    // kept from. One without the other is the failure this pair of keys exists to
    // prevent: `attributed: 0` alone is indistinguishable from a store that has
    // simply sold nothing, and the money it is being held back from would then be
    // visible nowhere on its own screen.
    expect($byStore[$store->id]['held'])->toBeTrue()
        ->and($byStore[$store->id]['hold_reason'])->toBe('Orders under review')
        ->and($byStore[$store->id]['attributed_kobo'])->toBe(0)
        ->and($byStore[$store->id]['withheld_kobo'])->toBe(60000)
        ->and($byStore[$store->id]['withheld'])->toEqual(600.0)
        ->and($byStore[$second->id]['held'])->toBeFalse()
        ->and($byStore[$second->id]['attributed_kobo'])->toBe(40000)
        ->and($byStore[$second->id]['withheld_kobo'])->toBe(0);

    // The per-store withheld figures are the business-level one, split — so a
    // business reading its own screen cannot find more withheld across its stores
    // than the wallet says is being withheld in total.
    expect(array_sum(array_column($wallet['stores'], 'withheld_kobo')))->toBe($wallet['withheld_kobo']);
});

test('a business with no payout bank is told why rather than shown a stuck figure', function () {
    w49Platform();

    [$owner, $business, $store] = w49Business();
    w49Funded($business, 50000, $store);

    $business->forceFill(['payout_bank_id' => null])->save();

    $response = $this->withToken(w49Token($owner))
        ->getJson('/api/v1/management/wallet')
        ->assertOk();

    $wallet = $response->json('data.wallet');

    // The label is the sweep's own, shared through the service, so the screen and
    // the run log cannot disagree about why the money is sitting still.
    expect($wallet['blocker'])->not->toBeNull()
        ->and($wallet['blocker']['code'])->toBe('no_bank')
        ->and($wallet['blocker']['label'])->toBe('no payout bank')
        // The money is still owed and still visible; it is the destination that is
        // missing, and the screen says so on both halves.
        ->and($wallet['available_kobo'])->toBe(50000)
        ->and($response->json('data.destination.configured'))->toBeFalse()
        ->and($response->json('data.destination.masked_account_number'))->toBeNull();
});

test('the wallet refuses an unauthenticated caller', function () {
    $this->getJson('/api/v1/management/wallet')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Store payloads: which figure is the real one
// ---------------------------------------------------------------------------

test('every store payload names which balance is the business\'s real money', function () {
    w49Platform();

    [$owner, $business, $store] = w49Business();
    w49Funded($business, 75000, $store);

    $token = w49Token($owner);

    // The list: the wallet block is computed once for the page, so the row carries
    // a slice of the same walk the wallet does.
    $row = $this->withToken($token)
        ->getJson('/api/v1/management/stores')
        ->assertOk()
        ->json('data.0');

    expect($row['balance_source'])->toBe('wallet')
        // The legacy column is still sent — a client mid-deploy must not have a
        // key vanish under it — but `balance_source` is what says not to trust it.
        ->and($row['balance'])->toBe(0)
        ->and($row['wallet']['attributed_kobo'])->toBe(75000)
        ->and($row['wallet']['business_available_kobo'])->toBe(75000);

    $show = $this->withToken($token)
        ->getJson('/api/v1/management/stores/'.$store->store_id)
        ->assertOk()
        ->json('data.store');

    expect($show['balance_source'])->toBe('wallet')
        ->and($show['wallet']['attributed_kobo'])->toBe(75000);

    // The store dashboard header — the third site that rendered the uncredited
    // column, and the one a business looks at most.
    $dashboard = $this->withToken($token)
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/dashboard')
        ->assertOk()
        ->json('data.store');

    expect($dashboard['balance_source'])->toBe('wallet')
        ->and($dashboard['wallet']['attributed_kobo'])->toBe(75000);

    // And the onboarding list, which carries the same store payload shape.
    $onboarding = $this->withToken($token)
        ->getJson('/api/v1/management/stores/onboarding/list')
        ->assertOk()
        ->json('data.stores.0');

    expect($onboarding['balance_source'])->toBe('wallet')
        ->and($onboarding['wallet']['attributed_kobo'])->toBe(75000);
});

test('a business charged on its own account is told its store balance is the real one', function () {
    [$owner, $business, $store] = w49Business(custody: false);

    $store->forceFill(['balance' => 4200])->save();

    $row = $this->withToken(w49Token($owner))
        ->getJson('/api/v1/management/stores')
        ->assertOk()
        ->json('data.0');

    // The opposite answer, for the opposite reason: this business's sales are
    // settled by the provider into its own bank, so `stores.balance` is money and
    // the wallet is empty. Both keys are always sent so a client can switch.
    expect($row['balance_source'])->toBe('store')
        ->and($row['balance'])->toBe(4200)
        ->and($row['wallet']['balance_source'])->toBe('store')
        ->and($row['wallet']['attributed_kobo'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Payouts, and the sales they were made of
// ---------------------------------------------------------------------------

test('the payout list and detail name the sales the transfer was made of', function () {
    w49Platform();
    w49Settings(['system_payment_processor' => 'bachs']);

    [$owner, $business, $store] = w49Business();

    $first = w49Funded($business, 60000, $store);
    $second = w49Funded($business, 40000, $store);

    w49ProviderAccepts();
    app(PayoutSweepService::class)->sweep();

    $payout = Payout::query()->where('business_id', $business->id)->sole();
    $token = w49Token($owner);

    $list = $this->withToken($token)
        ->getJson('/api/v1/management/payouts')
        ->assertOk()
        ->json('data.0');

    expect($list['reference'])->toBe($payout->reference)
        ->and($list['status'])->toBe(Payout::STATUS_PROCESSING)
        ->and($list['amount_kobo'])->toBe(100000)
        ->and($list['amount'])->toEqual(1000.0)
        ->and($list['account_number'])->toBe('0123456789')
        ->and($list['paid_at'])->toBeNull();

    // Addressed by the reference, which is the name this payout has in the
    // world — on the transfer, in the provider's dashboard, and in any link the
    // business is sent.
    $detail = $this->withToken($token)
        ->getJson('/api/v1/management/payouts/'.$payout->reference)
        ->assertOk()
        ->json('data.payout');

    expect($detail['sales_count'])->toBe(2)
        ->and($detail['allocations'])->toHaveCount(2);

    // And the id is no longer a way in, so the two names cannot disagree later.
    $this->withToken($token)
        ->getJson('/api/v1/management/payouts/'.$payout->id)
        ->assertNotFound();

    // Oldest sale first, and each line is that sale's own net — the answer the
    // `payouts` row alone cannot give, because a payout sends a pooled balance.
    $allocations = $detail['allocations'];

    expect($allocations[0]['transaction_id'])->toBe((int) $first->id)
        ->and($allocations[0]['reference'])->toBe($first->reference)
        ->and($allocations[0]['amount_kobo'])->toBe(60000)
        ->and($allocations[0]['store_name'])->toBe($store->name)
        ->and($allocations[1]['amount_kobo'])->toBe(40000)
        // The property that makes this an explanation rather than an approximation:
        // what the sales were worth is what was sent.
        ->and(array_sum(array_column($allocations, 'amount_kobo')))->toBe($payout->amount_kobo);
});

test('a business cannot read another business\'s payout', function () {
    w49Platform();
    w49Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = w49Business();
    w49Funded($business, 60000, $store);

    w49ProviderAccepts();
    app(PayoutSweepService::class)->sweep();

    $payout = Payout::query()->where('business_id', $business->id)->sole();

    [$outsider] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(w49Token($outsider))
        ->getJson('/api/v1/management/payouts/'.$payout->reference)
        ->assertForbidden();

    // And the list is scoped, not merely the detail: the row is not in it.
    $this->withToken(w49Token($outsider))
        ->getJson('/api/v1/management/payouts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the transaction list carries the settlement state and the payout that delivered the sale', function () {
    w49Platform();
    w49Settings(['system_payment_processor' => 'bachs']);

    [$owner, $business, $store] = w49Business();
    $sale = w49Funded($business, 50000, $store);

    // Before the payout: the sale has matured, so its money is payable and no
    // transfer has claimed it yet.
    $before = $this->withToken(w49Token($owner))
        ->getJson('/api/v1/management/transactions')
        ->assertOk()
        ->json('data.transactions.0');

    expect($before['settlement_state'])->toBe('payable')
        ->and($before['payout_reference'])->toBeNull()
        ->and($before['settlement']['state'])->toBe('payable')
        ->and($before['settlement']['payable_since'])->not->toBeNull()
        ->and($before['settlement']['payout'])->toBeNull();

    w49ProviderAccepts();
    app(PayoutSweepService::class)->sweep();

    $payout = Payout::query()->where('business_id', $business->id)->sole();

    $after = $this->withToken(w49Token($owner))
        ->getJson('/api/v1/management/transactions')
        ->assertOk()
        ->json('data.transactions.0');

    // Reserved and in flight — not `paid`, which is the difference the scan
    // between the two requests is for. The reference is on the row because the
    // question a business opens the list to ask is which transfer carried it.
    expect($after['id'])->toBe((int) $sale->id)
        ->and($after['settlement_state'])->toBe('payout_in_flight')
        ->and($after['payout_reference'])->toBe($payout->reference)
        ->and($after['settlement']['allocated_kobo'])->toBe(50000)
        ->and($after['settlement']['payout']['reference'])->toBe($payout->reference)
        ->and($after['settlement']['payout']['status'])->toBe(Payout::STATUS_PROCESSING);
});

test('a sale charged on the business\'s own account carries no settlement block, and an uncollected one a state without a payout', function () {
    [$owner, $business, $store] = w49Business(custody: false);

    // A sale the business's own provider account carried: no `payment_mode`
    // marker, so no wallet leg and nothing to describe.
    $own = w49Sale($business, 30000, $store, platformManaged: false);

    // And a managed sale whose webhook has not arrived yet — charged through
    // Storify, not yet credited. A real transient state, and the one a business
    // watching its wallet sees for the first few seconds after a sale.
    $uncollected = w49Sale($business, 20000, $store);

    $rows = collect($this->withToken(w49Token($owner))
        ->getJson('/api/v1/management/transactions')
        ->assertOk()
        ->json('data.transactions'))
        ->keyBy('reference');

    expect($rows[$own->reference]['settlement_state'])->toBeNull()
        // Null rather than a block full of nulls: there is no settlement to
        // describe, and an empty object would read as one that failed.
        ->and($rows[$own->reference]['settlement'])->toBeNull()
        ->and($rows[$own->reference]['payout_reference'])->toBeNull();

    // The managed one is distinguishable from it, which is the point of the
    // marker: `not_collected` says Storify is holding a claim it has not been paid
    // on, and it is not the same answer as "this sale has nothing to do with us".
    expect($rows[$uncollected->reference]['settlement_state'])->toBe('not_collected')
        ->and($rows[$uncollected->reference]['settlement']['state'])->toBe('not_collected')
        ->and($rows[$uncollected->reference]['settlement']['settled_at'])->toBeNull()
        ->and($rows[$uncollected->reference]['settlement']['payout'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Alerts
// ---------------------------------------------------------------------------

test('alerts are listed with an unread count and can be marked read', function () {
    w49Platform();

    [$owner, $business, $store] = w49Business();

    // Two real alerts, raised by the money model rather than written by hand: a
    // credit and a payout that landed.
    w49Funded($business, 50000, $store);

    $notice = BusinessNotification::query()
        ->where('business_id', $business->id)
        ->sole();

    $token = w49Token($owner);

    $list = $this->withToken($token)
        ->getJson('/api/v1/management/notifications')
        ->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.id'))->toBe((int) $notice->id)
        ->and($list->json('data.0.type'))->toBe(BusinessNotification::TYPE_WALLET_CREDITED)
        ->and($list->json('data.0.read'))->toBeFalse()
        ->and($list->json('data.0.read_at'))->toBeNull()
        // The title the business reads, and the amount in kobo beside it so a
        // client can format it in whatever currency the business is set to.
        ->and($list->json('data.0.amount_kobo'))->toBe(50000)
        ->and($list->json('data.0.currency'))->toBe('NGN')
        ->and($list->json('data.0.subject_type'))->toBe(Transaction::class)
        ->and($list->json('meta.unread_count'))->toBe(1);

    // The badge count is a separate query, not a sum over the page, so it is right
    // on every page rather than only the first.
    $read = $this->withToken($token)
        ->postJson('/api/v1/management/notifications/'.$notice->id.'/read')
        ->assertOk();

    expect($read->json('data.notification.read'))->toBeTrue()
        ->and($read->json('data.notification.read_at'))->not->toBeNull()
        ->and($read->json('data.unread_count'))->toBe(0);

    expect($notice->fresh()->read_at)->not->toBeNull();

    // Idempotent: it is read now, which is what the caller asked for either way.
    $again = $this->withToken($token)
        ->postJson('/api/v1/management/notifications/'.$notice->id.'/read')
        ->assertOk();

    expect($again->json('data.notification.read'))->toBeTrue();

    // And the whole set, including alerts written since the client fetched its
    // page — which is why this is one statement rather than a loop over ids.
    $fresh = w49Funded($business, 20000, $store);

    expect(BusinessNotification::query()->where('business_id', $business->id)->count())->toBe(2);

    $all = $this->withToken($token)
        ->postJson('/api/v1/management/notifications/read')
        ->assertOk();

    expect($all->json('data.marked_read'))->toBe(1)
        ->and($all->json('data.unread_count'))->toBe(0)
        ->and(BusinessNotification::query()->where('business_id', $business->id)->whereNull('read_at')->count())->toBe(0);

    // The newly written alert is still a readable record; marking it read is not
    // the same as dismissing it.
    $this->withToken($token)
        ->getJson('/api/v1/management/notifications?unread_only=1')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($fresh)->not->toBeNull();
});

test('an alert belonging to another business cannot be read', function () {
    w49Platform();

    [, $business, $store] = w49Business();
    w49Funded($business, 50000, $store);

    $notice = BusinessNotification::query()->where('business_id', $business->id)->sole();

    [$outsider] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // 404 rather than 403: the existence of the row is itself the other
    // business's information, and a 403 confirms an id.
    $this->withToken(w49Token($outsider))
        ->postJson('/api/v1/management/notifications/'.$notice->id.'/read')
        ->assertNotFound();

    // And the outsider's own list does not contain it.
    $this->withToken(w49Token($outsider))
        ->getJson('/api/v1/management/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('alerts need no special permission, and are scoped by tenancy instead', function () {
    w49Platform();

    [, $business, $store] = w49Business();
    w49Funded($business, 50000, $store);

    // A Store Associate holds dashboard, products, pos, orders and customers —
    // and no money permission at all.
    $staff = User::factory()->create([
        'role' => 'staff',
        'business_id' => $business->id,
        'status' => 'active',
        'is_verified' => true,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    // The wallet is business-level money and refuses them...
    $this->withToken(w49Token($staff))
        ->getJson('/api/v1/management/wallet')
        ->assertForbidden();

    // ...but an alert is addressed to a person: a staff member holding the money
    // still has to be told it arrived.
    $this->withToken(w49Token($staff))
        ->getJson('/api/v1/management/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Admin: the queue, and the holds that stop it
// ---------------------------------------------------------------------------

test('an admin can see the payout queue and why a business cannot take its money', function () {
    w49Platform();

    [, $business, $store] = w49Business();
    w49Funded($business, 90000, $store);

    $admin = w49Admin();

    $queue = $this->withToken(w49Token($admin, 'admin'))
        ->getJson('/api/v1/admin/payout-queue')
        ->assertOk()
        ->json('data.queue');

    expect($queue['totals']['payable_kobo'])->toBe(90000)
        ->and($queue['totals']['stuck_kobo'])->toBe(0)
        ->and($queue['totals']['currency'])->toBe('NGN')
        ->and($queue['holds'])->toBe([]);

    $row = collect($queue['businesses'])->firstWhere('id', (int) $business->id);

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe($business->name)
        ->and($row['available_kobo'])->toBe(90000)
        ->and($row['payable_kobo'])->toBe(90000)
        ->and($row['blocker'])->toBeNull()
        ->and($row['destination_failures'])->toBe(0);
});

test('holding a business stops its payout, shows on the queue with the reason, and releases', function () {
    w49Platform();

    [, $business, $store] = w49Business();
    w49Funded($business, 90000, $store);

    $admin = w49Admin();
    $token = w49Token($admin, 'admin');

    $placed = $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', [
            'scope' => 'business',
            'business_id' => $business->id,
            'reason' => 'Chargeback investigation, ticket 8812',
        ])
        ->assertCreated();

    $holdId = $placed->json('data.hold.id');

    expect($placed->json('data.hold.scope'))->toBe('business')
        ->and($placed->json('data.hold.source'))->toBe(PayoutHold::SOURCE_MANUAL)
        ->and($placed->json('data.hold.reason'))->toBe('Chargeback investigation, ticket 8812')
        ->and($placed->json('data.hold.business_name'))->toBe($business->name)
        // `over` is the console row's wording, taken from the model so the API,
        // the sweep's run log and the business's own wallet payload cannot describe
        // one hold three ways. The name is a separate key beside it, because an id
        // is the right thing to log and the wrong thing to render.
        ->and($placed->json('data.hold.over'))->toBe('business '.$business->id)
        ->and($placed->json('data.hold.placed_by'))->toBe((int) $admin->id);

    // Placing it twice is one hold: two admins looking at the same incident is not
    // a rare event, and the second press is not a second decision.
    $again = $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', [
            'scope' => 'business',
            'business_id' => $business->id,
            'reason' => 'Same investigation, second look',
        ])
        ->assertOk();

    expect($again->json('data.hold.id'))->toBe($holdId)
        ->and(PayoutHold::query()->where('business_id', $business->id)->count())->toBe(1);

    // The list an operator opens during an incident.
    $this->withToken($token)
        ->getJson('/api/v1/admin/payout-holds')
        ->assertOk()
        ->assertJsonCount(1, 'data.holds')
        ->assertJsonPath('data.holds.0.id', $holdId);

    // And the queue the same screen shows: the money is still owed, still sitting
    // in the wallet, and the reason it is not moving is on the row.
    $queue = $this->withToken($token)->getJson('/api/v1/admin/payout-queue')->assertOk()->json('data.queue');
    $row = collect($queue['businesses'])->firstWhere('id', (int) $business->id);

    expect($row['available_kobo'])->toBe(90000)
        ->and($row['payable_kobo'])->toBe(0)
        ->and($row['withheld_kobo'])->toBe(0)
        ->and($row['blocker']['code'])->toBe('hold')
        // The sweep's own label — reason and source — rather than a second phrasing
        // invented for this screen. `source` is on it because a subject can carry
        // two holds, and saying which one is what tells an operator whether
        // reactivating the business will lift it.
        ->and($row['blocker']['label'])->toBe('on hold: Chargeback investigation, ticket 8812 (manual)')
        ->and($queue['totals']['stuck_kobo'])->toBe(90000)
        ->and($queue['holds'])->toHaveCount(1);

    // A hold stops money; it never moves it. The wallet is untouched, which is
    // what makes lifting it a no-op rather than a reversal.
    expect($business->fresh()->available_balance)->toBe(90000);

    $this->withToken($token)
        ->deleteJson('/api/v1/admin/payout-holds/'.$holdId)
        ->assertOk()
        ->assertJsonPath('data.released', true);

    expect($business->fresh()->available_balance)->toBe(90000)
        ->and(PayoutHold::query()->open()->count())->toBe(0);

    // Released once, and releasing it again is not an error — the caller's
    // intent holds and there is nothing to look for.
    $this->withToken($token)
        ->deleteJson('/api/v1/admin/payout-holds/'.$holdId)
        ->assertOk()
        ->assertJsonPath('data.released', false);

    $after = $this->withToken($token)->getJson('/api/v1/admin/payout-queue')->assertOk()->json('data.queue');
    $afterRow = collect($after['businesses'])->firstWhere('id', (int) $business->id);

    expect($afterRow['payable_kobo'])->toBe(90000)
        ->and($afterRow['blocker'])->toBeNull()
        ->and($after['totals']['stuck_kobo'])->toBe(0);
});

test('a store hold withholds one store on the queue and leaves the others payable', function () {
    w49Platform();

    [$owner, $business, $store] = w49Business();

    $second = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Second Store',
        'slug' => 'w49-queue'.fake()->unique()->numerify('####'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    w49Funded($business, 60000, $store);
    w49Funded($business, 40000, $second);

    $admin = w49Admin();
    $token = w49Token($admin, 'admin');

    $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', [
            'scope' => 'store',
            'store_id' => $store->id,
            'reason' => 'Orders under review',
        ])
        ->assertCreated()
        ->assertJsonPath('data.hold.store_name', $store->name);

    $queue = $this->withToken($token)->getJson('/api/v1/admin/payout-queue')->assertOk()->json('data.queue');
    $row = collect($queue['businesses'])->firstWhere('id', (int) $business->id);

    // Paid the rest, not stopped: 400.00 still leaves on the next run.
    expect($row['payable_kobo'])->toBe(40000)
        ->and($row['withheld_kobo'])->toBe(60000)
        ->and($row['available_kobo'])->toBe(100000)
        ->and($row['blocker'])->toBeNull();
});

test('a system hold is placed with no subject, and a business id beside it is refused', function () {
    w49Platform();

    [, $business, $store] = w49Business();
    w49Funded($business, 90000, $store);

    $admin = w49Admin();
    $token = w49Token($admin, 'admin');

    // Refused rather than ignored: a caller sending a business id with a system
    // scope believes they are holding one business, and holding every business on
    // the platform instead is the worst possible way to find out.
    $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', [
            'scope' => 'system',
            'business_id' => $business->id,
            'reason' => 'Rail incident',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('business_id');

    // And a business-scope hold with no business is equally refused.
    $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', ['scope' => 'business', 'reason' => 'Rail incident'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('business_id');

    // A reason is required: a hold with no reason is indistinguishable from a bug.
    $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', ['scope' => 'system'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', ['scope' => 'system', 'reason' => 'Bachs balance negative'])
        ->assertCreated()
        ->assertJsonPath('data.hold.over', 'the whole platform')
        ->assertJsonPath('data.hold.business_id', null)
        ->assertJsonPath('data.hold.store_id', null);

    // Every business's money is stopped, and the queue says so by scope rather
    // than by looking like every business happened to be broken.
    $queue = $this->withToken($token)->getJson('/api/v1/admin/payout-queue')->assertOk()->json('data.queue');
    $row = collect($queue['businesses'])->firstWhere('id', (int) $business->id);

    expect($row['blocker']['code'])->toBe('hold')
        ->and($row['blocker']['label'])->toBe('on hold: Bachs balance negative (manual)');

    $this->withToken($token)->deleteJson('/api/v1/admin/payout-holds/'.$queue['holds'][0]['id'])->assertOk();

    $after = $this->withToken($token)->getJson('/api/v1/admin/payout-queue')->assertOk()->json('data.queue');
    expect(collect($after['businesses'])->firstWhere('id', (int) $business->id)['blocker'])->toBeNull();
});

test('the payout control surface refuses a business token and a role without the finance permission', function () {
    w49Platform();

    [, $business, $store] = w49Business();
    w49Funded($business, 90000, $store);

    (new SpatiePermissionSeeder)->run();

    // A business "Super Admin" bundles the admin.* permission names, so the
    // permission gate alone would let one through — this is why every controller
    // here also calls EnsuresPlatformAdmin, and why the token audience matters.
    [$owner] = createBusinessOwner();
    setPermissionsTeamId($owner->business_id);
    $owner->assignRole('Super Admin');

    $this->withToken(w49Token($owner, 'admin'))
        ->getJson('/api/v1/admin/payout-queue')
        ->assertForbidden();

    $this->withToken(w49Token($owner, 'admin'))
        ->postJson('/api/v1/admin/payout-holds', ['scope' => 'system', 'reason' => 'Nope'])
        ->assertForbidden();

    // An admin without the finance permission is refused by the route middleware.
    $operator = w49Admin('Support Admin');

    $this->withToken(w49Token($operator, 'admin'))
        ->getJson('/api/v1/admin/payout-queue')
        ->assertForbidden();

    // And a management token cannot reach the admin surface at all.
    $this->withToken(w49Token($owner, 'management'))
        ->getJson('/api/v1/admin/payout-queue')
        ->assertForbidden();

    // Nothing was placed by any of the refused calls.
    expect(PayoutHold::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Admin: one business's wallet, the code that addresses it, and the store
// payloads' balance source
// ---------------------------------------------------------------------------

test('an admin reads a business\'s wallet from the same block the business sees', function () {
    w49Platform();
    w49Settings(['system_payment_processor' => 'bachs']);

    [$owner, $business, $store] = w49Business();

    w49Funded($business, 60000, $store);
    w49Funded($business, 40000, $store);

    w49ProviderAccepts();
    app(PayoutSweepService::class)->sweep();

    $admin = w49Admin();

    $console = $this->withToken(w49Token($admin, 'admin'))
        ->getJson('/api/v1/admin/businesses/'.$business->business_code.'/wallet')
        ->assertOk()
        ->json('data');

    $mine = $this->withToken(w49Token($owner))
        ->getJson('/api/v1/management/wallet')
        ->assertOk()
        ->json('data.wallet');

    // The property this endpoint exists for. An operator reading one figure while
    // the business reads another is how a support call becomes a dispute, so there
    // is one computation and both screens read it — not two that agree today.
    foreach ([
        'currency', 'balance_source', 'pending_kobo', 'available_kobo', 'balance_kobo',
        'payable_kobo', 'withheld_kobo', 'unattributed_kobo', 'custody_enabled', 'auto_payout',
    ] as $key) {
        expect($console['wallet'][$key])->toBe($mine[$key]);
    }

    expect($console['wallet']['blocker'])->toBeNull()
        ->and($console['wallet']['stores'])->toHaveCount(1)
        ->and($console['wallet']['stores'][0]['id'])->toBe((int) $store->id)
        ->and($console['wallet']['stores'][0]['attributed_kobo'])->toBe($mine['stores'][0]['attributed_kobo']);

    // The transfers as a console draws them: what went, whether it landed, and
    // when. Deliberately without the destination digits — an operator reconciling
    // a transfer does not need the business's account number to do it.
    $payout = Payout::query()->where('business_id', $business->id)->sole();

    expect($console['payouts'])->toHaveCount(1)
        ->and($console['payouts'][0]['reference'])->toBe($payout->reference)
        ->and($console['payouts'][0]['amount_kobo'])->toBe(100000)
        ->and($console['payouts'][0]['status'])->toBe(Payout::STATUS_PROCESSING)
        ->and($console['payouts'][0])->not->toHaveKey('account_number');

    // Every business's in-business "Super Admin" bundles the admin.* permission
    // names, so the permission gate alone would let one through. The wallet is the
    // most sensitive read on the platform surface: it is every tenant's money.
    [$businessOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    setPermissionsTeamId($businessOwner->business_id);
    $businessOwner->assignRole('Super Admin');

    $this->withToken(w49Token($businessOwner, 'admin'))
        ->getJson('/api/v1/admin/businesses/'.$business->business_code.'/wallet')
        ->assertForbidden();

    // And a management token cannot reach the admin surface at all.
    $this->withToken(w49Token($owner, 'management'))
        ->getJson('/api/v1/admin/businesses/'.$business->business_code.'/wallet')
        ->assertForbidden();
});

test('a queue row carries the business code the console is addressed by', function () {
    w49Platform();

    [, $business, $store] = w49Business();
    w49Funded($business, 90000, $store);

    $admin = w49Admin();
    $token = w49Token($admin, 'admin');

    $row = collect(
        $this->withToken($token)->getJson('/api/v1/admin/payout-queue')->assertOk()->json('data.queue.businesses')
    )->firstWhere('id', (int) $business->id);

    // The queue lists businesses by id and the console is addressed by code. With
    // no key for it a queue row cannot open the business it is about, and the
    // screen would have to guess a URL or run a second search to find out.
    expect($row['business_code'])->toBe($business->business_code);

    // And the code in the row really is the address — asserted by using it.
    $this->withToken($token)
        ->getJson('/api/v1/admin/businesses/'.$row['business_code'].'/wallet')
        ->assertOk()
        ->assertJsonPath('data.wallet.balance_source', 'wallet');
});

test('the store directory and drawer name which balance is a store\'s real money', function () {
    w49Platform();

    [$owner, $business, $store] = w49Business();
    w49Funded($business, 42500, $store);

    // A second business charged on its own gateway account: the control that makes
    // the flag mean something. One value serving both cases is decoration.
    [, , $ownAccountStore] = w49Business(custody: false);

    $admin = w49Admin('Platform Admin');
    $token = w49Token($admin, 'admin');

    $rows = collect($this->withToken($token)->getJson('/api/v1/admin/stores')->assertOk()->json('data'));
    $row = $rows->firstWhere('id', (int) $store->id);
    $ownRow = $rows->firstWhere('id', (int) $ownAccountStore->id);

    expect($row)->not->toBeNull()
        // A managed sale never credits `stores.balance`, so it is a permanent
        // zero …
        ->and($row['balance'])->toBe(0)
        // … and the flag says so, rather than leaving a client to render the zero
        // as "this store has earned nothing" …
        ->and($row['balance_source'])->toBe('wallet')
        // … while the directory deliberately does not run the walk: one page spans
        // many businesses, and a walk per row is not a page render's job.
        ->and($row['wallet'])->toBeNull();

    expect($ownRow)->not->toBeNull()
        ->and($ownRow['balance_source'])->toBe('store');

    // The drawer is one store, so it carries the walk's own figures.
    $detail = $this->withToken($token)
        ->getJson('/api/v1/admin/stores/'.$store->store_id)
        ->assertOk()
        ->json('data.store');

    expect($detail['balance_source'])->toBe('wallet')
        ->and($detail['balance'])->toBe(0)
        ->and($detail['wallet']['attributed_kobo'])->toBe(42500)
        // The sale matured, so the money is available rather than pending, and the
        // drawer reads that from the business's own columns.
        ->and($detail['wallet']['business_available_kobo'])->toBe(42500)
        ->and($detail['wallet']['business_pending_kobo'])->toBe(0)
        ->and($detail['wallet']['held'])->toBeFalse();

    // The business's own store list agrees, to the kobo. Two screens reading one
    // walk: a console showing a store 42,500 while the business was shown
    // something else is exactly the defect these payloads exist to prevent.
    $theirs = collect(
        $this->withToken(w49Token($owner))
            ->getJson('/api/v1/management/wallet')
            ->assertOk()
            ->json('data.wallet.stores')
    )->firstWhere('id', (int) $store->id);

    expect($theirs)->not->toBeNull()
        ->and($theirs['attributed_kobo'])->toBe($detail['wallet']['attributed_kobo'])
        ->and($theirs['withheld_kobo'])->toBe($detail['wallet']['withheld_kobo']);
});

test('nothing on the payout control surface starts a payout', function () {
    w49Platform();
    w49Settings(['system_payment_processor' => 'bachs']);

    [, $business, $store] = w49Business();
    w49Funded($business, 90000, $store);

    w49ProviderAccepts();

    $admin = w49Admin();
    $token = w49Token($admin, 'admin');

    // Every route that could move money by hand is gone, and the shape of the
    // absence is worth pinning. The sweep has exactly one trigger — the scheduler —
    // so a route that reached it would be a second way to move money, which is a
    // second way to move it twice. Asserted rather than trusted because this is the
    // kind of route that gets added by accident, and the accident reads as help.
    $this->withToken($token)->postJson('/api/v1/admin/payout-queue')->assertStatus(405);
    $this->withToken($token)->postJson('/api/v1/admin/payout-queue/run')->assertNotFound();
    $this->withToken($token)->postJson('/api/v1/admin/payouts')->assertNotFound();
    $this->withToken($token)->postJson('/api/v1/admin/custody/sweep')->assertNotFound();

    // The one write the surface has is a stop, and stopping is not moving: the
    // wallet is untouched, the provider is never called, and no transfer exists.
    $holdId = $this->withToken($token)
        ->postJson('/api/v1/admin/payout-holds', [
            'scope' => 'store',
            'store_id' => $store->id,
            'reason' => 'Orders under review',
        ])
        ->assertCreated()
        ->json('data.hold.id');

    $this->withToken($token)->deleteJson('/api/v1/admin/payout-holds/'.$holdId)->assertOk();

    Http::assertNothingSent();

    expect(Payout::query()->count())->toBe(0)
        ->and($business->fresh()->available_balance)->toBe(90000)
        ->and(PayoutHold::query()->open()->count())->toBe(0);
});
