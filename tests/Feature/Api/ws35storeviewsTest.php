<?php

use App\Models\Business;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;

/**
 * WS-35 — the view counters behind "Store views" and "Product views".
 *
 * The counters themselves are read by the web-metrics tiles; before this the
 * only writer was the legacy Blade app, so the replacement SPA left both at
 * zero. These tests cover the writer the storefront now calls.
 *
 * @return array{0: Store, 1: Business}
 */
function ws35vContext(array $storeAttributes = []): array
{
    // The owner is on trial, so the management group's subscription gate lets
    // the metrics read through — the counters themselves do not depend on a
    // plan. `trial_ends_at` is the owner's, not the business's.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create(array_merge([
        // The owner carries the store; the metrics read checks accessibility
        // against exactly that.
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'View Shop',
        'slug' => 'views-shop-'.random_int(1000, 9999),
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ], $storeAttributes));

    return [$store, $business];
}

function ws35vProduct(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'Viewed Product',
        'amount' => 1000,
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));
}

/** The URL the storefront's product cards build: `{slug}-{product_code}`. */
function ws35vSlugCode(Product $product): string
{
    return $product->slug.'-'.$product->product_code;
}

function ws35vToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

test('every reported store view lands on the store counter', function () {
    [$store] = ws35vContext();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', ['kind' => 'store'])
        ->assertStatus(202)
        ->assertJsonPath('data.kind', 'store');

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', ['kind' => 'store'])
        ->assertStatus(202);

    expect((int) $store->refresh()->views)->toBe(2);
});

test('a product view is counted against the product the link names', function () {
    [$store, $business] = ws35vContext();

    $viewed = ws35vProduct($store, ['name' => 'Viewed']);
    $unviewed = ws35vProduct($store, ['name' => 'Unviewed']);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', [
        'kind' => 'product',
        'product' => ws35vSlugCode($viewed),
    ])->assertStatus(202);

    expect((int) $viewed->refresh()->views)->toBe(1)
        ->and((int) $unviewed->refresh()->views)->toBe(0);

    // A bare product code is also a product page URL, and must attribute the
    // same way.
    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', [
        'kind' => 'product',
        'product' => $viewed->product_code,
    ])->assertStatus(202);

    expect((int) $viewed->refresh()->views)->toBe(2)
        ->and((int) $unviewed->refresh()->views)->toBe(0);
});

test('a product view cannot be credited to another store', function () {
    [$store] = ws35vContext();
    [$otherStore] = ws35vContext(['name' => 'Other Shop']);

    $foreign = ws35vProduct($otherStore, ['name' => 'Foreign']);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', [
        'kind' => 'product',
        'product' => ws35vSlugCode($foreign),
    ])->assertNotFound();

    expect((int) $foreign->refresh()->views)->toBe(0);
});

test('a product no longer on sale is not counted', function () {
    [$store] = ws35vContext();

    $product = ws35vProduct($store, ['name' => 'Withdrawn', 'status' => 'inactive']);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', [
        'kind' => 'product',
        'product' => ws35vSlugCode($product),
    ])->assertNotFound();

    expect((int) $product->refresh()->views)->toBe(0);
});

test('a malformed report is refused before anything is counted', function () {
    [$store] = ws35vContext();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kind');

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', ['kind' => 'product'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product');

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', ['kind' => 'service'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kind');

    expect((int) $store->refresh()->views)->toBe(0);
});

test('a switched-off storefront counts nothing', function () {
    [$store] = ws35vContext(['has_website' => false]);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', ['kind' => 'store'])
        ->assertNotFound()
        ->assertJsonPath('errors.store.0', 'storefront_offline');

    expect((int) $store->refresh()->views)->toBe(0);
});

test('the counters reach the web-metrics tiles', function () {
    [$store, $business] = ws35vContext();

    $product = ws35vProduct($store, ['name' => 'Counted']);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', ['kind' => 'store']);
    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', ['kind' => 'store']);
    $this->postJson('/api/v1/storefront/'.$store->slug.'/views', [
        'kind' => 'product',
        'product' => ws35vSlugCode($product),
    ]);

    $owner = User::query()->findOrFail($business->user_id);

    $this->withToken(ws35vToken($owner))
        ->getJson('/api/v1/management/stores/'.$store->store_id.'/web-metrics')
        ->assertOk()
        ->assertJsonPath('data.metrics.store_views', 2)
        ->assertJsonPath('data.metrics.product_views', 1);
});
