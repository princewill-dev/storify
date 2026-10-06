<?php

use App\Enums\TransferStatus;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-15 — Inventory transfers workflow
|--------------------------------------------------------------------------
*/

function ws15Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws15Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Warehouse '.Str::upper(Str::random(5)),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws15Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.Str::upper(Str::random(5)),
        'slug' => 'ws15-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws15Product(Store $store, array $attributes = []): Product
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

/**
 * A stock-location row at a warehouse or store. `$location` must be a
 * Warehouse or Store model.
 */
function ws15Stock(Warehouse|Store $location, Product $product, int $quantity, int $minQuantity = 0): StockLocation
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
 * A transfer built straight on the model, for tests that only exercise the
 * transition endpoints.
 *
 * @param  array<int, array{product_id: int, quantity: int}>  $items
 */
function ws15Transfer(User $owner, Warehouse|Store $from, Warehouse|Store $to, array $items, array $overrides = []): StockTransfer
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
        ]);
    }

    return $transfer;
}

/**
 * @return array{0: User, 1: Business, 2: Warehouse, 3: Store, 4: Product}
 */
function ws15Context(array $warehouseAttributes = []): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $warehouse = ws15Warehouse($owner, $warehouseAttributes);
    $store = ws15Store($owner);

    $product = ws15Product($store, [
        'name' => 'Core Widget',
        'warehouse_id' => $warehouse->id,
        'quantity' => 10,
    ]);

    return [$owner, $business, $warehouse, $store, $product];
}

test('the transfer list returns status tabs, counts and business-scoped rows', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    [, $otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 4]]);
    ws15Transfer($otherOwner, ws15Warehouse($otherOwner), ws15Store($otherOwner), []);

    $response = $this->withToken(ws15Token($owner))->getJson('/api/v1/management/transfers');

    $response->assertOk()
        ->assertJsonPath('data.transfers.0.transfer_code', $mine->transfer_code)
        ->assertJsonPath('data.transfers.0.status', 'pending')
        ->assertJsonPath('data.transfers.0.items_count', 1)
        ->assertJsonPath('data.transfers.0.total_units', 4)
        ->assertJsonPath('data.transfers.0.from.name', $warehouse->name)
        ->assertJsonPath('data.transfers.0.to.name', $store->name)
        ->assertJsonPath('data.stats.total', 1)
        ->assertJsonPath('data.stats.by_status.pending', 1);

    expect($response->json('data.transfers'))->toHaveCount(1)
        ->and(collect($response->json('data.statuses'))->pluck('value'))->toContain('awaiting_acknowledgment');
});

test('the transfer list filters by status', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 1]]);
    ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 1]], ['status' => TransferStatus::DRAFT]);

    $this->withToken(ws15Token($owner))
        ->getJson('/api/v1/management/transfers?status=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data.transfers')
        ->assertJsonPath('data.transfers.0.status', 'draft')
        ->assertJsonPath('data.stats.total', 2);
});

test('a draft transfer can be created with items', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $response = $this->withToken(ws15Token($owner))->postJson('/api/v1/management/transfers', [
        'from_location_type' => 'warehouse',
        'from_location_id' => $warehouse->id,
        'to_location_type' => 'store',
        'to_location_id' => $store->id,
        'notes' => 'Rebalance stock',
        'submitted' => false,
        'items' => [['product_id' => $product->id, 'quantity' => 3]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.transfer.status', 'draft')
        ->assertJsonPath('data.transfer.items.0.quantity', 3)
        ->assertJsonPath('data.transfer.notes', 'Rebalance stock');

    expect($response->json('message'))->toBe('Transfer saved as draft.');

    $this->assertDatabaseHas('stock_transfers', [
        'id' => $response->json('data.transfer.id'),
        'business_id' => $owner->business_id,
        'status' => 'draft',
    ]);

    // The legacy `transfer.created` activity row is what the timeline reads.
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'transfer.created',
        'subject_id' => $response->json('data.transfer.id'),
    ]);
});

test('a transfer can be submitted for approval on create', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $this->withToken(ws15Token($owner))
        ->postJson('/api/v1/management/transfers', [
            'from_location_type' => 'warehouse',
            'from_location_id' => $warehouse->id,
            'to_location_type' => 'store',
            'to_location_id' => $store->id,
            'submitted' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.transfer.status', 'pending');
});

test('creating a transfer validates locations and items', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    $token = ws15Token($owner);

    $this->withToken($token)->postJson('/api/v1/management/transfers', [
        'from_location_type' => 'warehouse',
        'from_location_id' => $warehouse->id,
        'to_location_type' => 'store',
        'to_location_id' => $store->id,
        'items' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('items');

    $this->withToken($token)->postJson('/api/v1/management/transfers', [
        'from_location_type' => 'warehouse',
        'from_location_id' => $warehouse->id,
        'to_location_type' => 'store',
        'to_location_id' => $store->id,
        'items' => [['product_id' => $product->id, 'quantity' => 0]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

    $this->withToken($token)->postJson('/api/v1/management/transfers', [
        'from_location_type' => 'warehouse',
        'from_location_id' => $warehouse->id,
        'to_location_type' => 'warehouse',
        'to_location_id' => $warehouse->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('to_location_id');
});

test('creating a transfer refuses another business location or product', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirWarehouse = ws15Warehouse($otherOwner);
    $theirStore = ws15Store($otherOwner);
    $theirProduct = ws15Product($theirStore);

    $token = ws15Token($owner);

    $this->withToken($token)->postJson('/api/v1/management/transfers', [
        'from_location_type' => 'warehouse',
        'from_location_id' => $theirWarehouse->id,
        'to_location_type' => 'store',
        'to_location_id' => $store->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('from_location_id');

    $this->withToken($token)->postJson('/api/v1/management/transfers', [
        'from_location_type' => 'warehouse',
        'from_location_id' => $warehouse->id,
        'to_location_type' => 'store',
        'to_location_id' => $store->id,
        'items' => [['product_id' => $theirProduct->id, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
});

test('a draft transfer can be submitted and only a draft can', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 1]], [
        'status' => TransferStatus::DRAFT,
    ]);

    $token = ws15Token($owner);

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/submit")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'pending');

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/submit")
        ->assertStatus(409);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'transfer.submitted',
        'subject_id' => $transfer->id,
    ]);
});

test('approving without adjustments approves directly', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]]);
    $item = $transfer->items()->first();

    $this->withToken(ws15Token($owner))
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/approve", [
            'approved_quantities' => [$item->id => 5],
        ])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'approved')
        ->assertJsonPath('data.transfer.items.0.approved_quantity', 5)
        ->assertJsonPath('data.transfer.items.0.adjusted', false);

    expect($transfer->fresh()->approved_by)->toBe($owner->id);
});

test('approving with adjusted quantities enters the acknowledgement loop', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 9]]);
    $item = $transfer->items()->first();
    $token = ws15Token($owner);

    // Clamped to the requested quantity — and therefore not an adjustment.
    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/approve", [
            'approved_quantities' => [$item->id => 99],
        ])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'approved')
        ->assertJsonPath('data.transfer.items.0.approved_quantity', 9);

    // Rebuild pending and genuinely lower a line: awaiting acknowledgment.
    $transfer->update(['status' => TransferStatus::PENDING]);

    $response = $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/approve", [
            'approved_quantities' => [$item->id => 4],
        ])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'awaiting_acknowledgment')
        ->assertJsonPath('data.transfer.items.0.approved_quantity', 4)
        ->assertJsonPath('data.transfer.items.0.adjusted', true);

    expect($response->json('message'))->toBe('Quantities adjusted and sent for acknowledgement.');

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'approved');

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'transfer.acknowledged',
        'subject_id' => $transfer->id,
    ]);
});

test('acknowledge is refused unless the transfer is awaiting acknowledgement', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 1]], [
        'status' => TransferStatus::APPROVED,
    ]);

    $this->withToken(ws15Token($owner))
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/acknowledge")
        ->assertStatus(409);
});

test('rejecting requires a reason and records the approver', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 1]]);
    $token = ws15Token($owner);

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/reject")
        ->assertStatus(422)
        ->assertJsonValidationErrors('rejection_reason');

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/reject", [
            'rejection_reason' => 'Damaged pallet',
        ])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'rejected')
        ->assertJsonPath('data.transfer.rejection_reason', 'Damaged pallet');

    expect($transfer->fresh()->approved_by)->toBe($owner->id);

    // A rejected transfer cannot be approved any more.
    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/approve")
        ->assertStatus(409);
});

test('dispatch surfaces every short line and rolls back', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 2);

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]], [
        'status' => TransferStatus::APPROVED,
    ]);

    $response = $this->withToken(ws15Token($owner))
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/dispatch");

    $response->assertStatus(422);
    expect($response->json('message'))
        ->toContain('Insufficient stock for "Core Widget"')
        ->toContain('2 available, 5 requested.');

    expect($transfer->fresh()->status)->toBe(TransferStatus::APPROVED)
        ->and((int) StockLocation::first()->quantity)->toBe(2)
        ->and(StockMovement::count())->toBe(0);
});

test('dispatch moves stock through the ledger and syncs the product', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    $stock = ws15Stock($warehouse, $product, 10);

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 4]], [
        'status' => TransferStatus::APPROVED,
    ]);

    $this->withToken(ws15Token($owner))
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/dispatch")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'dispatched')
        ->assertJsonPath('data.transfer.movements.0.type', 'removed')
        ->assertJsonPath('data.transfer.movements.0.balance_before', 10)
        ->assertJsonPath('data.transfer.movements.0.balance_after', 6);

    expect($transfer->fresh()->dispatched_by)->toBe($owner->id)
        ->and((int) $stock->fresh()->quantity)->toBe(6)
        ->and((int) $product->fresh()->quantity)->toBe(6)
        ->and(StockMovement::count())->toBe(1);
});

test('dispatch seeds a source stock location from the product quantity', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    // No StockLocation row: the management product form writes quantity bare.
    expect(StockLocation::count())->toBe(0);

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 3]], [
        'status' => TransferStatus::APPROVED,
    ]);

    $this->withToken(ws15Token($owner))
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/dispatch")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'dispatched');

    expect((int) StockLocation::where('locationable_type', Warehouse::class)->first()->quantity)->toBe(7)
        ->and((int) $product->fresh()->quantity)->toBe(7);
});

test('receive creates the destination stock location and adds the stock', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 10);

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 4]], [
        'status' => TransferStatus::DISPATCHED,
        'dispatched_by' => $owner->id,
    ]);

    $this->withToken(ws15Token($owner))
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/receive")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'received')
        ->assertJsonPath('data.transfer.movements.0.type', 'added')
        ->assertJsonPath('data.transfer.movements.0.balance_before', 0)
        ->assertJsonPath('data.transfer.movements.0.balance_after', 4);

    $destination = StockLocation::where('locationable_type', Store::class)->first();

    expect($destination)->not->toBeNull()
        ->and((int) $destination->quantity)->toBe(4)
        ->and($transfer->fresh()->received_by)->toBe($owner->id);

    $product->refresh();

    expect((int) $product->quantity)->toBe(14)
        ->and((int) $product->store_id)->toBe($store->id);
});

test('the full state machine runs from draft to received', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 8);
    // Keep the global count in step with the ledger stock for the assertion.
    $product->update(['quantity' => 8]);

    $token = ws15Token($owner);

    $code = $this->withToken($token)->postJson('/api/v1/management/transfers', [
        'from_location_type' => 'warehouse',
        'from_location_id' => $warehouse->id,
        'to_location_type' => 'store',
        'to_location_id' => $store->id,
        'submitted' => false,
        'items' => [['product_id' => $product->id, 'quantity' => 6]],
    ])->assertCreated()->json('data.transfer.transfer_code');

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$code}/submit")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'pending');

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$code}/approve")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'approved');

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$code}/dispatch")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'dispatched');

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$code}/receive")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'received');

    $transfer = StockTransfer::where('transfer_code', $code)->first();

    expect(StockMovement::where('reference_type', StockTransfer::class)->where('reference_id', $transfer->id)->count())->toBe(2)
        ->and((int) StockLocation::where('locationable_type', Warehouse::class)->first()->quantity)->toBe(2)
        ->and((int) StockLocation::where('locationable_type', Store::class)->first()->quantity)->toBe(6)
        ->and((int) $product->fresh()->quantity)->toBe(8);
});

test('a transfer can be cancelled before dispatch but not after', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();

    $draft = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 1]], [
        'status' => TransferStatus::DRAFT,
    ]);

    $token = ws15Token($owner);

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$draft->transfer_code}/cancel")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'cancelled');

    $dispatched = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 1]], [
        'status' => TransferStatus::DISPATCHED,
    ]);

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$dispatched->transfer_code}/cancel")
        ->assertStatus(409);
});

test('transfers from another business are not reachable', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirTransfer = ws15Transfer(
        $otherOwner,
        ws15Warehouse($otherOwner),
        ws15Store($otherOwner),
        [['product_id' => ws15Product(ws15Store($otherOwner))->id, 'quantity' => 1]],
        ['status' => TransferStatus::APPROVED],
    );

    $this->withToken(ws15Token($owner))
        ->getJson("/api/v1/management/transfers/{$theirTransfer->transfer_code}")
        ->assertStatus(403);

    $this->withToken(ws15Token($owner))
        ->patchJson("/api/v1/management/transfers/{$theirTransfer->transfer_code}/approve")
        ->assertStatus(403);

    // Also make sure the happy-path context itself is the owner's.
    expect($warehouse->business_id)->toBe($owner->business_id);
});

test('the detail payload carries items, timeline, movements and actions', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 12);

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 5]], [
        'status' => TransferStatus::APPROVED,
        'approved_by' => $owner->id,
    ]);
    $transfer->items()->first()->update(['approved_quantity' => 5]);

    $response = $this->withToken(ws15Token($owner))
        ->getJson("/api/v1/management/transfers/{$transfer->transfer_code}");

    $response->assertOk()
        ->assertJsonPath('data.transfer.items.0.available_at_source', 12)
        ->assertJsonPath('data.transfer.actions.dispatch', true)
        ->assertJsonPath('data.transfer.actions.receive', false)
        ->assertJsonPath('data.transfer.actions.approve', false)
        ->assertJsonStructure([
            'data' => ['transfer' => ['timeline', 'movements', 'actions', 'items', 'from', 'to']],
        ]);

    expect(collect($response->json('data.transfer.timeline'))->pluck('key'))
        ->toContain('created', 'approved', 'dispatched');
});

test('the source product grid lists stock locations and product-quantity fallbacks', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 4);

    $fallback = ws15Product($store, ['name' => 'Fallback Widget', 'warehouse_id' => $warehouse->id, 'quantity' => 7]);
    $elsewhere = ws15Product($store, ['name' => 'Elsewhere Widget', 'warehouse_id' => ws15Warehouse($owner)->id, 'quantity' => 3]);

    $response = $this->withToken(ws15Token($owner))->getJson(
        "/api/v1/management/transfers/source-products?location_type=warehouse&location_id={$warehouse->id}",
    );

    $response->assertOk()->assertJsonPath('data.location.type', 'warehouse');

    $products = collect($response->json('data.products'))->keyBy('name');

    expect($products)->toHaveKeys(['Core Widget', 'Fallback Widget'])
        ->and($products['Core Widget']['available'])->toBe(4)
        ->and($products['Fallback Widget']['available'])->toBe(7)
        ->and($products->has('Elsewhere Widget'))->toBeFalse();
});

test('the source product grid refuses another business location', function () {
    [$owner] = ws15Context();
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirWarehouse = ws15Warehouse($otherOwner);

    $this->withToken(ws15Token($owner))
        ->getJson("/api/v1/management/transfers/source-products?location_type=warehouse&location_id={$theirWarehouse->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('location_id');
});

test('the locations endpoint returns accessible warehouses and stores', function () {
    [$owner, , $warehouse, $store] = ws15Context();
    [, $otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $theirWarehouse = ws15Warehouse($otherOwner);

    $response = $this->withToken(ws15Token($owner))->getJson('/api/v1/management/transfers/locations');

    $response->assertOk();

    expect(collect($response->json('data.locations'))->pluck('name'))
        ->toContain($warehouse->name, $store->name)
        ->not->toContain($theirWarehouse->name);
});

test('a restricted staff member only reaches transfers touching an assigned location', function () {
    [$owner, $business, $warehouse, $store, $product] = ws15Context();
    $otherWarehouse = ws15Warehouse($owner, ['name' => 'Unassigned Warehouse']);
    ws15Stock($warehouse, $product, 10);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Warehouse Manager');
    $staff->assignedWarehouses()->attach($warehouse->id);

    // Warehouse Manager can view/submit/dispatch but not receive.
    $mine = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 2]], [
        'status' => TransferStatus::APPROVED,
    ]);
    $theirs = ws15Transfer($owner, $otherWarehouse, $store, [['product_id' => $product->id, 'quantity' => 2]], [
        'status' => TransferStatus::APPROVED,
    ]);

    $token = ws15Token($staff);

    $this->withToken($token)
        ->getJson("/api/v1/management/transfers/{$mine->transfer_code}")
        ->assertOk();

    $this->withToken($token)
        ->getJson("/api/v1/management/transfers/{$theirs->transfer_code}")
        ->assertStatus(403);

    $this->withToken($token)
        ->getJson('/api/v1/management/transfers')
        ->assertOk()
        ->assertJsonCount(1, 'data.transfers');

    // Dispatch from the assigned warehouse is allowed...
    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$mine->transfer_code}/dispatch")
        ->assertOk();

    // ...receiving needs `transfers receive`, which this role does not hold.
    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$mine->transfer_code}/receive")
        ->assertStatus(403);
});

test('a staff member with the inventory clerk role can dispatch and receive', function () {
    [$owner, $business, $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 6);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Inventory Clerk');
    // The clerk covers both ends of this transfer: sending from the warehouse
    // and receiving into the store.
    $staff->assignedWarehouses()->attach($warehouse->id);
    $staff->assignedStores()->attach($store->id);

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 3]], [
        'status' => TransferStatus::APPROVED,
    ]);

    $token = ws15Token($staff);

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/dispatch")
        ->assertOk();

    $this->withToken($token)
        ->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/receive")
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'received');
});

test('stock-locations reads the stock at one location', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 4, 2);

    $this->withToken(ws15Token($owner))
        ->getJson("/api/v1/management/stock-locations?source_type=warehouse&source_id={$warehouse->id}")
        ->assertOk()
        ->assertJsonPath('data.stock_locations.0.product.name', 'Core Widget')
        ->assertJsonPath('data.stock_locations.0.quantity', 4)
        ->assertJsonPath('data.stock_locations.0.min_quantity', 2)
        ->assertJsonPath('data.total_units', 4);

    $this->withToken(ws15Token($owner))
        ->getJson('/api/v1/management/stock-locations')
        ->assertStatus(422);
});

test('activity logs record every transition for the timeline', function () {
    [$owner, , $warehouse, $store, $product] = ws15Context();
    ws15Stock($warehouse, $product, 5);

    $transfer = ws15Transfer($owner, $warehouse, $store, [['product_id' => $product->id, 'quantity' => 2]]);
    $token = ws15Token($owner);

    $this->withToken($token)->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/approve")->assertOk();
    $this->withToken($token)->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/dispatch")->assertOk();
    $this->withToken($token)->patchJson("/api/v1/management/transfers/{$transfer->transfer_code}/receive")->assertOk();

    $actions = ActivityLog::where('subject_type', StockTransfer::class)
        ->where('subject_id', $transfer->id)
        ->pluck('action')
        ->all();

    expect($actions)->toContain('transfer.approved', 'transfer.dispatched', 'transfer.received');
});
