<?php

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WS-15 — product & category catalogue (admin console).
 *
 * Covers the platform-wide list with the legacy filter set (status, q over
 * name/code/store/category, created range, the URL-only store scope that used
 * to fail open), the store-scoped variants, create with generated code +
 * initial stock ledger + audit row, the variant-aware validation, images with
 * the primary picker, update with variant synchronisation (update in place /
 * create / delete-on-removal), image deletion and re-primary, bulk-price
 * clearing, activate/deactivate, delete with file cleanup, the category
 * directory (slug rules, count-aware delete), the form options, and the
 * platform-admin / audience boundaries.
 */
function ad15Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad15SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad15AdminWithRole(string $roleName): User
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

function ad15Store(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store '.Str::upper(Str::random(5)),
        'slug' => 'ad15-'.Str::lower(Str::random(10)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad15Category(Store $store, array $attributes = []): Category
{
    return Category::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Category '.Str::upper(Str::random(4)),
        'slug' => 'ad15-cat-'.Str::lower(Str::random(8)),
        'status' => 'active',
    ], $attributes));
}

function ad15Product(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Product '.Str::upper(Str::random(5)),
        'quantity' => 5,
        'amount' => 1000,
        'status' => 'active',
    ], $attributes));
}

function ad15Currency(): Currency
{
    return Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);
}

test('the platform list returns every store product with the legacy columns', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    [$otherOwner, $otherBusiness] = createBusinessOwner();

    $currency = ad15Currency();

    $store = ad15Store($owner, $business, ['name' => 'Ikeja Store']);
    $otherStore = ad15Store($otherOwner, $otherBusiness, ['name' => 'Yaba Store']);

    $category = ad15Category($store, ['name' => 'Beverages']);

    ad15Product($store, [
        'name' => 'Bottled Water',
        'category_id' => $category->id,
        'currency_id' => $currency->id,
        'amount' => 1000,
        'discount_percentage' => 10,
        'featured' => true,
    ]);

    ad15Product($otherStore, ['name' => 'Foreign Item']);

    $response = $this->withToken(ad15Token($admin))->getJson('/api/v1/admin/products')->assertOk();

    expect($response->json('meta.total'))->toBe(2);

    $water = collect($response->json('data'))->firstWhere('name', 'Bottled Water');

    expect($water)->not->toBeNull()
        ->and($water['product_code'])->toStartWith('prd_')
        ->and($water['store'])->toBe('Ikeja Store')
        ->and($water['store_public_id'])->toBe($store->store_id)
        ->and($water['category'])->toBe('Beverages')
        ->and($water['featured'])->toBeTrue()
        ->and($water['display_price'])->toBe('₦1,000.00 -> ₦900.00 (-10%)')
        // PHP's JSON encoder drops the zero fraction, so an integral naira
        // amount decodes as an int — cast before the strict float compare.
        ->and((float) $water['final_amount'])->toBe(900.0)
        ->and($water['currency']['symbol'])->toBe('₦');

    // Opening the catalogue is itself audited (WS-1 middleware).
    expect(ActivityLog::query()->where('action', 'admin_route_accessed')->exists())->toBeTrue();
});

test('the list paginates at the legacy page sizes and filters by status, search, store and range', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    [$otherOwner, $otherBusiness] = createBusinessOwner();

    $currency = ad15Currency();

    $store = ad15Store($owner, $business, ['name' => 'Lekki Store']);
    $otherStore = ad15Store($otherOwner, $otherBusiness, ['name' => 'Surulere Store']);
    $category = ad15Category($store, ['name' => 'Snacks']);

    ad15Product($store, [
        'name' => 'Plantain Chips',
        'category_id' => $category->id,
        'currency_id' => $currency->id,
        'status' => 'active',
    ]);

    $old = ad15Product($store, ['name' => 'Old Item', 'status' => 'inactive']);
    $old->forceFill(['created_at' => now()->subDays(10)])->save();

    ad15Product($otherStore, ['name' => 'Foreign Widget', 'status' => 'active']);

    $token = ad15Token($admin);

    // Legacy per-page selector: default 10, 50 and 100 selectable.
    for ($i = 0; $i < 9; $i++) {
        ad15Product($store, ['name' => 'Filler '.$i]);
    }

    $this->withToken($token)->getJson('/api/v1/admin/products')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 12);

    $this->withToken($token)->getJson('/api/v1/admin/products?per_page=50')
        ->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.per_page', 50);

    $names = function (string $query) use ($token) {
        // The membership assertions below are about the filter, not the page:
        // ask for a full page so the default size of 10 cannot hide the 11th
        // match (the `from` range matches every product except `Old Item`).
        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/products?'.$query.'&per_page=100')
            ->assertOk();

        return collect($response->json('data'))->pluck('name');
    };

    // q matches name, product code, store name and category name (legacy).
    expect($names('q=Plantain'))->toContain('Plantain Chips')->not->toContain('Foreign Widget');
    expect($names('q=Lekki'))->toContain('Plantain Chips')->not->toContain('Foreign Widget');
    expect($names('q=Snacks'))->toContain('Plantain Chips')->not->toContain('Foreign Widget');
    expect($names('q='.urlencode('Foreign Widget')))->toContain('Foreign Widget')->not->toContain('Plantain Chips');

    $code = Product::where('name', 'Plantain Chips')->first()->product_code;
    expect($names('q='.$code))->toContain('Plantain Chips');

    expect($names('status=inactive'))->toContain('Old Item')->not->toContain('Plantain Chips');

    // The URL-only store scope accepts the numeric id and the public st_ id.
    expect($names('store_id='.$store->id))->toContain('Plantain Chips')->not->toContain('Foreign Widget');
    expect($names('store_id='.$store->store_id))->toContain('Plantain Chips')->not->toContain('Foreign Widget');

    // An unresolvable store must list nothing — legacy dropped the filter and
    // returned the whole platform.
    expect($names('store_id=st_does_not_exist'))->toBeEmpty();

    expect($names('from='.now()->subDays(2)->toDateString()))
        ->toContain('Plantain Chips')
        ->not->toContain('Old Item');

    expect($names('to='.now()->subDays(2)->toDateString()))
        ->toContain('Old Item')
        ->not->toContain('Plantain Chips');
});

test('the store-scoped list only returns that store products', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    [$otherOwner, $otherBusiness] = createBusinessOwner();

    $store = ad15Store($owner, $business);
    $otherStore = ad15Store($otherOwner, $otherBusiness);

    ad15Product($store, ['name' => 'Mine']);
    ad15Product($otherStore, ['name' => 'Theirs']);

    $response = $this->withToken(ad15Token($admin))
        ->getJson('/api/v1/admin/stores/'.$store->store_id.'/products')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Mine']);
});

test('a product is created with a generated code, stock ledger entry and audit row', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $currency = ad15Currency();
    $store = ad15Store($owner, $business);
    $category = ad15Category($store);

    $response = $this->withToken(ad15Token($admin))->postJson('/api/v1/admin/products', [
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Generator Fuel',
        'brand' => 'Total',
        'tags' => 'fuel, diesel',
        'description' => 'Diesel in a jerry can.',
        'status' => 'active',
        'quantity' => 7,
        'amount' => 2500.50,
        'currency_id' => $currency->id,
        'discount_percentage' => 5,
        'featured' => true,
        'cod_available' => true,
        'has_variants' => false,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.product.name', 'Generator Fuel')
        ->assertJsonPath('data.product.store', $store->name)
        ->assertJsonPath('data.product.category', $category->name)
        ->assertJsonPath('data.product.status', 'active')
        ->assertJsonPath('data.product.featured', true)
        ->assertJsonPath('data.product.quantity', 7)
        ->assertJsonPath('message', 'Product created.');

    $product = Product::where('name', 'Generator Fuel')->firstOrFail();

    expect($response->json('data.product.product_code'))->toBe($product->product_code)
        ->and($product->product_code)->toStartWith('prd_')
        ->and($product->slug)->toStartWith('generator-fuel-')
        ->and((int) $product->business_id)->toBe((int) $business->id);

    // The initial stock ledger the acceptance criteria demand.
    $location = StockLocation::where('product_id', $product->id)
        ->where('locationable_type', Store::class)
        ->where('locationable_id', $store->id)
        ->first();

    expect($location)->not->toBeNull()
        ->and($location->quantity)->toBe(7);

    $movement = StockMovement::where('product_id', $product->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe('added')
        ->and($movement->quantity)->toBe(7)
        ->and($movement->balance_after)->toBe(7)
        ->and($movement->notes)->toBe('Product created (Admin) — initial stock')
        ->and((int) $movement->performed_by_id)->toBe($admin->id);

    expect(ActivityLog::query()->where('action', 'create_product')->where('subject_id', $product->id)->exists())->toBeTrue();
});

test('product create enforces the catalogue validation rules', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $store = ad15Store($owner, $business);
    [$otherOwner, $otherBusiness] = createBusinessOwner();
    $otherStore = ad15Store($otherOwner, $otherBusiness);
    $foreignCategory = ad15Category($otherStore);

    $token = ad15Token($admin);
    $base = [
        'store_id' => $store->id,
        'name' => 'Valid Name',
        'status' => 'active',
        'quantity' => 3,
        'amount' => 100,
    ];

    $this->withToken($token)->postJson('/api/v1/admin/products', array_merge($base, ['name' => '']))
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', 'The name field is required.');

    $this->withToken($token)->postJson('/api/v1/admin/products', array_merge($base, ['quantity' => 0]))
        ->assertStatus(422)
        ->assertJsonPath('errors.quantity.0', 'Quantity must be greater than 0.');

    $this->withToken($token)->postJson('/api/v1/admin/products', array_merge($base, ['amount' => 0]))
        ->assertStatus(422)
        ->assertJsonPath('errors.amount.0', 'Amount must be greater than 0.');

    $this->withToken($token)->postJson('/api/v1/admin/products', array_merge($base, ['store_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonPath('errors.store_id.0', 'Choose a store that still exists.');

    // A category must belong to the store it is attached to — legacy allowed
    // any category id.
    $this->withToken($token)->postJson('/api/v1/admin/products', array_merge($base, ['category_id' => $foreignCategory->id]))
        ->assertStatus(422)
        ->assertJsonPath('errors.category_id.0', 'The selected category does not belong to this store.');

    // Deleted stores cannot receive new products.
    $deleted = ad15Store($owner, $business, ['status' => Store::STATUS_DELETED]);

    $this->withToken($token)->postJson('/api/v1/admin/products', array_merge($base, ['store_id' => $deleted->id]))
        ->assertStatus(422)
        ->assertJsonPath('errors.store_id.0', 'Choose a store that still exists.');

    // Variant-led products must actually carry variants.
    $this->withToken($token)->postJson('/api/v1/admin/products', [
        'store_id' => $store->id,
        'name' => 'Variant Product',
        'status' => 'active',
        'has_variants' => true,
        'variants' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('variants');

    expect(Product::count())->toBe(0);
});

test('a product can be created with variants and no base price', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $currency = ad15Currency();
    $store = ad15Store($owner, $business);

    $response = $this->withToken(ad15Token($admin))->postJson('/api/v1/admin/products', [
        'store_id' => $store->id,
        'name' => 'T-Shirt',
        'status' => 'active',
        'has_variants' => true,
        'variants' => [
            ['sku' => 'TS-S', 'size' => 38, 'quantity' => 4, 'amount' => 1500, 'currency_id' => $currency->id, 'status' => 'active'],
            ['sku' => 'TS-M', 'size' => 40, 'quantity' => 2, 'amount' => 1600, 'currency_id' => $currency->id, 'status' => 'active'],
            ['sku' => 'TS-L', 'size' => 42, 'quantity' => 1, 'amount' => 1700, 'currency_id' => $currency->id, 'status' => 'active'],
        ],
    ]);

    $response->assertCreated()->assertJsonCount(3, 'data.product.variants');

    // The legacy range cell for variant products. (Integral amounts arrive as
    // JSON ints — cast before the strict float comparison.)
    expect($response->json('data.product.display_price'))->toBe('₦1,500.00 - ₦1,700.00')
        ->and((float) $response->json('data.product.variant_price.min'))->toBe(1500.0)
        ->and((float) $response->json('data.product.variant_price.max'))->toBe(1700.0);

    $product = Product::where('name', 'T-Shirt')->firstOrFail();

    expect($product->has_variants)->toBeTrue()
        ->and($product->amount)->toBeNull()
        ->and($product->variants()->count())->toBe(3)
        ->and($product->variants()->where('sku', 'TS-S')->first()->quantity)->toBe(4);

    // No base quantity means no initial ledger entry.
    expect(StockMovement::query()->count())->toBe(0);
});

test('created images land in position order with the chosen primary', function () {
    Storage::fake('public');

    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);

    $response = $this->withToken(ad15Token($admin))->post('/api/v1/admin/products', [
        'store_id' => $store->id,
        'name' => 'Photo Product',
        'status' => 'active',
        'quantity' => 2,
        'amount' => 300,
        'images' => [
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('side.jpg'),
            UploadedFile::fake()->image('back.jpg'),
        ],
        'primary_image' => 1,
    ]);

    $response->assertCreated()->assertJsonCount(3, 'data.product.images');

    $images = $response->json('data.product.images');

    expect($images[1]['is_primary'])->toBeTrue()
        ->and($images[0]['is_primary'])->toBeFalse()
        ->and(array_column($images, 'position'))->toBe([0, 1, 2]);

    foreach ($images as $image) {
        Storage::disk('public')->assertExists($image['path']);
    }
});

test('an oversized image is rejected with human-readable copy', function () {
    Storage::fake('public');

    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);

    $response = $this->withToken(ad15Token($admin))->post('/api/v1/admin/products', [
        'store_id' => $store->id,
        'name' => 'Huge Image Product',
        'status' => 'active',
        'quantity' => 1,
        'amount' => 100,
        'images' => [UploadedFile::fake()->image('huge.jpg')->size(21000)],
    ]);

    $response->assertStatus(422);

    // The wildcard key is flat ("images.0"), so read the errors bag directly
    // rather than through dot-notation.
    expect($response->json('errors')['images.0'][0] ?? null)->toBe('Each image must be 20 MB or smaller.');
});

test('update synchronises variants in place, creates new rows and deletes the missing ones', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $currency = ad15Currency();
    $store = ad15Store($owner, $business);

    $product = ad15Product($store, ['name' => 'Hoodie', 'has_variants' => true, 'amount' => null, 'quantity' => 0]);

    $keep = ProductVariant::create(['product_id' => $product->id, 'sku' => 'KEEP', 'quantity' => 1, 'amount' => 100, 'currency_id' => $currency->id]);
    $drop = ProductVariant::create(['product_id' => $product->id, 'sku' => 'DROP', 'quantity' => 1, 'amount' => 200, 'currency_id' => $currency->id]);

    $response = $this->withToken(ad15Token($admin))->putJson('/api/v1/admin/products/'.$product->product_code, [
        'name' => 'Hoodie',
        'status' => 'active',
        'has_variants' => true,
        'variants' => [
            ['id' => $keep->id, 'sku' => 'KEEP', 'quantity' => 9, 'amount' => 150, 'currency_id' => $currency->id, 'status' => 'active'],
            ['sku' => 'NEW', 'quantity' => 2, 'amount' => 250, 'currency_id' => $currency->id, 'status' => 'active'],
        ],
    ]);

    $response->assertOk()->assertJsonCount(2, 'data.product.variants');

    expect($keep->fresh()->quantity)->toBe(9)
        ->and((float) $keep->fresh()->amount)->toBe(150.0)
        ->and(ProductVariant::find($drop->id))->toBeNull()
        ->and(ProductVariant::where('product_id', $product->id)->where('sku', 'NEW')->exists())->toBeTrue();

    expect(ActivityLog::query()->where('action', 'update_product')->exists())->toBeTrue();

    // Turning variants off deletes every row (legacy's single-SKU branch).
    $this->withToken(ad15Token($admin))->putJson('/api/v1/admin/products/'.$product->product_code, [
        'name' => 'Hoodie',
        'status' => 'active',
        'has_variants' => false,
        'quantity' => 5,
        'amount' => 900,
    ])->assertOk();

    expect(ProductVariant::where('product_id', $product->id)->count())->toBe(0)
        ->and($product->fresh()->has_variants)->toBeFalse();
});

test('a forged variant id from another product is refused', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);

    $product = ad15Product($store, ['has_variants' => true, 'amount' => null, 'quantity' => 0]);
    $other = ad15Product($store, ['has_variants' => true, 'amount' => null, 'quantity' => 0]);

    $foreign = ProductVariant::create(['product_id' => $other->id, 'sku' => 'FOREIGN', 'quantity' => 1, 'amount' => 100]);

    $this->withToken(ad15Token($admin))->putJson('/api/v1/admin/products/'.$product->product_code, [
        'has_variants' => true,
        'variants' => [
            ['id' => $foreign->id, 'quantity' => 5, 'amount' => 500],
        ],
    ])->assertStatus(422);

    expect($foreign->fresh()->quantity)->toBe(1);
});

test('update deletes images, promotes a new primary and clears bulk pricing when emptied', function () {
    Storage::fake('public');

    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);

    // The bulk columns are not in Product's `$fillable`, so persist them
    // explicitly — the start state is what the clearing update must remove.
    $product = ad15Product($store);
    $product->forceFill(['bulk_quantity' => 10, 'bulk_price' => 8000])->save();

    $first = ProductImage::create(['product_id' => $product->id, 'path' => 'products/images/first.jpg', 'is_primary' => true, 'position' => 0]);
    $second = ProductImage::create(['product_id' => $product->id, 'path' => 'products/images/second.jpg', 'is_primary' => false, 'position' => 1]);

    Storage::disk('public')->put($first->path, 'x');
    Storage::disk('public')->put($second->path, 'x');

    $response = $this->withToken(ad15Token($admin))->putJson('/api/v1/admin/products/'.$product->product_code, [
        'name' => $product->name,
        'status' => 'active',
        'quantity' => 5,
        'amount' => 1000,
        'delete_image_ids' => [$first->id],
        'primary_image_id' => $second->id,
        'bulk_quantity' => '',
        'bulk_price' => '',
    ]);

    $response->assertOk();

    expect(ProductImage::find($first->id))->toBeNull()
        ->and(ProductImage::find($second->id)->is_primary)->toBeTrue();

    Storage::disk('public')->assertMissing($first->path);

    // "Remove the bulk price and save" has to actually remove it.
    expect($product->fresh()->bulk_quantity)->toBeNull()
        ->and($product->fresh()->bulk_price)->toBeNull();

    // ...but a partial payload that never mentions bulk pricing must not wipe
    // it (the same "only write what the payload carries" rule as the flags).
    // Refresh first: the clearing update above emptied the columns in the
    // database, and forcing the same values back onto the stale in-memory
    // model would be a no-op (Eloquent skips a save when nothing is dirty).
    $product->refresh()->forceFill(['bulk_quantity' => 10, 'bulk_price' => 8000])->save();

    $this->withToken(ad15Token($admin))->putJson('/api/v1/admin/products/'.$product->product_code, [
        'name' => $product->name,
    ])->assertOk();

    expect($product->fresh()->bulk_quantity)->toBe(10)
        ->and((float) $product->fresh()->bulk_price)->toBe(8000.0);
});

test('product status can be toggled and only active or inactive are accepted', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);

    $product = ad15Product($store, ['status' => 'active']);

    $this->withToken(ad15Token($admin))
        ->putJson('/api/v1/admin/products/'.$product->product_code.'/status', ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.product.status', 'inactive')
        ->assertJsonPath('message', 'Product deactivated.');

    expect($product->fresh()->status)->toBe('inactive');

    $this->withToken(ad15Token($admin))
        ->putJson('/api/v1/admin/products/'.$product->product_code.'/status', ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('message', 'Product activated.');

    $this->withToken(ad15Token($admin))
        ->putJson('/api/v1/admin/products/'.$product->product_code.'/status', ['status' => 'deleted'])
        ->assertStatus(422);

    expect(ActivityLog::query()->where('action', 'product_status_updated')->count())->toBe(2);
});

test('delete removes the product, its images and their files', function () {
    Storage::fake('public');

    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);

    $product = ad15Product($store, ['name' => 'Disposable']);
    $image = ProductImage::create(['product_id' => $product->id, 'path' => 'products/images/gone.jpg', 'is_primary' => true, 'position' => 0]);
    Storage::disk('public')->put($image->path, 'x');

    $this->withToken(ad15Token($admin))
        ->deleteJson('/api/v1/admin/products/'.$product->product_code)
        ->assertOk()
        ->assertJsonPath('message', 'Product deleted.');

    expect(Product::find($product->id))->toBeNull();
    Storage::disk('public')->assertMissing($image->path);

    $this->withToken(ad15Token($admin))
        ->getJson('/api/v1/admin/products/'.$product->product_code)
        ->assertStatus(404);
});

test('the detail and store-scoped detail return the tab payloads', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    [$otherOwner, $otherBusiness] = createBusinessOwner();

    $currency = ad15Currency();
    $store = ad15Store($owner, $business);
    $otherStore = ad15Store($otherOwner, $otherBusiness);

    $product = ad15Product($store, [
        'name' => 'Detailed Product',
        'description' => '<p>Rich text</p>',
        'currency_id' => $currency->id,
        'size' => 12.5,
        'weight' => 1.2,
        'color' => 'Blue',
        'stock_quantity' => 20,
        'cost_price' => 400,
    ]);

    $token = ad15Token($admin);

    $this->withToken($token)
        ->getJson('/api/v1/admin/products/'.$product->product_code)
        ->assertOk()
        ->assertJsonPath('data.product.name', 'Detailed Product')
        ->assertJsonPath('data.product.color', 'Blue')
        ->assertJsonPath('data.product.sold_quantity', 15)
        // assertJsonPath compares strictly; an integral decimal encodes as an
        // int in JSON, so read it through a float cast.
        ->assertJsonPath('data.product.cost_price', fn ($value) => (float) $value === 400.0)
        ->assertJsonStructure(['data' => ['product' => ['images', 'variants', 'display_price', 'store', 'category']]]);

    // Store-scoped detail resolves by code inside the store and cannot be
    // reached from another store's URL.
    $this->withToken($token)
        ->getJson('/api/v1/admin/stores/'.$store->store_id.'/products/'.$product->product_code)
        ->assertOk()
        ->assertJsonPath('data.product.product_code', $product->product_code);

    $this->withToken($token)
        ->getJson('/api/v1/admin/stores/'.$otherStore->store_id.'/products/'.$product->product_code)
        ->assertStatus(404);
});

test('the form options endpoint serves the create/edit pickers', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    ad15Currency();
    $store = ad15Store($owner, $business, ['name' => 'Picker Store']);
    ad15Category($store, ['name' => 'Picker Category']);

    $response = $this->withToken(ad15Token($admin))
        ->getJson('/api/v1/admin/products/form-options')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['stores', 'categories', 'currencies', 'size_units', 'weight_units', 'default_currency_id', 'image_max_kb'],
        ]);

    expect(collect($response->json('data.stores'))->pluck('name'))->toContain('Picker Store')
        ->and(collect($response->json('data.categories'))->pluck('name'))->toContain('Picker Category')
        ->and(collect($response->json('data.currencies'))->pluck('symbol'))->toContain('₦')
        ->and($response->json('data.image_max_kb'))->toBe(20480);
});

test('the category directory lists every store ordered by store then name', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    [$otherOwner, $otherBusiness] = createBusinessOwner();

    $store = ad15Store($owner, $business);
    $otherStore = ad15Store($otherOwner, $otherBusiness);

    ad15Category($store, ['name' => 'Zebra']);
    ad15Category($store, ['name' => 'Aardvark']);
    ad15Category($otherStore, ['name' => 'Mango']);

    ad15Product($store, ['name' => 'Counted', 'category_id' => Category::where('name', 'Aardvark')->first()->id]);

    $response = $this->withToken(ad15Token($admin))->getJson('/api/v1/admin/categories')->assertOk();

    $rows = collect($response->json('data'));

    expect($rows->pluck('name')->all())->toBe(['Aardvark', 'Zebra', 'Mango'])
        ->and($rows->firstWhere('name', 'Aardvark')['products_count'])->toBe(1)
        ->and($rows->firstWhere('name', 'Aardvark')['store'])->toBe($store->name);

    // Store-scoped variant.
    $scoped = $this->withToken(ad15Token($admin))
        ->getJson('/api/v1/admin/stores/'.$store->store_id.'/categories')
        ->assertOk();

    expect(collect($scoped->json('data'))->pluck('name')->all())->toBe(['Aardvark', 'Zebra']);

    // Filters.
    $filtered = $this->withToken(ad15Token($admin))->getJson('/api/v1/admin/categories?status=inactive')->assertOk();
    expect($filtered->json('data'))->toBeEmpty();

    $search = $this->withToken(ad15Token($admin))->getJson('/api/v1/admin/categories?q=Mango')->assertOk();
    expect(collect($search->json('data'))->pluck('name')->all())->toBe(['Mango']);
});

test('a category is created with a uuid-fragment slug, editor-stable, and delete uncategorises products', function () {
    $admin = ad15SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $store = ad15Store($owner, $business);
    $category = ad15Category($store, ['name' => 'Drinks']);
    $product = ad15Product($store, ['name' => 'Fanta', 'category_id' => $category->id]);

    $token = ad15Token($admin);

    $created = $this->withToken($token)->postJson('/api/v1/admin/categories', [
        'store_id' => $store->id,
        'name' => 'Hot Drinks',
        'status' => 'active',
    ]);

    $created->assertCreated()->assertJsonPath('data.category.name', 'Hot Drinks');

    $createdId = $created->json('data.category.id');

    // Legacy slug: Str::slug(name) + a 6-character uuid fragment.
    expect($created->json('data.category.slug'))->toMatch('/^hot-drinks-[a-f0-9]{6}$/')
        ->and((int) $created->json('data.category.store_id'))->toBe($store->id);

    // Editing without renaming keeps the slug stable.
    $slugBefore = $created->json('data.category.slug');

    $this->withToken($token)->putJson('/api/v1/admin/categories/'.$createdId, [
        'store_id' => $store->id,
        'name' => 'Hot Drinks',
        'status' => 'inactive',
    ])->assertOk()->assertJsonPath('data.category.slug', $slugBefore)
        ->assertJsonPath('data.category.status', 'inactive');

    // Renaming regenerates it.
    $renamed = $this->withToken($token)->putJson('/api/v1/admin/categories/'.$createdId, [
        'store_id' => $store->id,
        'name' => 'Warm Drinks',
        'status' => 'active',
    ])->assertOk();

    expect($renamed->json('data.category.slug'))->toMatch('/^warm-drinks-[a-f0-9]{6}$/')
        ->and($renamed->json('data.category.slug'))->not->toBe($slugBefore);

    // Deleting a category counts the products it leaves uncategorised.
    $deleted = $this->withToken($token)->deleteJson('/api/v1/admin/categories/'.$category->id)
        ->assertOk()
        ->assertJsonPath('data.uncategorised_products', 1);

    expect($deleted->json('message'))->toContain('1 product(s) are now uncategorised')
        ->and(Product::find($product->id)->category_id)->toBeNull();
});

test('a platform admin without the products permission is refused', function () {
    $admin = ad15AdminWithRole('Finance Admin');
    [$owner, $business] = createBusinessOwner();
    ad15Store($owner, $business);

    $token = ad15Token($admin);

    $this->withToken($token)->getJson('/api/v1/admin/products')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/admin/categories')->assertStatus(403);
    $this->withToken($token)->postJson('/api/v1/admin/categories', [
        'store_id' => 1, 'name' => 'Nope', 'status' => 'active',
    ])->assertStatus(403);
});

test('a platform admin with the products permission can read the catalogue', function () {
    $admin = ad15AdminWithRole('Platform Admin');
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);
    ad15Product($store, ['name' => 'Visible']);

    $this->withToken(ad15Token($admin))
        ->getJson('/api/v1/admin/products')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Visible');
});

test('a business-scoped account with a leaked admin token cannot touch the catalogue', function () {
    [$owner, $business] = createBusinessOwner();
    $store = ad15Store($owner, $business);
    $product = ad15Product($store);

    $token = ad15Token($owner);
    $guardMessage = 'This endpoint is restricted to platform administrators.';

    $this->withToken($token)->getJson('/api/v1/admin/products')
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)->postJson('/api/v1/admin/products', [
        'store_id' => $store->id, 'name' => 'Sneaky', 'status' => 'active', 'quantity' => 1, 'amount' => 10,
    ])->assertStatus(403)->assertJsonPath('message', $guardMessage);

    $this->withToken($token)->putJson('/api/v1/admin/products/'.$product->product_code.'/status', ['status' => 'inactive'])
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)->deleteJson('/api/v1/admin/products/'.$product->product_code)
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)->getJson('/api/v1/admin/categories')->assertStatus(403);

    expect($product->fresh()->status)->toBe('active')
        ->and($product->fresh())->not->toBeNull();
});

test('a management token is refused by the admin audience', function () {
    [$owner] = createBusinessOwner();

    $token = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/admin/products')
        ->assertStatus(403)
        ->assertJsonPath('message', 'This token is not valid for this application.');
});
