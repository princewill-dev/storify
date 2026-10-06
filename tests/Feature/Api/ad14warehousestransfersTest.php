<?php

use App\Enums\TransferStatus;
use App\Models\Product;
use App\Models\Section;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-14 — warehouses & stock transfers (admin console)
|--------------------------------------------------------------------------
|
| Covers the platform warehouse directory and detail, the platform transfer
| directory and detail, the five admin-scoped transitions that delegate to
| the management controller (including the admin-scoped cancel the audit
| asked for), the state-machine and validation refusals, and the audience
| boundaries that keep tenant-held admin tokens out.
*/

function ad14Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad14SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad14AdminWithRole(string $roleName): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole($roleName);

    return $user;
}

function ad14Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Warehouse '.Str::upper(Str::random(5)),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ad14Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.Str::upper(Str::random(5)),
        'slug' => 'ad14-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad14Product(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Widget '.Str::upper(Str::random(5)),
        'amount' => 1000,
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));
}

function ad14Stock(Warehouse|Store $location, Product $product, int $quantity, int $minQuantity = 0): StockLocation
{
    return StockLocation::create([
        'business_id' => $location->business_id,
        'product_id' => $product->id,
        'locationable_type' => $location::class,
        'locationable_id' => $location->getKey(),
        'quantity' => $quantity,
        'min_quantity' => $minQuantity,
    ]);
}

/**
 * A transfer built straight on the model.
 *
 * @param  array<int, array{product_id: int, quantity: int, approved_quantity?: int}>  $items
 */
function ad14Transfer(User $owner, Warehouse|Store $from, Warehouse|Store $to, array $items, array $overrides = []): StockTransfer
{
    $transfer = StockTransfer::create(array_merge([
        'business_id' => $owner->business_id,
        'from_location_type' => $from::class,
        'from_location_id' => $from->getKey(),
        'to_location_type' => $to::class,
        'to_location_id' => $to->getKey(),
        'requested_by' => $owner->id,
        'status' => TransferStatus::PENDING,
    ], $overrides));

    foreach ($items as $item) {
        StockTransferItem::create([
            'stock_transfer_id' => $transfer->id,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'approved_quantity' => $item['approved_quantity'] ?? null,
        ]);
    }

    return $transfer;
}

/**
 * @return array{0: User, 1: Warehouse, 2: Store, 3: Product}
 */
function ad14Context(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $warehouse = ad14Warehouse($owner, ['name' => 'Central Depot']);
    $store = ad14Store($owner, ['name' => 'Ikeja Store']);
    $product = ad14Product($store, ['name' => 'Core Widget', 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

    ad14Stock($warehouse, $product, 10);

    return [$owner->fresh(), $warehouse, $store, $product];
}

// --- Warehouse directory ----------------------------------------------------

test('the warehouse directory lists every business, excludes deleted by default and filters by status, q and business', function () {
    $admin = ad14SuperAdmin();
    [$ownerA] = createBusinessOwner([], ['name' => 'Alpha Traders']);
    [$ownerB] = createBusinessOwner([], ['name' => 'Zenith Retail']);

    $mineA = ad14Warehouse($ownerA, ['name' => 'Abeokuta Depot']);
    ad14Warehouse($ownerA, ['name' => 'Gone Depot', 'status' => Warehouse::STATUS_DELETED]);
    $theirs = ad14Warehouse($ownerB, ['name' => 'Zaria Hub']);

    $store = ad14Store($ownerA);
    $product = ad14Product($store);
    ad14Stock($mineA, $product, 12);

    Section::create(['business_id' => $ownerA->business_id, 'warehouse_id' => $mineA->id, 'name' => 'Bay A']);

    $token = ad14Token($admin);

    $response = $this->withToken($token)->getJson('/api/v1/admin/warehouses')->assertOk();

    expect(array_column($response->json('data.warehouses'), 'name'))
        ->toContain('Abeokuta Depot')
        ->toContain('Zaria Hub')
        ->not->toContain('Gone Depot');

    $row = collect($response->json('data.warehouses'))->firstWhere('name', 'Abeokuta Depot');
    expect($row['business']['name'])->toBe($ownerA->business->name)
        ->and($row['owner']['name'])->toBe($ownerA->name)
        ->and($row['total_stock'])->toBe(12)
        ->and($row['stock_items'])->toBe(1)
        ->and($row['sections_count'])->toBe(1)
        ->and($row['status'])->toBe('active');

    // Deleted rows are reachable through the filter (legacy's option worked).
    $deleted = $this->withToken($token)->getJson('/api/v1/admin/warehouses?status=deleted')->assertOk();
    expect(array_column($deleted->json('data.warehouses'), 'name'))->toBe(['Gone Depot']);

    // q matches name, code or business name.
    $byBusiness = $this->withToken($token)->getJson('/api/v1/admin/warehouses?q='.urlencode($ownerB->business->name))->assertOk();
    expect(array_column($byBusiness->json('data.warehouses'), 'name'))->toBe(['Zaria Hub']);

    $byCode = $this->withToken($token)->getJson('/api/v1/admin/warehouses?q='.$theirs->warehouse_code)->assertOk();
    expect(array_column($byCode->json('data.warehouses'), 'name'))->toBe(['Zaria Hub']);

    expect($response->json('data.statuses'))->toHaveCount(3)
        ->and($response->json('data.stats.by_status.active'))->toBe(2)
        ->and($response->json('data.stats.by_status.deleted'))->toBe(1);
});

test('the warehouse directory paginates at 15 per page like legacy', function () {
    $admin = ad14SuperAdmin();
    [$owner] = createBusinessOwner();

    foreach (range(1, 16) as $index) {
        ad14Warehouse($owner, ['name' => sprintf('Depot %02d', $index)]);
    }

    $response = $this->withToken(ad14Token($admin))->getJson('/api/v1/admin/warehouses')->assertOk();

    expect($response->json('meta.per_page'))->toBe(15)
        ->and($response->json('meta.total'))->toBe(16)
        ->and($response->json('data.warehouses'))->toHaveCount(15);
});

test('the warehouse detail reports the legacy metric tiles, sections and the last 15 movements', function () {
    $admin = ad14SuperAdmin();
    [$owner] = createBusinessOwner();
    $warehouse = ad14Warehouse($owner, [
        'name' => 'Lekki Depot',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'contact_person' => 'Ada',
        'contact_phone' => '08030000000',
        'description' => 'Overflow stock',
    ]);

    $store = ad14Store($owner);
    $low = ad14Product($store, ['name' => 'Low Item']);
    $empty = ad14Product($store, ['name' => 'Empty Item']);
    $healthy = ad14Product($store, ['name' => 'Healthy Item']);

    ad14Stock($warehouse, $low, 4);
    ad14Stock($warehouse, $empty, 0);
    ad14Stock($warehouse, $healthy, 50);

    $section = Section::create(['business_id' => $owner->business_id, 'warehouse_id' => $warehouse->id, 'name' => 'Bay A']);
    Product::query()->whereKey($healthy->id)->update(['section_id' => $section->id]);

    // 16 outgoing movements on this warehouse plus one incoming; only the
    // latest 15 touching the warehouse may come back.
    foreach (range(1, 16) as $index) {
        StockMovement::create([
            'business_id' => $owner->business_id,
            'product_id' => $healthy->id,
            'from_location_type' => Warehouse::class,
            'from_location_id' => $warehouse->id,
            'quantity' => $index,
            'type' => StockMovement::TYPE_ADJUSTED,
        ]);
    }

    $incoming = StockMovement::create([
        'business_id' => $owner->business_id,
        'product_id' => $low->id,
        'to_location_type' => Warehouse::class,
        'to_location_id' => $warehouse->id,
        'quantity' => 7,
        'type' => StockMovement::TYPE_ADDED,
    ]);

    $response = $this->withToken(ad14Token($admin))
        ->getJson('/api/v1/admin/warehouses/'.$warehouse->warehouse_code)
        ->assertOk();

    expect($response->json('data.warehouse.total_stock'))->toBe(54)
        ->and($response->json('data.warehouse.low_stock_count'))->toBe(1)
        ->and($response->json('data.warehouse.stock_items'))->toBe(3)
        ->and($response->json('data.warehouse.sections_count'))->toBe(1)
        ->and($response->json('data.warehouse.low_stock_threshold'))->toBe(10)
        ->and($response->json('data.warehouse.city'))->toBe('Lagos')
        ->and($response->json('data.warehouse.contact_person'))->toBe('Ada')
        ->and($response->json('data.warehouse.business.name'))->toBe($owner->business->name)
        ->and($response->json('data.warehouse.owner.email'))->toBe($owner->email);

    expect($response->json('data.warehouse.sections'))->toHaveCount(1)
        ->and($response->json('data.warehouse.sections.0.name'))->toBe('Bay A')
        ->and($response->json('data.warehouse.sections.0.products_count'))->toBe(1)
        ->and($response->json('data.warehouse.sections.0.status'))->toBe('active');

    $movements = collect($response->json('data.recent_movements'));
    expect($movements)->toHaveCount(15);

    $latest = $movements->firstWhere('id', $incoming->id);
    expect($latest['direction'])->toBe('in')
        ->and($latest['signed_quantity'])->toBe(7)
        ->and($latest['product'])->toBe('Low Item');

    $outgoing = $movements->firstWhere('direction', 'out');
    expect($outgoing['signed_quantity'])->toBeLessThan(0);
});

test('a deleted warehouse is still inspectable and an unknown code is a 404', function () {
    $admin = ad14SuperAdmin();
    [$owner] = createBusinessOwner();
    $warehouse = ad14Warehouse($owner, ['status' => Warehouse::STATUS_DELETED]);

    $this->withToken(ad14Token($admin))
        ->getJson('/api/v1/admin/warehouses/'.$warehouse->warehouse_code)
        ->assertOk()
        ->assertJsonPath('data.warehouse.status', 'deleted');

    $this->withToken(ad14Token($admin))
        ->getJson('/api/v1/admin/warehouses/whs_does_not_exist')
        ->assertNotFound();
});

// --- Transfer directory & detail -------------------------------------------

test('the transfer directory is platform-wide and filters over all eight statuses and location names', function () {
    $admin = ad14SuperAdmin();
    [$ownerA, $warehouseA, $storeA, $productA] = ad14Context();
    [$ownerB] = createBusinessOwner();
    $warehouseB = ad14Warehouse($ownerB, ['name' => 'B Depot']);
    $storeB = ad14Store($ownerB, ['name' => 'B Store']);
    $productB = ad14Product($storeB);

    $mine = ad14Transfer($ownerA, $warehouseA, $storeA, [['product_id' => $productA->id, 'quantity' => 3]]);
    $theirs = ad14Transfer($ownerB, $warehouseB, $storeB, [['product_id' => $productB->id, 'quantity' => 2]]);
    ad14Transfer($ownerA, $warehouseA, $storeA, [['product_id' => $productA->id, 'quantity' => 1]], ['status' => TransferStatus::DRAFT]);

    $token = ad14Token($admin);

    $response = $this->withToken($token)->getJson('/api/v1/admin/transfers')->assertOk();

    expect(array_column($response->json('data.transfers'), 'transfer_code'))
        ->toContain($mine->transfer_code)
        ->toContain($theirs->transfer_code);
    expect($response->json('data.statuses'))->toHaveCount(8);
    expect($response->json('data.stats.by_status.pending'))->toBe(2)
        ->and($response->json('data.stats.by_status.draft'))->toBe(1);

    $filtered = $this->withToken($token)->getJson('/api/v1/admin/transfers?status=draft')->assertOk();
    expect(array_column($filtered->json('data.transfers'), 'transfer_code'))->not->toContain($mine->transfer_code);

    $byCode = $this->withToken($token)->getJson('/api/v1/admin/transfers?q='.$theirs->transfer_code)->assertOk();
    expect(array_column($byCode->json('data.transfers'), 'transfer_code'))->toBe([$theirs->transfer_code]);

    $byLocation = $this->withToken($token)->getJson('/api/v1/admin/transfers?q='.urlencode('B Depot'))->assertOk();
    expect(array_column($byLocation->json('data.transfers'), 'transfer_code'))->toBe([$theirs->transfer_code]);

    $row = collect($response->json('data.transfers'))->firstWhere('transfer_code', $mine->transfer_code);
    expect($row['items_count'])->toBe(1)
        ->and($row['total_units'])->toBe(3)
        ->and($row['requested_by'])->toBe($ownerA->name)
        ->and($row['business']['business_code'])->toBe($ownerA->business->business_code)
        ->and($row['from']['type'])->toBe('warehouse')
        ->and($row['to']['type'])->toBe('store');
});

test('the transfer detail carries items, timeline, business and admin action flags', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();

    $transfer = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]]);

    $response = $this->withToken(ad14Token($admin))
        ->getJson('/api/v1/admin/transfers/'.$transfer->transfer_code)
        ->assertOk();

    expect($response->json('data.transfer.status'))->toBe('pending')
        ->and($response->json('data.transfer.status_label'))->toBe('Pending')
        ->and($response->json('data.transfer.business.name'))->toBe($owner->business->name)
        ->and($response->json('data.transfer.actions.approve'))->toBeTrue()
        ->and($response->json('data.transfer.actions.reject'))->toBeTrue()
        ->and($response->json('data.transfer.actions.cancel'))->toBeTrue()
        ->and($response->json('data.transfer.actions.dispatch'))->toBeFalse()
        ->and($response->json('data.transfer.actions.receive'))->toBeFalse();

    expect($response->json('data.transfer.items.0.quantity'))->toBe(5)
        ->and($response->json('data.transfer.items.0.approved_quantity'))->toBeNull()
        ->and($response->json('data.transfer.items.0.adjusted'))->toBeFalse()
        ->and($response->json('data.transfer.items.0.available_at_source'))->toBe(10);

    $created = collect($response->json('data.transfer.timeline'))->firstWhere('key', 'created');
    expect($created['done'])->toBeTrue()->and($created['who'])->toBe($owner->name);
});

// --- Approve / reject -------------------------------------------------------

test('approving every line at its requested quantity approves the transfer and stamps the admin as approver', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();
    $transfer = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]]);
    $item = $transfer->items()->firstOrFail();

    $response = $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/approve', [
            'approved_quantities' => [$item->id => 5],
        ])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'approved')
        ->assertJsonPath('data.transfer.approved_by', $admin->name);

    // The delegated management path records the acting admin on the timeline,
    // not the business owner whose tenant context carried the call.
    $approved = collect($response->json('data.transfer.timeline'))->firstWhere('key', 'approved');
    expect($approved['done'])->toBeTrue()->and($approved['who'])->toBe($admin->name);

    expect($transfer->fresh()->approved_by)->toBe($admin->id);
});

test('approving reduced quantities clamps to the requested quantity and sends the transfer for acknowledgement', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();
    $transfer = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]]);
    $item = $transfer->items()->firstOrFail();

    $response = $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/approve', [
            'approved_quantities' => [$item->id => 2],
        ])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'awaiting_acknowledgment');

    expect($response->json('data.transfer.items.0.approved_quantity'))->toBe(2)
        ->and($response->json('data.transfer.items.0.adjusted'))->toBeTrue();

    // A value above the requested quantity is clamped, never accepted.
    $transfer2 = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]]);
    $item2 = $transfer2->items()->firstOrFail();

    $clamped = $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer2->transfer_code.'/approve', [
            'approved_quantities' => [$item2->id => 99],
        ])
        ->assertOk();

    expect($clamped->json('data.transfer.items.0.approved_quantity'))->toBe(5)
        ->and($clamped->json('data.transfer.items.0.adjusted'))->toBeFalse()
        ->and($clamped->json('data.transfer.status'))->toBe('approved');
});

test('an approve quantity below one is rejected by validation', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();
    $transfer = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]]);
    $item = $transfer->items()->firstOrFail();

    $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/approve', [
            'approved_quantities' => [$item->id => 0],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('approved_quantities.'.$item->id);

    expect($transfer->fresh()->status)->toBe(TransferStatus::PENDING);
});

test('rejecting requires a reason and records it against the admin', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();
    $transfer = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]]);

    $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/reject', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rejection_reason');

    $response = $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/reject', [
            'rejection_reason' => 'Stock already allocated elsewhere.',
        ])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'rejected')
        ->assertJsonPath('data.transfer.rejection_reason', 'Stock already allocated elsewhere.')
        ->assertJsonPath('data.transfer.approved_by', $admin->name);

    $rejected = collect($response->json('data.transfer.timeline'))->firstWhere('key', 'rejected');
    expect($rejected['done'])->toBeTrue()->and($rejected['who'])->toBe($admin->name);
});

// --- Dispatch / receive / cancel -------------------------------------------

test('dispatching moves stock through the ledger and attributes the admin', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();

    $transfer = ad14Transfer($owner, $warehouse, $store, [
        ['product_id' => $product->id, 'quantity' => 4, 'approved_quantity' => 4],
    ], ['status' => TransferStatus::APPROVED]);

    $response = $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/dispatch')
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'dispatched')
        ->assertJsonPath('data.transfer.dispatched_by', $admin->name);

    $movement = StockMovement::query()
        ->where('reference_type', StockTransfer::class)
        ->where('reference_id', $transfer->id)
        ->firstOrFail();

    expect($movement->type)->toBe(StockMovement::TYPE_REMOVED)
        ->and((int) $movement->performed_by_id)->toBe($admin->id)
        ->and((int) $movement->from_location_id)->toBe($warehouse->id)
        // Dispatch records the departure only: a removal movement names its
        // source and never a destination. The destination side of the ledger is
        // written by the receive step (a separate TYPE_ADDED row), exactly as it
        // is for every other removal writer (POS sales, storefront checkout).
        ->and($movement->to_location_id)->toBeNull()
        ->and($movement->to_location_type)->toBeNull();

    expect((int) StockLocation::query()
        ->where('product_id', $product->id)
        ->where('locationable_type', Warehouse::class)
        ->where('locationable_id', $warehouse->id)
        ->value('quantity'))->toBe(6);

    expect((int) $product->fresh()->quantity)->toBe(6);
    expect($transfer->fresh()->dispatched_by)->toBe($admin->id);

    $dispatched = collect($response->json('data.transfer.timeline'))->firstWhere('key', 'dispatched');
    expect($dispatched['who'])->toBe($admin->name);
});

test('dispatch refuses a short source and reports the offending line', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();

    // Drain the source below the approved line.
    StockLocation::query()
        ->where('product_id', $product->id)
        ->where('locationable_type', Warehouse::class)
        ->where('locationable_id', $warehouse->id)
        ->update(['quantity' => 1]);

    $transfer = ad14Transfer($owner, $warehouse, $store, [
        ['product_id' => $product->id, 'quantity' => 5, 'approved_quantity' => 5],
    ], ['status' => TransferStatus::APPROVED]);

    $response = $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/dispatch')
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Insufficient stock');
    expect($transfer->fresh()->status)->toBe(TransferStatus::APPROVED);
});

test('receiving adds the approved quantity to the destination and attributes the admin', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();

    $transfer = ad14Transfer($owner, $warehouse, $store, [
        ['product_id' => $product->id, 'quantity' => 4, 'approved_quantity' => 3],
    ], ['status' => TransferStatus::DISPATCHED]);

    $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/receive')
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'received')
        ->assertJsonPath('data.transfer.received_by', $admin->name)
        ->assertJsonPath('data.transfer.items.0.approved_quantity', 3);

    $destination = StockLocation::query()
        ->where('product_id', $product->id)
        ->where('locationable_type', Store::class)
        ->where('locationable_id', $store->id)
        ->firstOrFail();

    expect((int) $destination->quantity)->toBe(3);

    $movement = StockMovement::query()
        ->where('reference_type', StockTransfer::class)
        ->where('reference_id', $transfer->id)
        ->firstOrFail();

    // The destination leg of the ledger is this addition movement, just as the
    // dispatch leg's removal names only its source: the two columns of a
    // transfer are recorded by the two movements, one per side.
    expect($movement->type)->toBe(StockMovement::TYPE_ADDED)
        ->and((int) $movement->performed_by_id)->toBe($admin->id)
        ->and((int) $movement->to_location_id)->toBe($store->id)
        ->and($movement->to_location_type)->toBe(Store::class);

    expect((int) $product->fresh()->quantity)->toBe(13)
        ->and((int) $product->fresh()->store_id)->toBe($store->id);
});

test('the admin-scoped cancel route cancels and terminal transfers refuse further transitions', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();
    $transfer = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 3]]);

    // The audit's bug #13: the admin cancel must be an admin route.
    expect(Route::has('api.admin.transfers.cancel'))->toBeTrue();

    $response = $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'cancelled')
        ->assertJsonPath('data.transfer.actions.cancel', false);

    $cancelled = collect($response->json('data.transfer.timeline'))->firstWhere('key', 'cancelled');
    expect($cancelled['done'])->toBeTrue()->and($cancelled['who'])->toBe($admin->name);

    // A cancelled transfer is terminal for every action.
    $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/approve', [])
        ->assertStatus(409);

    $this->withToken(ad14Token($admin))
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/cancel')
        ->assertStatus(409);
});

test('a received transfer refuses approve, reject, dispatch and receive', function () {
    $admin = ad14SuperAdmin();
    [$owner, $warehouse, $store, $product] = ad14Context();
    $transfer = ad14Transfer($owner, $warehouse, $store, [
        ['product_id' => $product->id, 'quantity' => 2, 'approved_quantity' => 2],
    ], ['status' => TransferStatus::RECEIVED]);

    $token = ad14Token($admin);

    $this->withToken($token)
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/approve', [])
        ->assertStatus(409);

    $this->withToken($token)
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/reject', ['rejection_reason' => 'nope'])
        ->assertStatus(409);

    $this->withToken($token)
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/dispatch')
        ->assertStatus(409);

    $this->withToken($token)
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/receive')
        ->assertStatus(409);
});

// --- Audience boundaries ----------------------------------------------------

test('a platform admin without the warehouses permission is refused', function () {
    $admin = ad14AdminWithRole('Finance Admin');
    [$owner] = createBusinessOwner();
    ad14Warehouse($owner);

    $token = ad14Token($admin);

    $this->withToken($token)->getJson('/api/v1/admin/warehouses')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/admin/transfers')->assertStatus(403);
});

test('a platform admin with the warehouses permission can read both directories', function () {
    $admin = ad14AdminWithRole('Platform Admin');
    [$owner] = createBusinessOwner();
    ad14Warehouse($owner);

    $token = ad14Token($admin);

    $this->withToken($token)->getJson('/api/v1/admin/warehouses')->assertOk();
    $this->withToken($token)->getJson('/api/v1/admin/transfers')->assertOk();
});

test('a business-scoped account with a leaked admin token is refused by the platform guard', function () {
    [$owner, $warehouse, $store, $product] = ad14Context();
    $transfer = ad14Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 2]]);

    // The in-business "Super Admin" role carries the admin.* permission
    // strings, so the permission middleware alone would let this through; the
    // platform-role guard is what refuses it — hence the message assertion.
    $guardMessage = 'This endpoint is restricted to platform administrators.';
    $token = ad14Token($owner);

    $this->withToken($token)
        ->getJson('/api/v1/admin/warehouses')
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)
        ->getJson('/api/v1/admin/warehouses/'.$warehouse->warehouse_code)
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)
        ->getJson('/api/v1/admin/transfers')
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)
        ->patchJson('/api/v1/admin/transfers/'.$transfer->transfer_code.'/approve', [])
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    // Nothing moved.
    expect($transfer->fresh()->status)->toBe(TransferStatus::PENDING);
});

test('a management-audience token cannot reach the admin warehouse routes', function () {
    [$owner] = createBusinessOwner();

    $token = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/admin/warehouses')
        ->assertStatus(403)
        ->assertJsonPath('message', 'This token is not valid for this application.');
});

test('guests are refused', function () {
    $this->getJson('/api/v1/admin/warehouses')->assertStatus(401);
    $this->getJson('/api/v1/admin/transfers')->assertStatus(401);
});
