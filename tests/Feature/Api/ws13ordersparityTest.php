<?php

use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\Ws13OrdersDeletePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-13 — Orders list & detail parity
|--------------------------------------------------------------------------
*/

function ws13Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws13Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS13 Store',
        'slug' => 'ws13-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws13Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'WS13-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ws13Item(Order $order, array $attributes = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'product_name' => 'WS13 Widget',
        'product_code' => 'WS13-SKU',
        'unit_price' => 1000,
        'quantity' => 1,
        'subtotal' => 1000,
        'is_digital' => false,
    ], $attributes));
}

function ws13Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS13-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws13Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ada-'.Str::lower(Str::random(6)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => 'ACTIVE',
    ], $attributes));
}

test('the orders board returns the legacy list columns and filters', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $storeA = ws13Store($owner, $business, ['name' => 'Lekki Store']);
    $storeB = ws13Store($owner, $business, ['name' => 'Ikeja Store']);
    $foreignStore = ws13Store($otherOwner, $otherBusiness, ['name' => 'Theirs']);

    $customer = ws13Customer($business->id, ['first_name' => 'Ada', 'last_name' => 'Obi']);

    PaymentMethod::create(['name' => 'Cash', 'code' => 'cash']);
    $transfer = PaymentMethod::create(['name' => 'Bank Transfer', 'code' => 'bank_transfer']);

    $online = ws13Order($storeA, [
        'order_number' => 'WS13-ONLINE',
        'customer_id' => $customer->id,
        'status' => 'pending',
        'subtotal' => 5000,
        'total' => 5000,
        'amount_paid' => 2000,
    ]);
    $online->created_at = Carbon::parse('2026-06-10 09:00:00');
    $online->save();

    ws13Item($online);
    ws13Item($online);

    ws13Transaction($online, ['payment_method_id' => PaymentMethod::where('code', 'cash')->value('id')]);
    ws13Transaction($online, ['reference' => 'WS13-TXN-SPLIT', 'payment_method_id' => $transfer->id, 'amount' => 1500]);

    $pos = ws13Order($storeB, [
        'order_number' => 'WS13-POS',
        'source' => 'pos',
        'status' => 'completed',
        'total' => 1500,
        'amount_paid' => 1500,
        'meta' => ['customer_name' => 'Walk-in Buyer', 'customer_phone' => '08099998888'],
    ]);
    $pos->created_at = Carbon::parse('2026-06-20 12:00:00');
    $pos->save();

    $deleted = ws13Order($storeA, ['order_number' => 'WS13-GONE']);
    $deleted->delete();

    $token = ws13Token($owner);

    $response = $this->withToken($token)->getJson('/api/v1/management/orders/board/list');

    $response->assertOk();
    $rows = collect($response->json('data.orders'))->keyBy('order_number');

    expect($rows)->not->toHaveKey('WS13-GONE');

    expect($rows['WS13-ONLINE']['items_count'])->toBe(2)
        ->and($rows['WS13-ONLINE']['payment_legs'])->toBe(2)
        ->and($rows['WS13-ONLINE']['is_split_payment'])->toBeTrue()
        ->and($rows['WS13-ONLINE']['payment_method'])->toBe('Cash')
        ->and($rows['WS13-ONLINE']['payment_method_code'])->toBe('cash')
        ->and((float) $rows['WS13-ONLINE']['remaining'])->toBe(3000.0)
        ->and($rows['WS13-ONLINE']['status'])->toBe('pending')
        ->and($rows['WS13-ONLINE']['status_label'])->toBe('Pending')
        ->and($rows['WS13-ONLINE']['is_pos'])->toBeFalse()
        ->and($rows['WS13-ONLINE']['store'])->toBe('Lekki Store')
        ->and($rows['WS13-ONLINE']['customer']['name'])->toBe('Ada Obi')
        ->and($rows['WS13-ONLINE']['customer']['phone'])->toBe('08011112222')
        ->and($rows['WS13-ONLINE']['customer']['is_walk_in'])->toBeFalse()
        ->and($rows['WS13-POS']['is_pos'])->toBeTrue()
        ->and($rows['WS13-POS']['customer']['is_walk_in'])->toBeTrue()
        ->and($rows['WS13-POS']['customer']['name'])->toBe('Walk-in Buyer')
        ->and($rows['WS13-POS']['customer']['phone'])->toBe('08099998888');

    // Filters — source, store (numeric id, not the legacy store code), status,
    // search and the date range.
    $this->withToken($token)->getJson('/api/v1/management/orders/board/list?source=pos')
        ->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.order_number', 'WS13-POS');

    $this->withToken($token)->getJson('/api/v1/management/orders/board/list?store_id='.$storeA->id)
        ->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.order_number', 'WS13-ONLINE');

    $this->withToken($token)->getJson('/api/v1/management/orders/board/list?status=completed')
        ->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.order_number', 'WS13-POS');

    $this->withToken($token)->getJson('/api/v1/management/orders/board/list?q=Ada')
        ->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.order_number', 'WS13-ONLINE');

    $this->withToken($token)->getJson('/api/v1/management/orders/board/list?from=2026-06-15&to=2026-06-30')
        ->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.order_number', 'WS13-POS');

    // A store id from another business is refused rather than silently
    // matching nothing.
    $this->withToken($token)->getJson('/api/v1/management/orders/board/list?store_id='.$foreignStore->id)
        ->assertStatus(403);
});

test('the orders board stats count every legacy status for this business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws13Store($owner, $business);
    $foreignStore = ws13Store($otherOwner, $otherBusiness);

    ws13Order($store, ['status' => 'pending']);
    ws13Order($store, ['status' => 'dispatched']);
    ws13Order($store, ['status' => 'delivered']);
    ws13Order($store, ['status' => 'cancelled']);

    $gone = ws13Order($store, ['status' => 'pending']);
    $gone->delete();

    ws13Order($foreignStore, ['status' => 'pending']);

    $this->withToken(ws13Token($owner))
        ->getJson('/api/v1/management/orders/board/stats')
        ->assertOk()
        ->assertJsonPath('data.stats.total', 4)
        ->assertJsonPath('data.stats.pending', 1)
        ->assertJsonPath('data.stats.accepted', 0)
        ->assertJsonPath('data.stats.processing', 0)
        ->assertJsonPath('data.stats.dispatched', 1)
        ->assertJsonPath('data.stats.delivered', 1)
        ->assertJsonPath('data.stats.completed', 0)
        ->assertJsonPath('data.stats.cancelled', 1)
        ->assertJsonPath('data.stats.returned', 0);
});

test('order detail renders the legacy panels', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws13Store($owner, $business, ['name' => 'Lekki Store']);

    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'country' => 'Nigeria',
        'state' => 'Lagos',
        'area' => 'Lekki Phase 1',
        'fee' => 1500,
        'delivery_days' => 2,
    ]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => 'Chidi Cashier',
        'email' => 'chidi-'.Str::lower(Str::random(5)).'@ws13.test',
    ]);

    $order = ws13Order($store, [
        'order_number' => 'WS13-DETAIL',
        'customer_id' => null,
        'status' => 'processing',
        'subtotal' => 4000,
        'shipping_fee' => 500,
        'tax' => 200,
        'total' => 4775,
        'amount_paid' => 2000,
        'service_charge_amount' => 75,
        'staff_id' => $staff->id,
        'delivery_route_id' => $route->id,
        'delivery_state' => 'Lagos',
        'delivery_area' => 'Lekki Phase 1',
        'notes' => 'Leave at the reception desk.',
        'meta' => [
            'customer_name' => 'Walk-in Buyer',
            'customer_phone' => '08099998888',
            'service_charge_name' => 'Packaging',
        ],
    ]);

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Blue Hoodie',
        'amount' => 2000,
        'quantity' => 10,
        'status' => 'active',
    ]);

    ProductImage::create([
        'product_id' => $product->id,
        'business_id' => $business->id,
        'path' => 'products/images/hoodie.jpg',
        'is_primary' => true,
        'position' => 1,
    ]);

    ws13Item($order, [
        'product_id' => $product->id,
        'product_name' => 'Blue Hoodie',
        'product_code' => 'HOOD-BLUE',
        'unit_price' => 2000,
        'quantity' => 2,
        'subtotal' => 4000,
    ]);

    OrderDelivery::create([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'status' => 'assigned',
        'tracking_number' => 'TRK-WS13-9',
        'driver_name' => 'Dan Driver',
        'driver_phone' => '08031234567',
        'delivery_notes' => 'Call on arrival',
        'estimated_delivery_at' => now()->addDay(),
        'created_by' => $owner->id,
    ]);

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Storify Ltd',
        'is_verified' => true,
    ]);

    $transfer = PaymentMethod::create(['name' => 'Bank Transfer', 'code' => 'bank_transfer']);

    ws13Transaction($order, [
        'payment_method_id' => $transfer->id,
        'store_bank_id' => $bank->id,
        'amount' => 2000,
        'status' => TransactionStatus::PENDING,
        'paid_at' => null,
    ]);

    ActivityLog::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'action' => 'accepted',
        'subject_type' => Order::class,
        'subject_id' => $order->id,
        'description' => 'Order accepted',
    ]);

    $response = $this->withToken(ws13Token($owner))
        ->getJson('/api/v1/management/orders/WS13-DETAIL/detail')
        ->assertOk();

    $detail = $response->json('data.order');

    expect($detail['order_number'])->toBe('WS13-DETAIL')
        ->and($detail['status_label'])->toBe('Processing')
        ->and($detail['payment_status'])->toBe('pending')
        ->and($detail['source'])->toBe('checkout')
        ->and($detail['notes'])->toBe('Leave at the reception desk.')
        ->and((float) $detail['subtotal'])->toBe(4000.0)
        ->and($detail['service_charge']['name'])->toBe('Packaging')
        ->and((float) $detail['service_charge']['amount'])->toBe(75.0)
        ->and($detail['items'])->toHaveCount(1)
        ->and($detail['items'][0]['product_code'])->toBe('HOOD-BLUE')
        ->and((float) $detail['items'][0]['unit_price'])->toBe(2000.0)
        ->and((int) $detail['items'][0]['quantity'])->toBe(2)
        ->and($detail['items'][0]['product_image_url'])->toContain('products/images/hoodie.jpg')
        ->and($detail['staff']['name'])->toBe('Chidi Cashier')
        ->and($detail['customer']['is_walk_in'])->toBeTrue()
        ->and($detail['customer']['name'])->toBe('Walk-in Buyer')
        ->and($detail['customer']['phone'])->toBe('08099998888')
        ->and($detail['delivery_route']['area'])->toBe('Lekki Phase 1')
        // Kobo, matching every other delivery-route payload.
        ->and((int) $detail['delivery_route']['fee'])->toBe(1500)
        ->and($detail['delivery_area'])->toBe('Lekki Phase 1')
        ->and($detail['delivery_state'])->toBe('Lagos')
        ->and($detail['delivery']['status'])->toBe('assigned')
        ->and($detail['delivery']['tracking_number'])->toBe('TRK-WS13-9')
        ->and($detail['delivery']['driver_name'])->toBe('Dan Driver')
        ->and($detail['transactions'][0]['bank']['bank_name'])->toBe('GTBank')
        ->and($detail['transactions'][0]['bank']['account_number'])->toBe('0123456789')
        ->and($detail['transactions'][0]['bank']['is_verified'])->toBeTrue()
        ->and($detail['activity'][0]['description'])->toBe('Order accepted')
        ->and($detail['activity'][0]['user'])->toBe($owner->name);
});

test('order detail is scoped to the business and to assigned stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws13Store($owner, $business, ['name' => 'Assigned']);
    $unassigned = ws13Store($owner, $business, ['name' => 'Unassigned']);
    $foreignStore = ws13Store($otherOwner, $otherBusiness, ['name' => 'Theirs']);

    $mine = ws13Order($assigned, ['order_number' => 'WS13-MINE']);
    $hidden = ws13Order($unassigned, ['order_number' => 'WS13-HIDDEN']);
    $theirs = ws13Order($foreignStore, ['order_number' => 'WS13-THEIRS']);

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

    $staffToken = ws13Token($staff);

    $this->withToken($staffToken)
        ->getJson('/api/v1/management/orders/'.$mine->order_number.'/detail')
        ->assertOk();

    $this->withToken($staffToken)
        ->getJson('/api/v1/management/orders/'.$hidden->order_number.'/detail')
        ->assertStatus(403);

    $this->withToken($staffToken)
        ->getJson('/api/v1/management/orders/board/list')
        ->assertOk()
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.order_number', 'WS13-MINE');

    $this->withToken($staffToken)
        ->getJson('/api/v1/management/orders/board/stats')
        ->assertOk()
        ->assertJsonPath('data.stats.total', 1);

    $this->withToken(ws13Token($otherOwner))
        ->getJson('/api/v1/management/orders/'.$mine->order_number.'/detail')
        ->assertStatus(403);

    // A role without orders view is refused the whole module.
    $clerk = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $clerk->assignRole('Inventory Clerk');

    $this->withToken(ws13Token($clerk))
        ->getJson('/api/v1/management/orders/board/list')
        ->assertStatus(403);

    expect($theirs->order_number)->toBe('WS13-THEIRS');
});

test('the order edit payload carries the items and the editable fields', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws13Store($owner, $business);

    $order = ws13Order($store, [
        'order_number' => 'WS13-EDITME',
        'subtotal' => 1000,
        'shipping_fee' => 100,
        'tax' => 50,
        'total' => 1175,
        'service_charge_amount' => 25,
        'notes' => 'Original note',
    ]);
    ws13Item($order);

    $this->withToken(ws13Token($owner))
        ->getJson('/api/v1/management/orders/WS13-EDITME/edit')
        ->assertOk()
        ->assertJsonPath('data.order.items.0.product_name', 'WS13 Widget')
        ->assertJsonPath('data.order.notes', 'Original note')
        ->assertJsonPath('data.order.shipping_fee', 100)
        ->assertJsonPath('data.order.tax', 50)
        ->assertJsonPath('data.order.subtotal', 1000);

    // View-only roles cannot open the editor.
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');
    $staff->assignedStores()->attach($store->id);

    $this->withToken(ws13Token($staff))
        ->getJson('/api/v1/management/orders/WS13-EDITME/edit')
        ->assertStatus(403);
});

test('updating an order recomputes the total server-side', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws13Store($owner, $business);

    $order = ws13Order($store, [
        'order_number' => 'WS13-UPDATE',
        'subtotal' => 1000,
        'shipping_fee' => 100,
        'tax' => 50,
        'total' => 1175,
        'service_charge_amount' => 25,
        'notes' => 'Original note',
    ]);

    $this->withToken(ws13Token($owner))
        ->putJson('/api/v1/management/orders/WS13-UPDATE', [
            'shipping_fee' => 200.50,
            'tax' => 75,
            'notes' => 'Packed and labelled.',
            // A client-supplied total must never be trusted.
            'total' => 1,
            'status' => 'completed',
        ])
        ->assertOk()
        ->assertJsonPath('data.order.notes', 'Packed and labelled.')
        ->assertJsonPath('data.order.total', 1300.5)
        ->assertJsonPath('data.order.shipping_fee', 200.5)
        ->assertJsonPath('data.order.tax', 75)
        // The edit form owns shipping/tax/notes only — status stays put.
        ->assertJsonPath('data.order.status', 'pending');

    $order->refresh();

    expect((float) $order->total)->toBe(1300.5)
        ->and((float) $order->shipping_fee)->toBe(200.5)
        ->and($order->notes)->toBe('Packed and labelled.');

    $log = ActivityLog::where('subject_type', Order::class)->where('subject_id', $order->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('updated')
        ->and((float) $log->old_values['total'])->toBe(1175.0)
        ->and((float) $log->new_values['total'])->toBe(1300.5);
});

test('order edits reject invalid pricing', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws13Store($owner, $business);
    $order = ws13Order($store, ['order_number' => 'WS13-INVALID']);

    $token = ws13Token($owner);

    $this->withToken($token)
        ->putJson('/api/v1/management/orders/WS13-INVALID', ['shipping_fee' => -1, 'tax' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('shipping_fee');

    $this->withToken($token)
        ->putJson('/api/v1/management/orders/WS13-INVALID', ['shipping_fee' => 0, 'tax' => 'not-a-number'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax');

    $this->withToken($token)
        ->putJson('/api/v1/management/orders/WS13-INVALID', [
            'shipping_fee' => 0,
            'tax' => 0,
            'notes' => str_repeat('x', 5001),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('notes');

    $this->withToken($token)
        ->putJson('/api/v1/management/orders/WS13-INVALID', ['tax' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('shipping_fee');
});

test('order edits are refused across businesses and across stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws13Store($owner, $business, ['name' => 'Mine']);
    $hiddenStore = ws13Store($owner, $business, ['name' => 'Hidden']);
    $foreignStore = ws13Store($otherOwner, $otherBusiness, ['name' => 'Theirs']);

    $order = ws13Order($store, ['order_number' => 'WS13-SAFE', 'total' => 1000]);
    $hidden = ws13Order($hiddenStore, ['order_number' => 'WS13-OFFLIMITS', 'total' => 1000]);

    $this->withToken(ws13Token($otherOwner))
        ->putJson('/api/v1/management/orders/WS13-SAFE', ['shipping_fee' => 999, 'tax' => 0])
        ->assertStatus(403);

    // A staff member who does hold orders edit is still bound to the stores
    // they are assigned to.
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');
    $staff->assignedStores()->attach($store->id);
    $staff->givePermissionTo('orders edit');

    $this->withToken(ws13Token($staff))
        ->putJson('/api/v1/management/orders/WS13-OFFLIMITS', ['shipping_fee' => 999, 'tax' => 0])
        ->assertStatus(403);

    $this->withToken(ws13Token($staff))
        ->putJson('/api/v1/management/orders/WS13-SAFE', ['shipping_fee' => 150, 'tax' => 0])
        ->assertOk();

    expect((float) $order->fresh()->total)->toBe(1150.0)
        ->and((float) $hidden->fresh()->total)->toBe(1000.0);
});

test('deleting an order needs the orders delete permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws13Store($owner, $business);

    $order = ws13Order($store, ['order_number' => 'WS13-DELETE']);

    // The permission was never seeded, so no role could hold it and the
    // shared DELETE route was unreachable. The WS-13 seeder fixes that.
    (new Ws13OrdersDeletePermissionSeeder)->run();

    $this->withToken(ws13Token($owner))
        ->deleteJson('/api/v1/management/orders/WS13-DELETE')
        ->assertOk();

    expect(Order::withTrashed()->where('order_number', 'WS13-DELETE')->first()->trashed())->toBeTrue();

    $kept = ws13Order($store, ['order_number' => 'WS13-KEEP']);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');
    $staff->assignedStores()->attach($store->id);

    $this->withToken(ws13Token($staff))
        ->deleteJson('/api/v1/management/orders/WS13-KEEP')
        ->assertStatus(403);

    expect($kept->fresh())->not->toBeNull();
});
