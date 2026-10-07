<?php

use App\Models\Product;
use App\Models\Section;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Management\Warehouse\DefaultWarehouseResolver;
use Illuminate\Support\Str;

/**
 * The fallback warehouse: created on demand the first time a physical product
 * is saved without one, reused after that, and never in the way of a choice
 * the user actually made.
 *
 * The wholesale behaviour is covered by ws14productformTest and
 * ManagementWarehouseApiTest; this file is about the lifecycle — reuse,
 * rename, delete, and the cases where the resolver should decline.
 */
function dwToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function dwStore(User $owner): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.Str::random(4),
        'slug' => 'dw-store-'.Str::random(8),
        'status' => Store::STATUS_ACTIVE,
    ]);
}

/** The payload a warehouse-less physical product is always created with. */
function dwPayload(Store $store, string $name = 'Widget'): array
{
    return ['name' => $name, 'store_id' => $store->id, 'amount' => 1000, 'quantity' => 1];
}

test('a second warehouse-less product reuses the fallback instead of creating another', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = dwStore($owner);

    $first = $this->withToken(dwToken($owner))
        ->postJson('/api/v1/management/products', dwPayload($store, 'First'))
        ->assertCreated();

    $second = $this->withToken(dwToken($owner))
        ->postJson('/api/v1/management/products', dwPayload($store, 'Second'))
        ->assertCreated();

    $fallback = Warehouse::where('business_id', $business->id)->sole();

    expect(Warehouse::where('business_id', $business->id)->count())->toBe(1)
        ->and(Section::where('warehouse_id', $fallback->id)->count())->toBe(1);

    $first->assertJsonPath('data.product.warehouse_id', $fallback->id);
    $second->assertJsonPath('data.product.warehouse_id', $fallback->id);
});

test('choosing a warehouse never creates a fallback', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = dwStore($owner);

    $chosen = Warehouse::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Depot',
        'status' => Warehouse::STATUS_ACTIVE,
    ]);

    $this->withToken(dwToken($owner))
        ->postJson('/api/v1/management/products', [...dwPayload($store), 'warehouse_id' => $chosen->id])
        ->assertCreated()
        ->assertJsonPath('data.product.warehouse_id', $chosen->id);

    expect(Warehouse::where('business_id', $business->id)->where('is_default', true)->count())->toBe(0);
});

test('renaming the fallback does not orphan it', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = dwStore($owner);
    $token = dwToken($owner);

    $this->withToken($token)->postJson('/api/v1/management/products', dwPayload($store, 'First'))->assertCreated();

    $fallback = Warehouse::where('business_id', $business->id)->sole();

    // Renaming it is explicitly allowed, so the finder must not be matching on
    // the name it was born with.
    $this->withToken($token)
        ->putJson('/api/v1/management/warehouses/'.$fallback->warehouse_code, ['name' => 'Renamed Depot'])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/management/products', dwPayload($store, 'Second'))
        ->assertCreated()
        ->assertJsonPath('data.product.warehouse_id', $fallback->id);

    expect(Warehouse::where('business_id', $business->id)->count())->toBe(1);
});

test('deleting the fallback retires it and the next product gets a fresh one', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = dwStore($owner);
    $token = dwToken($owner);

    $this->withToken($token)->postJson('/api/v1/management/products', dwPayload($store, 'First'))->assertCreated();

    $original = Warehouse::where('business_id', $business->id)->sole();

    $this->withToken($token)
        ->deleteJson('/api/v1/management/warehouses/'.$original->warehouse_code)
        ->assertOk();

    // Cleared along with the status. Left holding `1`, it would go on blocking
    // a replacement for this business forever.
    expect($original->fresh()->is_default)->toBeNull();

    $this->withToken($token)
        ->postJson('/api/v1/management/products', dwPayload($store, 'Second'))
        ->assertCreated();

    $replacement = Warehouse::where('business_id', $business->id)
        ->where('is_default', true)
        ->sole();

    expect($replacement->id)->not->toBe($original->id)
        ->and($replacement->name)->toBe($business->name.' warehouse');
});

test('a business with no name still gets a usable fallback', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => '']);
    $store = dwStore($owner);

    $this->withToken(dwToken($owner))
        ->postJson('/api/v1/management/products', dwPayload($store))
        ->assertCreated();

    $fallback = Warehouse::where('business_id', $business->id)->sole();

    expect($fallback->name)->toBe('Main warehouse');
});

test('clearing the warehouse on an existing physical product re-files it', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = dwStore($owner);

    // A product that predates the fallback, or one whose warehouse was since
    // removed — the update path has to leave it reachable, not refuse it.
    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Legacy',
        'amount' => 2500,
        'quantity' => 4,
        'status' => 'active',
        'warehouse_id' => null,
    ]);

    $this->withToken(dwToken($owner))
        ->putJson('/api/v1/management/products/'.$product->product_code, ['warehouse_id' => ''])
        ->assertOk();

    $fallback = Warehouse::where('business_id', $business->id)->where('is_default', true)->sole();

    expect($product->fresh()->warehouse_id)->toBe($fallback->id);
});

test('the resolver declines rather than inventing a warehouse nobody can reach', function () {
    $detached = User::factory()->create([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'business_id' => null,
    ]);

    expect(app(DefaultWarehouseResolver::class)->resolve($detached))->toBeNull()
        ->and(Warehouse::count())->toBe(0);
});
