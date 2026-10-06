<?php

use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-26 — Dispatches board
|--------------------------------------------------------------------------
| The board is a read model over OrderDelivery: search, five filters, four
| legacy metric cards (plus the Failed counter legacy computed but never
| rendered), and rows that link to the order. Rows are created by WS-12.
*/

function ws26Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws26Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS26 Store',
        'slug' => 'ws26-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws26Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'WS26-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'dispatched',
    ], $attributes));
}

function ws26Delivery(Order $order, array $attributes = []): OrderDelivery
{
    return OrderDelivery::create(array_merge([
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'status' => 'assigned',
        'driver_name' => 'Musa Ibrahim',
        'driver_phone' => '08031234567',
    ], $attributes));
}

test('the dispatch board returns the legacy columns, metric cards and filter options', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $storeA = ws26Store($owner, $business, ['name' => 'Lekki Store']);
    $storeB = ws26Store($owner, $business, ['name' => 'Ikeja Store']);

    $route = DeliveryRoute::create([
        'store_id' => $storeA->id,
        'country' => 'Nigeria',
        'state' => 'Lagos',
        'area' => 'Lekki Phase 1',
        'fee' => 1500, // kobo
        'delivery_days' => 2,
    ]);

    $assigned = ws26Order($storeA, ['order_number' => 'WS26-ASSIGNED']);
    $assignedDelivery = ws26Delivery($assigned, [
        'status' => 'assigned',
        'tracking_number' => 'TRK-0001',
        'estimated_delivery_at' => now()->addDay(),
        'delivery_route_id' => $route->id,
    ]);

    $inTransit = ws26Order($storeB, ['order_number' => 'WS26-TRANSIT', 'source' => 'pos']);
    ws26Delivery($inTransit, ['status' => 'in_transit', 'driver_name' => 'Ada Obi']);

    $delivered = ws26Order($storeA, ['order_number' => 'WS26-DELIVERED']);
    ws26Delivery($delivered, ['status' => 'delivered', 'actual_delivery_at' => now()]);

    $failed = ws26Order($storeA, ['order_number' => 'WS26-FAILED']);
    ws26Delivery($failed, ['status' => 'failed']);

    $response = $this->withToken(ws26Token($owner))->getJson('/api/v1/management/dispatches');

    $response->assertOk()
        ->assertJsonCount(4, 'data.dispatches')
        ->assertJsonStructure([
            'data' => [
                'dispatches' => [[
                    'id', 'status', 'status_label',
                    'order' => ['id', 'order_number', 'status', 'is_pos', 'customer_name', 'total'],
                    'store' => ['id', 'name'],
                    'driver_name', 'driver_phone', 'tracking_number', 'route',
                    'estimated_delivery_at', 'actual_delivery_at', 'created_at',
                ]],
                'stats' => ['total', 'pending', 'in_transit', 'delivered_today', 'failed', 'open'],
                'stores',
                'statuses',
            ],
        ])
        ->assertJsonPath('data.stats.total', 4)
        ->assertJsonPath('data.stats.pending', 1)
        ->assertJsonPath('data.stats.in_transit', 1)
        ->assertJsonPath('data.stats.delivered_today', 1)
        ->assertJsonPath('data.stats.failed', 1)
        ->assertJsonPath('data.stats.open', 2)
        ->assertJsonCount(8, 'data.statuses')
        ->assertJsonPath('data.stores.0.name', 'Ikeja Store');

    $rows = collect($response->json('data.dispatches'));

    expect($rows->firstWhere('id', $assignedDelivery->id)['route'])->toBe('Lekki Phase 1, Lagos')
        ->and($rows->firstWhere('id', $assignedDelivery->id)['order']['order_number'])->toBe('WS26-ASSIGNED')
        ->and($rows->firstWhere('id', $assignedDelivery->id)['order']['is_pos'])->toBeFalse()
        ->and($rows->firstWhere('driver_name', 'Ada Obi')['store']['name'])->toBe('Ikeja Store')
        ->and($rows->firstWhere('driver_name', 'Ada Obi')['order']['is_pos'])->toBeTrue();
});

test('the board search matches driver name, tracking number and order number', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws26Store($owner, $business);

    $byDriver = ws26Delivery(ws26Order($store, ['order_number' => 'WS26-AAA']), [
        'driver_name' => 'Chidi Nwosu',
    ]);
    $byTracking = ws26Delivery(ws26Order($store, ['order_number' => 'WS26-BBB']), [
        'driver_name' => 'Bola Adeyemi',
        'tracking_number' => 'DHL-99881',
    ]);
    $byOrder = ws26Delivery(ws26Order($store, ['order_number' => 'WS26-CCC']));

    $token = ws26Token($owner);

    $driverResults = $this->withToken($token)
        ->getJson('/api/v1/management/dispatches?q=Chidi')
        ->assertOk()
        ->json('data.dispatches');
    expect(array_column($driverResults, 'id'))->toBe([$byDriver->id]);

    $trackingResults = $this->withToken($token)
        ->getJson('/api/v1/management/dispatches?q=DHL-99881')
        ->assertOk()
        ->json('data.dispatches');
    expect(array_column($trackingResults, 'id'))->toBe([$byTracking->id]);

    $orderResults = $this->withToken($token)
        ->getJson('/api/v1/management/dispatches?q=WS26-CCC')
        ->assertOk()
        ->json('data.dispatches');
    expect(array_column($orderResults, 'id'))->toBe([$byOrder->id]);
});

test('the board filters by status, store and created date range', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $storeA = ws26Store($owner, $business, ['name' => 'Store A']);
    $storeB = ws26Store($owner, $business, ['name' => 'Store B']);

    $old = ws26Delivery(ws26Order($storeA, ['order_number' => 'WS26-OLD']), ['status' => 'assigned']);
    $old->forceFill(['created_at' => now()->subMonth()])->save();

    $recent = ws26Delivery(ws26Order($storeA, ['order_number' => 'WS26-RECENT']), ['status' => 'assigned']);
    $otherStore = ws26Delivery(ws26Order($storeB, ['order_number' => 'WS26-OTHER']), ['status' => 'picked_up']);

    $token = ws26Token($owner);

    $byStatus = $this->withToken($token)
        ->getJson('/api/v1/management/dispatches?status=picked_up')
        ->assertOk()
        ->json('data.dispatches');
    expect(array_column($byStatus, 'id'))->toBe([$otherStore->id]);

    $byStore = $this->withToken($token)
        ->getJson('/api/v1/management/dispatches?store_id='.$storeB->id)
        ->assertOk()
        ->json('data.dispatches');
    expect(array_column($byStore, 'id'))->toBe([$otherStore->id]);

    // The legacy filter modal posted date_from/date_to; both spellings work.
    $byDate = $this->withToken($token)
        ->getJson('/api/v1/management/dispatches?date_from='.now()->subWeek()->toDateString())
        ->assertOk()
        ->json('data.dispatches');
    expect(array_column($byDate, 'id'))->toHaveCount(2)
        ->and(array_column($byDate, 'id'))->not->toContain($old->id);

    $legacyAlias = $this->withToken($token)
        ->getJson('/api/v1/management/dispatches?date_to='.now()->subWeek()->toDateString())
        ->assertOk()
        ->json('data.dispatches');
    expect(array_column($legacyAlias, 'id'))->toBe([$old->id]);
});

test('the metric cards use the legacy status groups and ignore the active filters', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws26Store($owner, $business);

    foreach (['pending', 'assigned', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'] as $status) {
        ws26Delivery(ws26Order($store), [
            'status' => $status,
            'actual_delivery_at' => $status === 'delivered' ? now() : null,
        ]);
    }

    $response = $this->withToken(ws26Token($owner))
        ->getJson('/api/v1/management/dispatches?status=delivered')
        ->assertOk();

    // One row survives the filter, but the cards still describe the whole board.
    $response->assertJsonCount(1, 'data.dispatches')
        ->assertJsonPath('data.stats.total', 8)
        ->assertJsonPath('data.stats.pending', 2)
        ->assertJsonPath('data.stats.in_transit', 3)
        ->assertJsonPath('data.stats.delivered_today', 1)
        ->assertJsonPath('data.stats.failed', 2)
        ->assertJsonPath('data.stats.open', 5);
});

test('delivered today counts the actual delivery time, not past deliveries', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws26Store($owner, $business);

    ws26Delivery(ws26Order($store), ['status' => 'delivered', 'actual_delivery_at' => now()->subDay()]);
    ws26Delivery(ws26Order($store), ['status' => 'delivered', 'actual_delivery_at' => now()]);

    // A delivery moved to delivered without an actual time must not inflate it.
    ws26Delivery(ws26Order($store), ['status' => 'delivered', 'actual_delivery_at' => null]);

    $this->withToken(ws26Token($owner))
        ->getJson('/api/v1/management/dispatches')
        ->assertOk()
        ->assertJsonPath('data.stats.delivered_today', 1);
});

test('deliveries from another business are never listed and their store id is refused', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws26Delivery(ws26Order(ws26Store($owner, $business), ['order_number' => 'WS26-MINE']));
    $theirs = ws26Delivery(ws26Order(ws26Store($otherOwner, $otherBusiness), ['order_number' => 'WS26-THEIRS']));

    $response = $this->withToken(ws26Token($owner))->getJson('/api/v1/management/dispatches')->assertOk();

    expect(array_column($response->json('data.dispatches'), 'id'))->toBe([$mine->id])
        ->and($response->json('data.stats.total'))->toBe(1)
        ->and(array_column($response->json('data.stores'), 'id'))->toBe([$mine->order->store_id]);

    $foreignStoreId = $theirs->order->store_id;

    $this->withToken(ws26Token($owner))
        ->getJson('/api/v1/management/dispatches?store_id='.$foreignStoreId)
        ->assertStatus(403);
});

test('restricted staff only see dispatches from stores assigned to them', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws26Store($owner, $business, ['name' => 'Assigned']);
    $unassigned = ws26Store($owner, $business, ['name' => 'Unassigned']);

    $visible = ws26Delivery(ws26Order($assigned, ['order_number' => 'WS26-VISIBLE']));
    ws26Delivery(ws26Order($unassigned, ['order_number' => 'WS26-HIDDEN']));

    // Store Associate holds orders view but not transactions view, so the
    // user is both permitted and restricted to assigned stores.
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');
    $staff->assignedStores()->attach($assigned->id);

    // Legacy's counters were business-wide, leaking the unassigned store's
    // delivery count to restricted staff; both rows and cards are scoped here.
    $this->withToken(ws26Token($staff))
        ->getJson('/api/v1/management/dispatches')
        ->assertOk()
        ->assertJsonPath('data.stats.total', 1)
        ->assertJsonCount(1, 'data.stores')
        ->assertJsonPath('data.dispatches.0.id', $visible->id);
});

test('an invalid status filter is rejected', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws26Token($owner))
        ->getJson('/api/v1/management/dispatches?status=lost')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('the board is empty with zeroed cards until orders are dispatched', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws26Store($owner, $business);

    $this->withToken(ws26Token($owner))
        ->getJson('/api/v1/management/dispatches')
        ->assertOk()
        ->assertJsonCount(0, 'data.dispatches')
        ->assertJsonPath('data.stats.total', 0)
        ->assertJsonPath('data.stats.pending', 0)
        ->assertJsonPath('data.stats.in_transit', 0)
        ->assertJsonPath('data.stats.delivered_today', 0)
        ->assertJsonPath('data.stats.failed', 0)
        ->assertJsonPath('data.stats.open', 0);
});

test("a soft-deleted order's delivery stays off the board", function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws26Store($owner, $business);

    $order = ws26Order($store, ['order_number' => 'WS26-GONE']);
    ws26Delivery($order);
    $order->delete();

    $this->withToken(ws26Token($owner))
        ->getJson('/api/v1/management/dispatches')
        ->assertOk()
        ->assertJsonCount(0, 'data.dispatches')
        ->assertJsonPath('data.stats.total', 0);
});
