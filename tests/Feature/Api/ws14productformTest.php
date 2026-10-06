<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Section;
use App\Models\SizeUnit;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WeightUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function ws14Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws14Store(User $owner, array $attributes = []): Store
{
    static $sequence = 0;

    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.++$sequence,
        'slug' => 'ws14-store-'.$sequence,
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws14Warehouse(User $owner, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Depot '.Str::random(4),
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws14Section(Warehouse $warehouse, array $attributes = []): Section
{
    return Section::create(array_merge([
        'business_id' => $warehouse->business_id,
        'warehouse_id' => $warehouse->id,
        'name' => 'Aisle '.Str::random(4),
    ], $attributes));
}

function ws14Product(int $storeId, int $businessId, array $attributes = []): Product
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

function ws14Currency(): Currency
{
    return Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);
}

test('a product keeps the full selling data the legacy form accepted', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);
    $section = ws14Section($warehouse);
    $category = Category::create(['business_id' => $business->id, 'store_id' => $store->id, 'name' => 'Shoes', 'slug' => 'shoes']);
    $currency = ws14Currency();
    $sizeUnit = SizeUnit::create(['name' => 'US', 'code' => 'us']);
    $weightUnit = WeightUnit::create(['name' => 'Kilogram', 'code' => 'kg']);

    $response = $this->withToken(ws14Token($owner))->postJson('/api/v1/management/products', [
        'name' => 'Leather Boot',
        'store_id' => $store->id,
        'warehouse_id' => $warehouse->id,
        'section_id' => $section->id,
        'category_id' => $category->id,
        'brand' => 'Storify',
        'description' => 'A sturdy boot.',
        'tags' => 'boots,leather',
        'color' => 'Brown',
        'size' => 42,
        'size_unit_id' => $sizeUnit->id,
        'weight' => 1.5,
        'weight_unit_id' => $weightUnit->id,
        'currency_id' => $currency->id,
        'amount' => 25000,
        'cost_price' => 15000,
        'discount_percentage' => 10,
        'is_taxable' => true,
        'bulk_quantity' => 12,
        'bulk_price' => 22000,
        'quantity' => 30,
        'stock_quantity' => 40,
        'featured' => true,
        'cod_available' => true,
        'status' => 'active',
    ]);

    // assertJsonPath is a strict identity check and PHP's JSON encoder writes
    // whole floats without a decimal fraction (42.0 -> 42), which decodes back
    // as int — so whole numbers are asserted as ints.
    $response->assertCreated()
        ->assertJsonPath('data.product.size', 42)
        ->assertJsonPath('data.product.size_unit_id', $sizeUnit->id)
        ->assertJsonPath('data.product.weight', 1.5)
        ->assertJsonPath('data.product.weight_unit_id', $weightUnit->id)
        ->assertJsonPath('data.product.color', 'Brown')
        ->assertJsonPath('data.product.tags', 'boots,leather')
        ->assertJsonPath('data.product.cost_price', 15000)
        ->assertJsonPath('data.product.bulk_quantity', 12)
        ->assertJsonPath('data.product.bulk_price', 22000)
        ->assertJsonPath('data.product.stock_quantity', 40)
        ->assertJsonPath('data.product.sold_quantity', 10)
        ->assertJsonPath('data.product.has_discount', true)
        ->assertJsonPath('data.product.display_amount', 22500)
        ->assertJsonPath('data.product.section_name', $section->name)
        ->assertJsonPath('data.product.warehouse_name', $warehouse->name)
        ->assertJsonPath('data.product.store_name', $store->name)
        ->assertJsonPath('data.product.category_name', 'Shoes');

    $product = Product::where('product_code', $response->json('data.product.product_code'))->firstOrFail();

    // Cost price must reach accounting costing — 15000 naira in kobo.
    expect((int) $product->average_cost_kobo)->toBe(1500000);

    // The legacy save wrote an activity-log row; the new stack had dropped it.
    expect(ActivityLog::where('action', 'business_create_product')->where('user_id', $owner->id)->exists())->toBeTrue();
});

test('the first uploaded image becomes the primary one', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);

    $response = $this->withToken(ws14Token($owner))->post('/api/v1/management/products', [
        'name' => 'Photo Product',
        'store_id' => (string) $store->id,
        'warehouse_id' => (string) $warehouse->id,
        'amount' => '1000',
        'quantity' => '4',
        'images' => [
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('back.jpg'),
        ],
    ]);

    $response->assertCreated();

    // The legacy API stored every upload as non-primary because a null
    // max(position) was cast to 1 before the `position === 0` check.
    expect($response->json('data.product.images.0.is_primary'))->toBeTrue();
    expect($response->json('data.product.images.1.is_primary'))->toBeFalse();

    $product = Product::where('name', 'Photo Product')->firstOrFail();
    expect($product->images()->where('is_primary', true)->count())->toBe(1);
    Storage::disk('public')->assertExists($product->images()->first()->path);
});

test('a product needs a store the caller can reach and a warehouse for physical stock', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $theirs = ws14Store($otherOwner);
    $warehouse = ws14Warehouse($owner);

    $token = ws14Token($owner);

    // No store at all.
    $this->withToken($token)->postJson('/api/v1/management/products', [
        'name' => 'Orphan', 'amount' => 1000, 'quantity' => 1, 'warehouse_id' => $warehouse->id,
    ])->assertStatus(422)->assertJsonValidationErrors('store_id');

    // Another business's store.
    $this->withToken($token)->postJson('/api/v1/management/products', [
        'name' => 'Stolen', 'store_id' => $theirs->id, 'amount' => 1000, 'quantity' => 1, 'warehouse_id' => $warehouse->id,
    ])->assertStatus(422)->assertJsonValidationErrors('store_id');

    // Physical product without a warehouse.
    $this->withToken($token)->postJson('/api/v1/management/products', [
        'name' => 'Homeless', 'store_id' => $store->id, 'amount' => 1000, 'quantity' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

    // Another business's warehouse.
    $theirWarehouse = ws14Warehouse($otherOwner);
    $this->withToken($token)->postJson('/api/v1/management/products', [
        'name' => 'Trespasser', 'store_id' => $store->id, 'amount' => 1000, 'quantity' => 1,
        'warehouse_id' => $theirWarehouse->id,
    ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
});

test('a variant product must actually carry variants with positive quantity and price', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);

    $token = ws14Token($owner);
    $base = [
        'name' => 'Varied', 'store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'has_variants' => true,
    ];

    // Flagged as variant-based with no rows.
    $this->withToken($token)->postJson('/api/v1/management/products', $base)
        ->assertStatus(422)->assertJsonValidationErrors('variants');

    // Legacy required quantity > 0 per variant; the new API allowed zero.
    $this->withToken($token)->postJson('/api/v1/management/products', [...$base, 'variants' => [
        ['quantity' => 0, 'amount' => 500],
    ]])->assertStatus(422)->assertJsonValidationErrors('variants.0.quantity');
});

test('variants round-trip every legacy field', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);
    $currency = ws14Currency();
    $weightUnit = WeightUnit::create(['name' => 'Gram', 'code' => 'g']);

    $response = $this->withToken(ws14Token($owner))->postJson('/api/v1/management/products', [
        'name' => 'T-Shirt',
        'store_id' => $store->id,
        'warehouse_id' => $warehouse->id,
        'has_variants' => true,
        'variants' => [
            [
                'sku' => 'TS-RED-M',
                'size' => 32,
                'weight' => 250,
                'weight_unit_id' => $weightUnit->id,
                'color' => 'Red',
                'quantity' => 8,
                'amount' => 4500,
                'currency_id' => $currency->id,
                'status' => 'active',
                'featured' => true,
            ],
            [
                'sku' => 'TS-BLUE-L',
                'color' => 'Blue',
                'quantity' => 3,
                'amount' => 4700,
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.product.variants.0.sku', 'TS-RED-M')
        ->assertJsonPath('data.product.variants.0.size', 32)
        ->assertJsonPath('data.product.variants.0.weight', 250)
        ->assertJsonPath('data.product.variants.0.weight_unit_id', $weightUnit->id)
        ->assertJsonPath('data.product.variants.0.currency_id', $currency->id)
        ->assertJsonPath('data.product.variants.0.featured', true)
        ->assertJsonPath('data.product.variants.0.quantity', 8);

    expect($response->json('data.product.price_range'))->toBe(['min' => 4500, 'max' => 4700]);
});

test('a digital product needs no warehouse and cannot offer cash on delivery', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);

    $response = $this->withToken(ws14Token($owner))->postJson('/api/v1/management/products', [
        'name' => 'E-book',
        'store_id' => $store->id,
        'amount' => 2000,
        'is_digital' => true,
        'cod_available' => true,
        'download_limit' => 3,
        'download_expiry_days' => 14,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.product.is_digital', true)
        ->assertJsonPath('data.product.cod_available', false)
        ->assertJsonPath('data.product.warehouse_id', null)
        ->assertJsonPath('data.product.download_limit', 3);
});

test('updating variants keeps existing ids, adds new rows and removes the missing ones', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);

    $product = ws14Product($store->id, $business->id, [
        'warehouse_id' => $warehouse->id,
        'has_variants' => true,
        'amount' => null,
        'quantity' => 0,
    ]);

    $keep = ProductVariant::create(['product_id' => $product->id, 'sku' => 'KEEP', 'quantity' => 2, 'amount' => 1000]);
    $drop = ProductVariant::create(['product_id' => $product->id, 'sku' => 'DROP', 'quantity' => 2, 'amount' => 1000]);

    $response = $this->withToken(ws14Token($owner))->putJson('/api/v1/management/products/'.$product->product_code, [
        'has_variants' => true,
        'variants' => [
            ['id' => $keep->id, 'sku' => 'KEEP', 'quantity' => 9, 'amount' => 1200, 'status' => 'active'],
            ['sku' => 'NEW', 'quantity' => 4, 'amount' => 1500],
        ],
    ]);

    $response->assertOk();

    // The old API deleted and re-created every variant, churning ids.
    expect(ProductVariant::whereKey($keep->id)->value('quantity'))->toBe(9);
    expect(ProductVariant::whereKey($drop->id)->exists())->toBeFalse();
    expect(ProductVariant::where('product_id', $product->id)->where('sku', 'NEW')->exists())->toBeTrue();
    expect(ProductVariant::where('product_id', $product->id)->count())->toBe(2);
});

test('turning variants off deletes the variant rows', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);

    $product = ws14Product($store->id, $business->id, [
        'warehouse_id' => $warehouse->id,
        'has_variants' => true,
        'amount' => null,
        'quantity' => 0,
    ]);

    ProductVariant::create(['product_id' => $product->id, 'quantity' => 1, 'amount' => 1000]);

    $this->withToken(ws14Token($owner))->putJson('/api/v1/management/products/'.$product->product_code, [
        'has_variants' => false,
        'amount' => 1000,
        'quantity' => 5,
    ])->assertOk()->assertJsonPath('data.product.has_variants', false);

    // The old API left orphaned rows behind when the flag was switched off.
    expect(ProductVariant::where('product_id', $product->id)->count())->toBe(0);
});

test('a partial update still works and cost price resyncs', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);

    $product = ws14Product($store->id, $business->id, ['warehouse_id' => $warehouse->id]);

    $this->withToken(ws14Token($owner))->putJson('/api/v1/management/products/'.$product->product_code, [
        'name' => 'Renamed Widget',
        'amount' => 3000,
    ])->assertOk()->assertJsonPath('data.product.name', 'Renamed Widget');

    $this->withToken(ws14Token($owner))->putJson('/api/v1/management/products/'.$product->product_code, [
        'cost_price' => 900,
    ])->assertOk();

    expect((int) $product->fresh()->average_cost_kobo)->toBe(90000);
});

test('a product cannot be moved into another business store', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $theirs = ws14Store($otherOwner);
    $warehouse = ws14Warehouse($owner);

    $product = ws14Product($store->id, $business->id, ['warehouse_id' => $warehouse->id]);

    // The update path used to accept any store id (verify pass #3).
    $this->withToken(ws14Token($owner))->putJson('/api/v1/management/products/'.$product->product_code, [
        'store_id' => $theirs->id,
    ])->assertStatus(422)->assertJsonValidationErrors('store_id');

    expect($product->fresh()->store_id)->toBe($store->id);
});

test('another business product is not reachable, writable or deletable', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $theirStore = ws14Store($otherOwner);
    $theirProduct = ws14Product($theirStore->id, $otherBusiness->id);

    $token = ws14Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/products/'.$theirProduct->product_code)->assertStatus(403);
    $this->withToken($token)->putJson('/api/v1/management/products/'.$theirProduct->product_code, ['name' => 'Mine now'])
        ->assertStatus(403);
    $this->withToken($token)->deleteJson('/api/v1/management/products/'.$theirProduct->product_code)->assertStatus(403);
    $this->withToken($token)->putJson('/api/v1/management/products/'.$theirProduct->product_code.'/status', ['status' => 'inactive'])
        ->assertStatus(403);

    expect($theirProduct->fresh()->name)->not->toBe('Mine now');
});

test('images can be re-primaried and deleted, and deleting the primary promotes the next', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);
    $product = ws14Product($store->id, $business->id, ['warehouse_id' => $warehouse->id]);

    $first = ProductImage::create(['product_id' => $product->id, 'path' => 'products/images/a.jpg', 'is_primary' => true, 'position' => 0]);
    $second = ProductImage::create(['product_id' => $product->id, 'path' => 'products/images/b.jpg', 'is_primary' => false, 'position' => 1]);
    Storage::disk('public')->put($first->path, 'a');
    Storage::disk('public')->put($second->path, 'b');

    $token = ws14Token($owner);

    $this->withToken($token)->putJson("/api/v1/management/products/{$product->product_code}/images/{$second->id}/primary")
        ->assertOk();

    expect((bool) $second->fresh()->is_primary)->toBeTrue();
    expect((bool) $first->fresh()->is_primary)->toBeFalse();

    $this->withToken($token)->deleteJson("/api/v1/management/products/{$product->product_code}/images/{$second->id}")
        ->assertOk();

    expect(ProductImage::whereKey($second->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($second->path);
    // The next image is promoted so the product keeps a thumbnail.
    expect((bool) $first->fresh()->is_primary)->toBeTrue();
});

test('an image from another product or another business cannot be touched', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);
    $product = ws14Product($store->id, $business->id, ['warehouse_id' => $warehouse->id]);

    $otherProduct = ws14Product($store->id, $business->id, ['name' => 'Other', 'warehouse_id' => $warehouse->id]);
    $orphan = ProductImage::create(['product_id' => $otherProduct->id, 'path' => 'products/images/c.jpg', 'is_primary' => false, 'position' => 0]);

    $theirStore = ws14Store($otherOwner);
    $theirProduct = ws14Product($theirStore->id, $otherBusiness->id);
    $theirImage = ProductImage::create(['product_id' => $theirProduct->id, 'path' => 'products/images/d.jpg', 'is_primary' => true, 'position' => 0]);

    $token = ws14Token($owner);

    // Right product, wrong image id.
    $this->withToken($token)->putJson("/api/v1/management/products/{$product->product_code}/images/{$orphan->id}/primary")
        ->assertStatus(404);

    // Someone else's product entirely.
    $this->withToken($token)->deleteJson("/api/v1/management/products/{$theirProduct->product_code}/images/{$theirImage->id}")
        ->assertStatus(403);
});

test('the detail payload carries names, stock math, variant fields and file state', function () {
    Storage::fake('local');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);
    $section = ws14Section($warehouse);
    $category = Category::create(['business_id' => $business->id, 'store_id' => $store->id, 'name' => 'Gear', 'slug' => 'gear']);
    $currency = ws14Currency();

    $product = ws14Product($store->id, $business->id, [
        'warehouse_id' => $warehouse->id,
        'section_id' => $section->id,
        'category_id' => $category->id,
        'currency_id' => $currency->id,
        'stock_quantity' => 40,
        'quantity' => 25,
        'cod_available' => true,
        'cost_price' => 500,
    ]);

    ProductVariant::create([
        'product_id' => $product->id, 'sku' => 'SKU-1', 'quantity' => 2, 'amount' => 700,
        'currency_id' => $currency->id, 'status' => 'active', 'featured' => true, 'color' => 'Green',
    ]);

    ProductFile::create([
        'product_id' => $product->id, 'business_id' => $business->id, 'disk' => 'local',
        'path' => 'products/downloads/missing.pdf', 'original_name' => 'missing.pdf',
        'mime_type' => 'application/pdf', 'size' => 1024, 'position' => 0,
    ]);

    $response = $this->withToken(ws14Token($owner))->getJson('/api/v1/management/products/'.$product->product_code);

    $response->assertOk()
        ->assertJsonPath('data.product.store.name', $store->name)
        ->assertJsonPath('data.product.warehouse.name', $warehouse->name)
        ->assertJsonPath('data.product.section.name', $section->name)
        ->assertJsonPath('data.product.category.name', 'Gear')
        ->assertJsonPath('data.product.currency.code', 'NGN')
        ->assertJsonPath('data.product.sold_quantity', 15)
        ->assertJsonPath('data.product.stock_percentage', 63)
        ->assertJsonPath('data.product.stock_level', 'good')
        ->assertJsonPath('data.product.cod_available', true)
        ->assertJsonPath('data.product.has_variants', false)
        ->assertJsonPath('data.product.variants.0.currency_id', $currency->id)
        ->assertJsonPath('data.product.variants.0.featured', true)
        ->assertJsonPath('data.product.variants.0.color', 'Green')
        // The audit's digital-file gap: stored vs missing-on-disk state.
        ->assertJsonPath('data.product.files.0.exists_on_disk', false)
        ->assertJsonPath('data.product.files.0.formatted_size', '1 KB');
});

test('the list includes warehouse-only products and hides other businesses', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);

    ws14Product($store->id, $business->id, ['name' => 'Stored']);
    ws14Product($store->id, $business->id, ['name' => 'Digital-ish', 'is_digital' => true]);
    // Legacy store-less rows: the migration blocker this workstream resolves.
    Product::create([
        'store_id' => null, 'warehouse_id' => $warehouse->id, 'business_id' => $business->id,
        'name' => 'Warehouse Only', 'amount' => 1000, 'quantity' => 3, 'status' => 'active',
    ]);

    $theirStore = ws14Store($otherOwner);
    ws14Product($theirStore->id, $otherBusiness->id, ['name' => 'Theirs']);

    $response = $this->withToken(ws14Token($owner))->getJson('/api/v1/management/products');

    $response->assertOk();

    $names = array_column($response->json('data'), 'name');
    sort($names);

    expect($names)->toBe(['Digital-ish', 'Stored', 'Warehouse Only']);
});

test('a warehouse-only product stays editable instead of 403ing', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $warehouse = ws14Warehouse($owner);

    $product = Product::create([
        'store_id' => null, 'warehouse_id' => $warehouse->id, 'business_id' => $business->id,
        'name' => 'Warehouse Only', 'amount' => 1000, 'quantity' => 3, 'status' => 'active',
    ]);

    $token = ws14Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/products/'.$product->product_code)->assertOk();

    $this->withToken($token)->putJson('/api/v1/management/products/'.$product->product_code, [
        'name' => 'Warehouse Only Renamed',
    ])->assertOk()->assertJsonPath('data.product.name', 'Warehouse Only Renamed');
});

test('the form options endpoint serves the pickers the create/edit screens need', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws14Store($owner);
    $warehouse = ws14Warehouse($owner);
    $section = ws14Section($warehouse);
    Category::create(['business_id' => $owner->business_id, 'store_id' => $store->id, 'name' => 'Shoes', 'slug' => 'shoes']);
    ws14Currency();
    SizeUnit::create(['name' => 'US', 'code' => 'us']);
    WeightUnit::create(['name' => 'Kilogram', 'code' => 'kg']);

    $response = $this->withToken(ws14Token($owner))->getJson('/api/v1/management/products/form/options');

    $response->assertOk()
        ->assertJsonPath('data.stores.0.name', $store->name)
        ->assertJsonPath('data.warehouses.0.name', $warehouse->name)
        ->assertJsonPath('data.warehouses.0.sections.0.name', $section->name)
        ->assertJsonPath('data.categories.0.name', 'Shoes')
        ->assertJsonPath('data.currencies.0.code', 'NGN')
        ->assertJsonPath('data.size_units.0.code', 'us')
        ->assertJsonPath('data.weight_units.0.code', 'kg')
        ->assertJsonPath('data.low_stock_threshold', 10)
        ->assertJsonStructure(['data' => ['defaults' => ['download_limit', 'download_expiry_days']]]);
});
