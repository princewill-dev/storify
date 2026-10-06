<?php

use App\Enums\SectionStatus;
use App\Models\Product;
use App\Models\Section;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;

function ws36Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws36Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Depot '.random_int(100, 999),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws36Section(Warehouse $warehouse, array $attributes = []): Section
{
    return Section::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'business_id' => $warehouse->business_id,
        'name' => 'Aisle A',
        'status' => Section::STATUS_ACTIVE,
    ], $attributes));
}

function ws36Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Shop '.random_int(100, 999),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

/**
 * Store-less rows skip the Product model's quantity/amount guard, which is how
 * a warehouse-less or zero-stock product is reachable in tests.
 */
function ws36Product(User $owner, array $attributes = []): Product
{
    return Product::create(array_merge([
        'business_id' => $owner->business_id,
        'name' => 'Widget '.random_int(100, 999),
        'amount' => 100,
        'quantity' => 1,
        'status' => 'active',
    ], $attributes));
}

test('the section list returns product counts and hides deleted sections', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);

    $aisle = ws36Section($warehouse, ['name' => 'Aisle A']);
    ws36Section($warehouse, ['name' => 'Gone', 'status' => Section::STATUS_DELETED]);
    ws36Section(ws36Warehouse($owner), ['name' => 'Other warehouse']);

    ws36Product($owner, ['section_id' => $aisle->id, 'status' => 'active']);
    ws36Product($owner, ['section_id' => $aisle->id, 'status' => 'inactive']);

    $response = $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections");

    $response->assertOk()
        ->assertJsonCount(1, 'data.sections')
        ->assertJsonPath('data.sections.0.name', 'Aisle A')
        ->assertJsonPath('data.sections.0.products_count', 2)
        ->assertJsonPath('data.sections.0.active_products_count', 1)
        ->assertJsonPath('data.stats.total', 1)
        ->assertJsonPath('data.stats.active', 1)
        ->assertJsonPath('meta.total', 1);
});

test('the section list refuses another business warehouse', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws36Warehouse($otherOwner);

    $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/warehouses/{$theirs->warehouse_code}/sections")
        ->assertStatus(403);
});

test('a section is created with a generated code and status', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);

    $response = $this->withToken(ws36Token($owner))
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections", [
            'name' => 'Cold Room',
            'description' => 'Chilled goods',
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.section.name', 'Cold Room')
        ->assertJsonPath('data.section.status', 'active')
        ->assertJsonPath('data.section.warehouse.id', $warehouse->id)
        ->assertJsonPath('data.section.products_count', 0);

    expect($response->json('data.section.section_code'))->toStartWith('sec_');

    $this->withToken(ws36Token($owner))
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections", [
            'name' => 'Back Room',
            'is_active' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('data.section.status', 'inactive');
});

test('a section requires a name', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);

    $this->withToken(ws36Token($owner))
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections", [
            'name' => '',
            'description' => str_repeat('x', 501),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'description']);

    expect(Section::count())->toBe(0);
});

test('section detail reports the legacy metrics and its products', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);
    $store = ws36Store($owner);

    $active = ws36Product($owner, [
        'store_id' => $store->id, 'section_id' => $section->id,
        'name' => 'Alpha', 'amount' => 1000.50, 'quantity' => 4, 'status' => 'active',
    ]);
    ws36Product($owner, [
        'store_id' => $store->id, 'section_id' => $section->id,
        'name' => 'Beta', 'amount' => 250.25, 'quantity' => 2, 'status' => 'inactive',
    ]);
    ws36Product($owner, ['section_id' => $section->id, 'name' => 'Gamma', 'amount' => 0, 'quantity' => 0]);

    // Another section's product must not leak into these numbers.
    ws36Product($owner, ['section_id' => ws36Section($warehouse, ['name' => 'Bay B'])->id, 'amount' => 99, 'quantity' => 9]);

    $response = $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}");

    $response->assertOk()
        ->assertJsonPath('data.section.id', $section->id)
        ->assertJsonPath('data.section.products_count', 3)
        ->assertJsonPath('data.stats.products_count', 3)
        ->assertJsonPath('data.stats.active_products_count', 2)
        ->assertJsonPath('data.stats.stock_count', 6)
        ->assertJsonPath('data.stats.stock_value_kobo', 125075)
        ->assertJsonPath('data.stats.stock_value', '1250.75')
        ->assertJsonPath('data.stats.out_of_stock_count', 1)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(3, 'data.products');

    expect(collect($response->json('data.products'))->pluck('name')->all())->toBe(['Alpha', 'Beta', 'Gamma']);

    $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}?status=active")
        ->assertOk()
        ->assertJsonCount(2, 'data.products');

    // The first product's price survives the naira→kobo conversion exactly.
    expect((float) $active->fresh()->amount)->toBe(1000.50);
});

test('a section from another warehouse of the same business is not reachable under this warehouse', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $otherWarehouse = ws36Warehouse($owner, ['name' => 'Second Depot']);
    $section = ws36Section($otherWarehouse);

    $base = "/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}";

    $this->withToken(ws36Token($owner))->getJson($base)->assertStatus(404);
    $this->withToken(ws36Token($owner))->putJson($base, ['name' => 'Hijack'])->assertStatus(404);
    $this->withToken(ws36Token($owner))->deleteJson($base)->assertStatus(404);

    expect($section->fresh()->name)->toBe('Aisle A')
        ->and($section->fresh()->status)->toBe(SectionStatus::ACTIVE);
});

test('another business section is refused even through my own warehouse', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws36Warehouse($owner);
    $theirSection = ws36Section(ws36Warehouse($otherOwner));

    $base = "/api/v1/management/warehouses/{$mine->warehouse_code}/sections/{$theirSection->section_code}";

    $this->withToken(ws36Token($owner))->getJson($base)->assertStatus(403);
    $this->withToken(ws36Token($owner))->putJson($base, ['name' => 'Hijack'])->assertStatus(403);
    $this->withToken(ws36Token($owner))->deleteJson($base)->assertStatus(403);

    expect($theirSection->fresh()->name)->toBe('Aisle A');
});

test('a section can be renamed and deactivated', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);

    $response = $this->withToken(ws36Token($owner))
        ->putJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}", [
            'name' => 'Renamed Bay',
            'description' => 'Now on the west wall',
            'is_active' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.section.name', 'Renamed Bay')
        ->assertJsonPath('data.section.description', 'Now on the west wall')
        ->assertJsonPath('data.section.status', 'inactive')
        ->assertJsonPath('data.section.section_code', $section->section_code);
});

test('a section holding products cannot be deleted', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);

    ws36Product($owner, ['section_id' => $section->id]);

    $this->withToken(ws36Token($owner))
        ->deleteJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete a section with products.');

    expect($section->fresh()->status)->toBe(SectionStatus::ACTIVE);
});

test('an empty section is soft-deleted and disappears from the list and picker', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);

    $this->withToken(ws36Token($owner))
        ->deleteJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}")
        ->assertOk();

    expect($section->fresh()->status)->toBe(SectionStatus::DELETED)
        ->and(Section::whereKey($section->id)->exists())->toBeTrue();

    $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections")
        ->assertOk()
        ->assertJsonCount(0, 'data.sections');

    $this->withToken(ws36Token($owner))
        ->getJson('/api/v1/management/sections')
        ->assertOk()
        ->assertJsonCount(0, 'data.sections');

    // A deleted section is gone, not editable.
    $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}")
        ->assertStatus(404);
});

test('the picker returns reachable sections and filters by warehouse', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $depotA = ws36Warehouse($owner, ['name' => 'Depot A']);
    $depotB = ws36Warehouse($owner, ['name' => 'Depot B']);

    ws36Section($depotA, ['name' => 'Aisle A']);
    ws36Section($depotA, ['name' => 'Aisle gone', 'status' => Section::STATUS_DELETED]);
    ws36Section($depotB, ['name' => 'Bay B']);
    ws36Section(ws36Warehouse($otherOwner), ['name' => 'Their aisle']);

    $this->withToken(ws36Token($owner))
        ->getJson('/api/v1/management/sections')
        ->assertOk()
        ->assertJsonCount(2, 'data.sections')
        ->assertJsonPath('data.sections.0.warehouse_name', 'Depot A');

    $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/sections?warehouse_id={$depotB->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.sections')
        ->assertJsonPath('data.sections.0.name', 'Bay B');

    $theirWarehouse = ws36Warehouse($otherOwner, ['name' => 'Theirs']);

    $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/sections?warehouse_id={$theirWarehouse->id}")
        ->assertStatus(403);
});

test('section permissions follow the warehouse group and warehouse assignment', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $unassigned = ws36Warehouse($owner, ['name' => 'Unassigned Depot']);
    $section = ws36Section($warehouse);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Warehouse Manager');
    $staff->assignedWarehouses()->attach($warehouse->id);

    $token = ws36Token($staff);

    // Warehouse Manager holds warehouses view/create/edit and products view.
    $this->withToken($token)
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections")
        ->assertOk();

    $this->withToken($token)
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections", ['name' => 'Staff Bay'])
        ->assertCreated();

    $this->withToken($token)
        ->putJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}", ['name' => 'Staff Rename'])
        ->assertOk();

    // ...but not `warehouses delete`.
    $this->withToken($token)
        ->deleteJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}")
        ->assertStatus(403);

    // ...and not `products edit`, which assignment writes require.
    $this->withToken($token)
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}/products", [
            'product_ids' => [ws36Product($owner, ['warehouse_id' => $warehouse->id])->id],
        ])
        ->assertStatus(403);

    // A warehouse the staff is not assigned to stays out of reach.
    $this->withToken($token)
        ->getJson("/api/v1/management/warehouses/{$unassigned->warehouse_code}/sections")
        ->assertStatus(403);
});

test('a product created with a section and no warehouse inherits the section warehouse', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);
    $store = ws36Store($owner);

    $this->withToken(ws36Token($owner))
        ->postJson('/api/v1/management/products', [
            'name' => 'Filed Widget',
            'store_id' => $store->id,
            'amount' => 1500,
            'quantity' => 3,
            'section_id' => $section->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.product.section_id', $section->id)
        ->assertJsonPath('data.product.warehouse_id', $warehouse->id);
});

test('a product cannot be filed into another business section', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws36Store($owner);
    $theirSection = ws36Section(ws36Warehouse($otherOwner));

    $this->withToken(ws36Token($owner))
        ->postJson('/api/v1/management/products', [
            'name' => 'Widget',
            'store_id' => $store->id,
            'amount' => 1500,
            'quantity' => 1,
            'section_id' => $theirSection->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('section_id');
});

test('products can be assigned to a section and inherit its warehouse', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);
    $store = ws36Store($owner);

    $inWarehouse = ws36Product($owner, [
        'store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'name' => 'In House',
    ]);
    $storeless = ws36Product($owner, ['store_id' => $store->id, 'name' => 'No Warehouse Yet']);

    $this->withToken(ws36Token($owner))
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}/products", [
            'product_ids' => [$inWarehouse->id, $storeless->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.assigned', 2)
        ->assertJsonPath('data.section.products_count', 2);

    expect($inWarehouse->fresh()->section_id)->toBe($section->id)
        ->and($inWarehouse->fresh()->warehouse_id)->toBe($warehouse->id)
        ->and($storeless->fresh()->section_id)->toBe($section->id)
        ->and($storeless->fresh()->warehouse_id)->toBe($warehouse->id);
});

test('assigning a product held in another warehouse is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $otherWarehouse = ws36Warehouse($owner, ['name' => 'Second Depot']);
    $section = ws36Section($warehouse);
    $store = ws36Store($owner);

    $elsewhere = ws36Product($owner, [
        'store_id' => $store->id, 'warehouse_id' => $otherWarehouse->id, 'name' => 'Elsewhere',
    ]);

    $this->withToken(ws36Token($owner))
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}/products", [
            'product_ids' => [$elsewhere->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_ids');

    expect($elsewhere->fresh()->section_id)->toBeNull()
        ->and($elsewhere->fresh()->warehouse_id)->toBe($otherWarehouse->id);
});

test('assigning another business product is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);
    $theirProduct = ws36Product($otherOwner, ['name' => 'Theirs']);

    $this->withToken(ws36Token($owner))
        ->postJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}/products", [
            'product_ids' => [$theirProduct->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_ids');

    expect($theirProduct->fresh()->section_id)->toBeNull();
});

test('available products lists warehouse candidates and excludes what is already filed', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $otherWarehouse = ws36Warehouse($owner, ['name' => 'Second Depot']);
    $section = ws36Section($warehouse);
    $otherSection = ws36Section($warehouse, ['name' => 'Bay B']);

    ws36Product($owner, ['warehouse_id' => $warehouse->id, 'name' => 'Filed Already', 'section_id' => $section->id]);
    ws36Product($owner, ['warehouse_id' => $warehouse->id, 'name' => 'Free Agent']);
    ws36Product($owner, ['warehouse_id' => $warehouse->id, 'name' => 'Filed Elsewhere', 'section_id' => $otherSection->id]);
    ws36Product($owner, ['warehouse_id' => $otherWarehouse->id, 'name' => 'Other Depot Product']);

    $response = $this->withToken(ws36Token($owner))
        ->getJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}/available-products");

    $response->assertOk()->assertJsonCount(2, 'data.products');

    $names = collect($response->json('data.products'))->pluck('name')->all();

    expect($names)->toContain('Free Agent', 'Filed Elsewhere')
        ->not->toContain('Filed Already', 'Other Depot Product');
});

test('unassigning removes products from the section but leaves them in the warehouse', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws36Warehouse($owner);
    $section = ws36Section($warehouse);

    $product = ws36Product($owner, ['warehouse_id' => $warehouse->id, 'section_id' => $section->id]);

    $this->withToken(ws36Token($owner))
        ->deleteJson("/api/v1/management/warehouses/{$warehouse->warehouse_code}/sections/{$section->section_code}/products", [
            'product_ids' => [$product->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.removed', 1)
        ->assertJsonPath('data.section.products_count', 0);

    expect($product->fresh()->section_id)->toBeNull()
        ->and($product->fresh()->warehouse_id)->toBe($warehouse->id);
});
