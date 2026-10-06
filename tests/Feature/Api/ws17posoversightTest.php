<?php

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-17 — POS Oversight & Sessions
|--------------------------------------------------------------------------
| Owner-level POS oversight: sessions across every accessible store, the
| cash-drawer reconciliation, and the POS `source` filter on orders.
*/

function ws17Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws17Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS17 Store',
        'slug' => 'ws17-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws17Session(Store $store, User $staff, array $attributes = []): PosSession
{
    return PosSession::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'staff_id' => $staff->id,
        'opened_at' => now(),
        'opening_balance' => 100000, // ₦1,000.00 float, in kobo
        'status' => PosSession::STATUS_OPEN,
    ], $attributes));
}

function ws17Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'pos',
        'order_number' => 'WS17-ORD-'.$sequence,
        'subtotal' => 2500,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 2500,
        'amount_paid' => 2500,
        'status' => 'completed',
    ], $attributes));
}

/**
 * A confirmed payment leg. With no payment method and no `leg_method` metadata
 * this is the cash leg the drawer reconciliation counts.
 */
function ws17Payment(Order $order, float $amount, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS17-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => $amount,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws17Staff($business, string $role = 'Cashier'): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
        'name' => 'WS17 '.$role,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole($role);

    return $staff;
}

test('the POS sessions list returns KPIs and drawer figures across stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $ikeja = ws17Store($owner, $business, ['name' => 'Ikeja', 'pos_enabled' => true]);
    $yaba = ws17Store($owner, $business, ['name' => 'Yaba', 'pos_enabled' => true]);
    ws17Store($owner, $business, ['name' => 'No POS']);

    $open = ws17Session($ikeja, $owner, ['opening_balance' => 100000]);
    $order = ws17Order($ikeja, ['pos_session_id' => $open->id, 'total' => 2500, 'subtotal' => 2500]);
    ws17Payment($order, 2500);

    $closed = ws17Session($yaba, $owner, [
        'status' => PosSession::STATUS_CLOSED,
        'opened_at' => now()->subDay(),
        'closed_at' => now()->subDay()->addHour(),
        'opening_balance' => 5000,
        'closing_balance_expected' => 5000,
        'closing_balance_actual' => 4500,
        'difference' => -500,
    ]);

    $response = $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/pos/sessions')
        ->assertOk();

    $response
        ->assertJsonPath('data.stats.open_sessions', 1)
        ->assertJsonPath('data.stats.pos_stores', 2)
        // 2500 naira -> 250000 kobo; the non-POS store contributes nothing.
        ->assertJsonPath('data.stats.today_pos_sales', 250000)
        ->assertJsonCount(2, 'data.sessions')
        ->assertJsonCount(3, 'data.stores');

    $rows = collect($response->json('data.sessions'))->keyBy('session_code');

    expect($rows[$open->session_code]['expected_close'])->toBe(350000)
        ->and($rows[$open->session_code]['cash_sales_total'])->toBe(250000)
        ->and($rows[$open->session_code]['orders_count'])->toBe(1)
        ->and($rows[$open->session_code]['is_open'])->toBeTrue()
        ->and($rows[$open->session_code]['difference'])->toBeNull()
        ->and($rows[$open->session_code]['store']['name'])->toBe('Ikeja')
        ->and($rows[$closed->session_code]['expected_close'])->toBe(5000)
        ->and($rows[$closed->session_code]['actual_close'])->toBe(4500)
        ->and($rows[$closed->session_code]['difference'])->toBe(-500)
        ->and($rows[$closed->session_code]['is_open'])->toBeFalse();
});

test('the POS sessions list filters by store and status', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $storeA = ws17Store($owner, $business, ['pos_enabled' => true]);
    $storeB = ws17Store($owner, $business, ['pos_enabled' => true]);

    $openA = ws17Session($storeA, $owner);
    $closedA = ws17Session($storeA, $owner, ['status' => PosSession::STATUS_CLOSED, 'closed_at' => now()]);
    $openB = ws17Session($storeB, $owner);

    $token = ws17Token($owner);

    $byStore = $this->withToken($token)
        ->getJson('/api/v1/management/pos/sessions?store_id='.$storeA->id)
        ->assertOk();

    expect(collect($byStore->json('data.sessions'))->pluck('session_code')->sort()->values()->all())
        ->toBe(collect([$openA->session_code, $closedA->session_code])->sort()->values()->all());

    $openOnly = $this->withToken($token)
        ->getJson('/api/v1/management/pos/sessions?status=open')
        ->assertOk();

    expect(collect($openOnly->json('data.sessions'))->pluck('session_code')->sort()->values()->all())
        ->toBe(collect([$openA->session_code, $openB->session_code])->sort()->values()->all());

    $this->withToken($token)
        ->getJson('/api/v1/management/pos/sessions?status=awaiting')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('a store filter naming another business store is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws17Store($otherOwner, $otherBusiness, ['pos_enabled' => true]);

    $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/pos/sessions?store_id='.$theirs->id)
        ->assertStatus(403);
});

test('a POS session from another business is not reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws17Session(ws17Store($otherOwner, $otherBusiness, ['pos_enabled' => true]), $otherOwner);

    $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/pos/sessions/'.$theirs->session_code)
        ->assertStatus(403);
});

test('the session detail returns sales records, transactions and staff activity', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    // An older session so the cashier's activity list has something behind it.
    ws17Session($store, $owner, ['opened_at' => now()->subHours(3)]);

    $session = ws17Session($store, $owner, ['opened_at' => now()->subHour(), 'opening_balance' => 0]);
    $order = ws17Order($store, ['pos_session_id' => $session->id, 'total' => 1200, 'subtotal' => 1200]);
    ws17Payment($order, 1200);

    $response = $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/pos/sessions/'.$session->session_code)
        ->assertOk();

    $response
        ->assertJsonPath('data.session.session_code', $session->session_code)
        ->assertJsonPath('data.session.status', 'open')
        ->assertJsonPath('data.session.orders_count', 1)
        ->assertJsonPath('data.session.sales_total', 120000)
        ->assertJsonPath('data.session.orders.0.order_number', $order->order_number)
        ->assertJsonPath('data.session.orders.0.total', 120000)
        ->assertJsonPath('data.session.orders.0.payment_status', 'confirmed')
        ->assertJsonPath('data.session.orders.0.items_count', 0)
        ->assertJsonPath('data.session.transactions.0.amount', 120000)
        ->assertJsonPath('data.session.transactions.0.status', 'confirmed')
        ->assertJsonCount(2, 'data.session.staff_recent_sessions')
        // Latest first.
        ->assertJsonPath('data.session.staff_recent_sessions.0.session_code', $session->session_code)
        ->assertJsonPath('data.session.staff_recent_sessions.1.session_code', PosSession::where('store_id', $store->id)->oldest('opened_at')->first()->session_code);
});

test('opening a session requires POS to be enabled for the store', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business); // pos_enabled defaults to false

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/open', ['opening_balance' => 100000])
        ->assertStatus(422)
        ->assertJsonPath('message', 'POS is not enabled for this store.');

    expect(PosSession::count())->toBe(0);
});

test('opening a session stores the float and blocks the same cashier opening twice', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/open', ['opening_balance' => 150000])
        ->assertCreated()
        ->assertJsonPath('data.session.opening_balance', 150000)
        ->assertJsonPath('data.session.is_open', true)
        ->assertJsonPath('data.open_sessions_count', 1);

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/open', ['opening_balance' => 0])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You already have an open session for this store.');

    expect(PosSession::count())->toBe(1);
});

test('two cashiers can hold sessions on one store and both are surfaced', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $ada = ws17Staff($business, 'Cashier');
    $bola = ws17Staff($business, 'Cashier');
    $ada->assignedStores()->attach($store->id);
    $bola->assignedStores()->attach($store->id);

    $this->withToken(ws17Token($ada))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/open', ['opening_balance' => 1000])
        ->assertCreated();

    $this->withToken(ws17Token($bola))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/open', ['opening_balance' => 2000])
        ->assertCreated();

    $response = $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/pos/sessions?status=open')
        ->assertOk()
        ->assertJsonPath('data.stats.open_sessions', 2)
        ->assertJsonCount(2, 'data.sessions');

    expect($response->json('data.stores.0.open_sessions_count'))->toBe(2);
});

test('the opening float must be a non-negative integer', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/open', ['opening_balance' => -50])
        ->assertStatus(422)
        ->assertJsonValidationErrors('opening_balance');

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/open', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('opening_balance');
});

test('closing reconciles the drawer from confirmed cash legs only', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $session = ws17Session($store, $owner, ['opening_balance' => 0]);

    // Cash sales of 4000 naira confirmed on the till.
    $order = ws17Order($store, ['pos_session_id' => $session->id, 'total' => 4000, 'subtotal' => 4000]);
    ws17Payment($order, 4000);

    // A transfer leg settles the same order but is not in the drawer; it must
    // not inflate the expected close.
    $transferOrder = ws17Order($store, ['pos_session_id' => $session->id, 'total' => 1000, 'subtotal' => 1000]);
    $bank = PaymentMethod::create(['name' => 'Bank Transfer', 'code' => 'bank_transfer_ws17']);
    ws17Payment($transferOrder, 1000, ['payment_method_id' => $bank->id]);

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/close', [
            'closing_balance_actual' => 390000,
            'notes' => 'Drawer counted short by ₦10.',
        ])
        ->assertOk()
        ->assertJsonPath('data.session.expected_close', 400000)
        ->assertJsonPath('data.session.actual_close', 390000)
        ->assertJsonPath('data.session.difference', -10000)
        ->assertJsonPath('data.session.status', 'closed')
        ->assertJsonPath('data.session.notes', 'Drawer counted short by ₦10.')
        ->assertJsonPath('data.other_open_sessions', 0);

    $session->refresh();

    expect($session->closing_balance_expected)->toBe(400000)
        ->and($session->difference)->toBe(-10000)
        ->and($session->closed_at)->not->toBeNull();
});

test('closing targets the latest open session unless one is named', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $ada = ws17Staff($business, 'Cashier');
    $bola = ws17Staff($business, 'Cashier');

    $older = ws17Session($store, $ada, ['opened_at' => now()->subHours(2), 'opening_balance' => 0]);
    $latest = ws17Session($store, $bola, ['opened_at' => now()->subMinute(), 'opening_balance' => 0]);

    $token = ws17Token($owner);

    // No session_code: the store's latest open session is the one counted.
    $this->withToken($token)
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/close', ['closing_balance_actual' => 0])
        ->assertOk()
        ->assertJsonPath('data.session.session_code', $latest->session_code)
        ->assertJsonPath('data.session.staff.name', 'WS17 Cashier')
        ->assertJsonPath('data.other_open_sessions', 1);

    expect($latest->fresh()->status)->toBe(PosSession::STATUS_CLOSED)
        ->and($older->fresh()->status)->toBe(PosSession::STATUS_OPEN);

    // The remaining drawer is named explicitly.
    $this->withToken($token)
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/close', [
            'closing_balance_actual' => 0,
            'session_code' => $older->session_code,
        ])
        ->assertOk()
        ->assertJsonPath('data.session.session_code', $older->session_code)
        ->assertJsonPath('data.other_open_sessions', 0);

    expect($older->fresh()->status)->toBe(PosSession::STATUS_CLOSED);
});

test('closing requires an open session and a non-negative cash count', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/close', ['closing_balance_actual' => 0])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No open session found for this store.');

    $session = ws17Session($store, $owner);

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/close', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('closing_balance_actual');

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/close', ['closing_balance_actual' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('closing_balance_actual');

    $this->withToken(ws17Token($owner))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/close', [
            'closing_balance_actual' => 0,
            'notes' => str_repeat('x', 501),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('notes');

    expect($session->fresh()->status)->toBe(PosSession::STATUS_OPEN);
});

test('POS can be enabled for a store and enabling twice is harmless', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business);

    $token = ws17Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/enable')
        ->assertOk()
        ->assertJsonPath('data.store.pos_enabled', true);

    expect($store->fresh()->pos_enabled)->toBeTrue();

    $this->withToken($token)
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/enable')
        ->assertOk()
        ->assertJsonPath('data.store.pos_enabled', true);
});

test('a cashier cannot enable POS without the stores settings permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $cashier = ws17Staff($business, 'Cashier');
    $cashier->assignedStores()->attach($store->id);

    $this->withToken(ws17Token($cashier))
        ->postJson('/api/v1/management/stores/'.$store->store_id.'/pos/enable')
        ->assertStatus(403);
});

test('the store POS history paginates and reconciles each session', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $open = ws17Session($store, $owner, ['opening_balance' => 0]);
    $order = ws17Order($store, ['pos_session_id' => $open->id, 'total' => 1000, 'subtotal' => 1000]);
    ws17Payment($order, 1000);

    $closed = ws17Session($store, $owner, [
        'status' => PosSession::STATUS_CLOSED,
        'opened_at' => now()->subDay(),
        'closed_at' => now()->subDay()->addMinutes(30),
        'opening_balance' => 2000,
        'closing_balance_expected' => 2000,
        'closing_balance_actual' => 2500,
        'difference' => 500,
    ]);

    $response = $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/pos/sessions')
        ->assertOk()
        ->assertJsonPath('data.store.pos_enabled', true)
        ->assertJsonPath('data.stats.open_sessions', 1)
        ->assertJsonPath('data.stats.total_sessions', 2)
        ->assertJsonPath('data.stats.today_pos_sales', 100000)
        ->assertJsonCount(2, 'data.sessions');

    $rows = collect($response->json('data.sessions'))->keyBy('session_code');

    expect($rows[$open->session_code]['expected_close'])->toBe(100000)
        ->and($rows[$closed->session_code]['difference'])->toBe(500);
});

test('the per-store session endpoint refuses a session from a different store', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $storeA = ws17Store($owner, $business, ['pos_enabled' => true]);
    $storeB = ws17Store($owner, $business, ['pos_enabled' => true]);

    $session = ws17Session($storeA, $owner);

    $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/stores/'.$storeB->store_id.'/pos/sessions/'.$session->session_code)
        ->assertStatus(404);

    $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/stores/'.$storeA->store_id.'/pos/sessions/'.$session->session_code)
        ->assertOk()
        ->assertJsonPath('data.session.session_code', $session->session_code)
        ->assertJsonPath('data.store.name', $storeA->name);
});

test('a restricted staff member only sees POS sessions of assigned stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws17Store($owner, $business, ['name' => 'Assigned', 'pos_enabled' => true]);
    $other = ws17Store($owner, $business, ['name' => 'Other', 'pos_enabled' => true]);

    $mine = ws17Session($assigned, $owner);
    ws17Session($other, $owner);

    // Store Associate holds pos view_history and has no transactions view, so
    // the user is both permitted and restricted to assigned stores.
    $staff = ws17Staff($business, 'Store Associate');
    $staff->assignedStores()->attach($assigned->id);

    $response = $this->withToken(ws17Token($staff))
        ->getJson('/api/v1/management/pos/sessions')
        ->assertOk();

    expect(collect($response->json('data.sessions'))->pluck('session_code')->all())
        ->toBe([$mine->session_code]);

    $this->withToken(ws17Token($staff))
        ->getJson('/api/v1/management/stores/'.$other->store_id.'/pos/sessions')
        ->assertStatus(403);
});

test('the overview endpoint reports open counts per store for the nav group', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $enabled = ws17Store($owner, $business, ['name' => 'Enabled', 'pos_enabled' => true]);
    $disabled = ws17Store($owner, $business, ['name' => 'Disabled']);

    ws17Session($enabled, $owner);
    ws17Session($enabled, $owner, ['opened_at' => now()->subMinutes(5)]);

    $response = $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/pos/overview')
        ->assertOk()
        ->assertJsonPath('data.stats.open_sessions', 2)
        ->assertJsonPath('data.stats.pos_stores', 1);

    $rows = collect($response->json('data.stores'))->keyBy('name');

    expect($rows['Enabled']['open_sessions_count'])->toBe(2)
        ->and($rows['Enabled']['pos_enabled'])->toBeTrue()
        ->and($rows['Disabled']['open_sessions_count'])->toBe(0)
        ->and($rows['Disabled']['pos_enabled'])->toBeFalse();
});

test('the orders list filters by source and exposes the POS session', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $session = ws17Session($store, $owner);
    $posOrder = ws17Order($store, ['pos_session_id' => $session->id, 'order_number' => 'WS17-POS-1']);
    $onlineOrder = ws17Order($store, ['source' => 'checkout', 'order_number' => 'WS17-WEB-1']);

    $token = ws17Token($owner);

    $posOnly = $this->withToken($token)
        ->getJson('/api/v1/management/orders?source=pos')
        ->assertOk();

    expect(array_column($posOnly->json('data'), 'order_number'))->toBe(['WS17-POS-1']);

    $posOnly
        ->assertJsonPath('data.0.is_pos', true)
        ->assertJsonPath('data.0.pos_session_id', $session->id);

    $onlineOnly = $this->withToken($token)
        ->getJson('/api/v1/management/orders?source=checkout')
        ->assertOk();

    expect(array_column($onlineOnly->json('data'), 'order_number'))->toBe(['WS17-WEB-1']);

    // The base payload fields survive the route takeover.
    $this->withToken($token)
        ->getJson('/api/v1/management/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'order_number', 'customer', 'store', 'source', 'total', 'status', 'payment_status', 'created_at']], 'meta']);
});

test('the orders source filter rejects a non-string value', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/orders?source[]=pos')
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');
});

test('the orders list stays scoped to the accessible stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws17Store($owner, $business, ['pos_enabled' => true]);
    $theirs = ws17Store($otherOwner, $otherBusiness, ['pos_enabled' => true]);

    ws17Order($mine, ['order_number' => 'WS17-MINE']);
    ws17Order($theirs, ['order_number' => 'WS17-THEIRS']);

    $response = $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/orders?source=pos')
        ->assertOk();

    expect(array_column($response->json('data'), 'order_number'))->toBe(['WS17-MINE']);
});

test('the today POS sales KPI counts only orders tied to a POS session', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws17Store($owner, $business, ['pos_enabled' => true]);

    $session = ws17Session($store, $owner);
    ws17Order($store, ['pos_session_id' => $session->id, 'total' => 3000, 'subtotal' => 3000]);
    // A source=pos row with no session (e.g. a legacy import) is not counted.
    ws17Order($store, ['pos_session_id' => null, 'total' => 9000, 'subtotal' => 9000]);
    // An online order is not counted either.
    ws17Order($store, ['source' => 'checkout', 'total' => 7000, 'subtotal' => 7000]);

    $this->withToken(ws17Token($owner))
        ->getJson('/api/v1/management/pos/sessions')
        ->assertOk()
        ->assertJsonPath('data.stats.today_pos_sales', 300000);
});
