<?php

use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Section;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Money note: the amounts this test reads are whole naira values, and PHP's
 * `json_encode` drops the zero fraction (`json_encode(2500.0) === "2500"`), so
 * the wire carries JSON integers. `assertJsonPath` / `toBe` compare with
 * `assertSame`, so a `2500.0` expectation can never match the decoded `2500` —
 * assert the integers the API actually emits (the same convention as
 * StorefrontApiTest and the ws21 invoice tests).
 */

function ws25Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws25Store(User $owner, array $attributes = []): Store
{
    static $sequence = 0;

    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.++$sequence,
        'slug' => 'ws25-store-'.$sequence,
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws25Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Depot '.Str::random(4),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws25Section(Warehouse $warehouse, array $attributes = []): Section
{
    return Section::create(array_merge([
        'business_id' => $warehouse->business_id,
        'warehouse_id' => $warehouse->id,
        'name' => 'Aisle '.Str::random(4),
    ], $attributes));
}

function ws25Product(int $storeId, int $businessId, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $storeId,
        'business_id' => $businessId,
        'name' => 'Widget',
        'amount' => 2500,
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));
}

function ws25Currency(): Currency
{
    return Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);
}

test('the list carries the columns, price range and stock display the table renders', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);
    $warehouse = ws25Warehouse($owner);
    $section = ws25Section($warehouse);
    // categories.slug is NOT NULL with no model default, so the fixture sets it.
    $category = Category::create(['business_id' => $business->id, 'store_id' => $store->id, 'name' => 'Boots', 'slug' => 'boots']);
    $currency = ws25Currency();

    $product = ws25Product($store->id, $business->id, [
        'name' => 'Trail Boot',
        'warehouse_id' => $warehouse->id,
        'section_id' => $section->id,
        'category_id' => $category->id,
        'currency_id' => $currency->id,
        'amount' => 2500,
        'discount_percentage' => 20,
        'stock_quantity' => 40,
        'quantity' => 25,
        'has_variants' => true,
    ]);

    ProductVariant::create(['product_id' => $product->id, 'sku' => 'A', 'quantity' => 2, 'amount' => 5000, 'status' => 'active']);
    ProductVariant::create(['product_id' => $product->id, 'sku' => 'B', 'quantity' => 0, 'amount' => 3000, 'status' => 'active']);

    $response = $this->withToken(ws25Token($owner))->getJson('/api/v1/management/products');

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Trail Boot')
        ->assertJsonPath('data.0.store_name', $store->name)
        ->assertJsonPath('data.0.warehouse_name', $warehouse->name)
        ->assertJsonPath('data.0.section_name', $section->name)
        ->assertJsonPath('data.0.category_name', 'Boots')
        ->assertJsonPath('data.0.currency_code', 'NGN')
        ->assertJsonPath('data.0.currency_symbol', '₦')
        ->assertJsonPath('data.0.has_variants', true)
        ->assertJsonPath('data.0.variant_count', 2)
        ->assertJsonPath('data.0.variant_stock', 2)
        // Variant span, original and discounted (20% off 3000–5000).
        ->assertJsonPath('data.0.price_range.min', 3000)
        ->assertJsonPath('data.0.price_range.max', 5000)
        ->assertJsonPath('data.0.display_price_range.min', 2400)
        ->assertJsonPath('data.0.display_price_range.max', 4000)
        // Stock math: 25 of 40 left, sold 15, 63%.
        ->assertJsonPath('data.0.sold_quantity', 15)
        ->assertJsonPath('data.0.stock_percentage', 63)
        ->assertJsonPath('data.0.stock_level', 'good')
        // 2 units on the variant rows is below the legacy amber threshold.
        ->assertJsonPath('data.0.low_stock', true)
        ->assertJsonPath('meta.low_stock_threshold', 10);
});

test('a discounted single-sku product reports the strike-through price', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);

    ws25Product($store->id, $business->id, ['amount' => 2000, 'discount_percentage' => 25]);

    $row = $this->withToken(ws25Token($owner))->getJson('/api/v1/management/products')->json('data.0');

    expect($row['has_discount'])->toBeTrue()
        ->and($row['amount'])->toBe(2000)
        ->and($row['discount_amount'])->toBe(500)
        ->and($row['display_amount'])->toBe(1500)
        ->and($row['price_range'])->toBeNull();
});

test('the list filters by created range, section, variants and low stock', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);
    $warehouse = ws25Warehouse($owner);
    $section = ws25Section($warehouse);

    $this->travelTo(Carbon::parse('2026-01-05 10:00:00'));
    $january = ws25Product($store->id, $business->id, [
        'name' => 'January Low',
        'warehouse_id' => $warehouse->id,
        'section_id' => $section->id,
        'quantity' => 3,
    ]);

    $this->travelTo(Carbon::parse('2026-02-05 10:00:00'));
    $february = ws25Product($store->id, $business->id, ['name' => 'February Full', 'quantity' => 90]);
    $variantProduct = ws25Product($store->id, $business->id, [
        'name' => 'Variant Product',
        'quantity' => 80,
        'has_variants' => true,
    ]);
    ProductVariant::create(['product_id' => $variantProduct->id, 'quantity' => 2, 'amount' => 900, 'status' => 'active']);

    $this->travelBack();

    $token = ws25Token($owner);

    $names = fn ($query) => array_column(
        $this->withToken($token)->getJson('/api/v1/management/products?'.$query)->json('data'),
        'name',
    );

    expect($names('from=2026-01-01&to=2026-01-31'))->toBe(['January Low'])
        ->and($names('from=2026-02-01'))->toEqualCanonicalizing(['February Full', 'Variant Product'])
        ->and($names('section_id='.$section->id))->toBe(['January Low'])
        ->and($names('has_variants=1'))->toBe(['Variant Product'])
        ->and($names('low_stock=1'))->toEqualCanonicalizing(['January Low', 'Variant Product'])
        ->and($names('q=February'))->toBe(['February Full']);

    // The filter and the per-row flag must agree: every returned row is low.
    $rows = $this->withToken($token)->getJson('/api/v1/management/products?low_stock=1')->json('data');
    expect(collect($rows)->every(fn ($row) => $row['low_stock'] === true))->toBeTrue();
});

test('the list honours the page size and refuses an impossible one', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);
    ws25Product($store->id, $business->id);

    // 10/20/50/100 are the sizes the screen offers (legacy's whitelist).
    foreach ([10, 20, 50, 100] as $size) {
        $this->withToken(ws25Token($owner))
            ->getJson('/api/v1/management/products?per_page='.$size)
            ->assertOk()
            ->assertJsonPath('meta.per_page', $size);
    }

    $this->withToken(ws25Token($owner))
        ->getJson('/api/v1/management/products?per_page=500')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('the list still shows warehouse-only products and hides other businesses', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws25Store($owner);
    $warehouse = ws25Warehouse($owner);

    ws25Product($store->id, $business->id, ['name' => 'Stored']);
    Product::create([
        'store_id' => null, 'warehouse_id' => $warehouse->id, 'business_id' => $business->id,
        'name' => 'Warehouse Only', 'amount' => 1000, 'quantity' => 3, 'status' => 'active',
    ]);
    ws25Product(ws25Store($otherOwner)->id, $otherBusiness->id, ['name' => 'Theirs']);

    $response = $this->withToken(ws25Token($owner))->getJson('/api/v1/management/products');

    $response->assertOk();

    $names = array_column($response->json('data'), 'name');
    sort($names);

    expect($names)->toBe(['Stored', 'Warehouse Only']);
});

test('bulk edit applies only the fields that were filled in, per row', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);

    $first = ws25Product($store->id, $business->id, ['name' => 'First', 'amount' => 1000, 'quantity' => 4]);
    $second = ws25Product($store->id, $business->id, ['name' => 'Second', 'amount' => 2000, 'quantity' => 6]);
    $theirs = ws25Product(ws25Store($otherOwner)->id, $otherBusiness->id, ['amount' => 9999]);

    $response = $this->withToken(ws25Token($owner))->postJson('/api/v1/management/products/bulk-update', [
        'products' => [
            ['id' => $first->id, 'amount' => 1500, 'quantity' => 5],
            ['id' => $second->id, 'status' => 'inactive'],
            ['id' => $theirs->id, 'amount' => 1],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.updated', 2)
        ->assertJsonPath('data.skipped_ids', [$theirs->id])
        ->assertJsonPath('message', '2 product(s) updated successfully.');

    expect((float) $first->fresh()->amount)->toBe(1500.0)
        ->and((int) $first->fresh()->quantity)->toBe(5)
        ->and($second->fresh()->status)->toBe('inactive')
        // Untouched fields keep their value: the modal only submits what the
        // user edited (legacy's blank input meant "no change").
        ->and((float) $second->fresh()->amount)->toBe(2000.0)
        ->and((float) $theirs->fresh()->amount)->toBe(9999.0);
});

test('bulk edit reports a row the model refuses instead of failing the batch', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);

    $refused = ws25Product($store->id, $business->id, ['name' => 'Refused', 'quantity' => 4]);
    $accepted = ws25Product($store->id, $business->id, ['name' => 'Accepted', 'quantity' => 4]);

    $response = $this->withToken(ws25Token($owner))->postJson('/api/v1/management/products/bulk-update', [
        'products' => [
            // A store product may not hold zero stock (the Product model
            // refuses it); that row must not sink the whole batch.
            ['id' => $refused->id, 'quantity' => 0],
            ['id' => $accepted->id, 'quantity' => 12],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.rejected.0.id', $refused->id);

    expect((int) $refused->fresh()->quantity)->toBe(4)
        ->and((int) $accepted->fresh()->quantity)->toBe(12);
});

test('bulk status activates and deactivates a scoped selection', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);

    $active = ws25Product($store->id, $business->id, ['name' => 'Active one', 'status' => 'active']);
    $inactive = ws25Product($store->id, $business->id, ['name' => 'Inactive one', 'status' => 'inactive']);
    $theirs = ws25Product(ws25Store($otherOwner)->id, $otherBusiness->id, ['status' => 'active']);

    $token = ws25Token($owner);
    $ids = [$active->id, $inactive->id, $theirs->id];

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-status', [
        'product_ids' => $ids,
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertJsonPath('data.updated', 2)
        ->assertJsonPath('data.skipped_ids', [$theirs->id])
        ->assertJsonPath('message', '2 product(s) deactivated.');

    expect($active->fresh()->status)->toBe('inactive')
        ->and($inactive->fresh()->status)->toBe('inactive')
        ->and($theirs->fresh()->status)->toBe('active');

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-status', [
        'product_ids' => [$active->id],
        'status' => 'active',
    ])
        ->assertOk()
        ->assertJsonPath('message', '1 product(s) activated.');

    expect($active->fresh()->status)->toBe('active');
});

test('bulk delete removes the rows with their images and digital files', function () {
    Storage::fake('public');
    Storage::fake('local');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);

    $product = ws25Product($store->id, $business->id, ['name' => 'Doomed']);
    $image = ProductImage::create([
        'product_id' => $product->id, 'business_id' => $business->id,
        'path' => 'products/images/doomed.jpg', 'is_primary' => true, 'position' => 0,
    ]);
    $file = ProductFile::create([
        'product_id' => $product->id, 'business_id' => $business->id, 'disk' => 'local',
        'path' => 'products/downloads/doomed.pdf', 'original_name' => 'doomed.pdf',
        'mime_type' => 'application/pdf', 'size' => 512, 'position' => 0,
    ]);
    Storage::disk('public')->put($image->path, 'image');
    Storage::disk('local')->put($file->path, 'file');

    $theirProduct = ws25Product(ws25Store($otherOwner)->id, $otherBusiness->id, ['name' => 'Safe']);
    $theirImage = ProductImage::create([
        'product_id' => $theirProduct->id, 'path' => 'products/images/safe.jpg', 'is_primary' => true, 'position' => 0,
    ]);

    $response = $this->withToken(ws25Token($owner))->postJson('/api/v1/management/products/bulk-delete', [
        'product_ids' => [$product->id, $theirProduct->id],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.deleted', 1)
        ->assertJsonPath('data.deleted_ids', [$product->id])
        ->assertJsonPath('data.skipped_ids', [$theirProduct->id])
        ->assertJsonPath('message', '1 product(s) deleted.');

    expect(Product::find($product->id))->toBeNull()
        ->and(ProductImage::find($image->id))->toBeNull()
        ->and(ProductFile::find($file->id))->toBeNull()
        ->and(Product::find($theirProduct->id))->not->toBeNull()
        ->and(ProductImage::find($theirImage->id))->not->toBeNull();

    Storage::disk('public')->assertMissing($image->path);
    Storage::disk('local')->assertMissing($file->path);
});

test('bulk payloads are validated before anything is touched', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);
    $product = ws25Product($store->id, $business->id, ['name' => 'Untouched']);

    $token = ws25Token($owner);

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-update', ['products' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('products');

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-update', [
        'products' => [['id' => $product->id, 'status' => 'archived']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('products.0.status');

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-update', [
        'products' => [['id' => $product->id, 'amount' => -5]],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('products.0.amount');

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-status', ['product_ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product_ids', 'status']);

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-status', [
        'product_ids' => [$product->id], 'status' => 'archived',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-delete', ['product_ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_ids');

    // A backwards date range would silently return nothing, so it is refused.
    $this->withToken($token)->getJson('/api/v1/management/products?from=2026-02-01&to=2026-01-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    expect($product->fresh()->name)->toBe('Untouched');
});

test('the bulk routes need their permission and a management token', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws25Store($owner);
    $product = ws25Product($store->id, $business->id);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    // Managing Director sees the catalogue but cannot change it.
    $staff->assignRole('Managing Director');

    $token = ws25Token($staff);

    $this->withToken($token)->getJson('/api/v1/management/products')->assertOk();

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-update', [
        'products' => [['id' => $product->id, 'amount' => 1]],
    ])->assertStatus(403);

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-status', [
        'product_ids' => [$product->id], 'status' => 'inactive',
    ])->assertStatus(403);

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-delete', [
        'product_ids' => [$product->id],
    ])->assertStatus(403);

    expect((float) $product->fresh()->amount)->toBe(2500.0)
        ->and($product->fresh()->status)->toBe('active');

    // Two things keep this request authenticated if neither is cleared: the
    // test client's default headers, and the Sanctum guard, which caches the
    // user it resolved on the previous request for the life of the test. Left
    // alone, the "no token" request arrives as the staff member and 403s from
    // the permission middleware instead of 401ing from the auth middleware.
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/management/products/bulk-delete', ['product_ids' => [$product->id]])
        ->assertStatus(401);
});

test('a restricted staff member only reaches the products of assigned stores', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $assigned = ws25Store($owner, ['name' => 'Assigned']);
    $unassigned = ws25Store($owner, ['name' => 'Unassigned']);

    $mine = ws25Product($assigned->id, $business->id, ['name' => 'Reachable']);
    $hidden = ws25Product($unassigned->id, $business->id, ['name' => 'Not reachable']);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Manager');
    $staff->assignedStores()->attach($assigned->id);

    $token = ws25Token($staff);

    expect(array_column($this->withToken($token)->getJson('/api/v1/management/products')->json('data'), 'name'))
        ->toBe(['Reachable']);

    $this->withToken($token)->postJson('/api/v1/management/products/bulk-status', [
        'product_ids' => [$mine->id, $hidden->id],
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.skipped_ids', [$hidden->id]);

    expect($hidden->fresh()->status)->toBe('active');
});
