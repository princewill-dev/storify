<?php

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-31 — Categories Polish & Audit Logging
|--------------------------------------------------------------------------
| Covers what this workstream repaired over the base slice: the store/name
| filters and pager (so categories beyond one page are reachable), slug
| regeneration on rename, the `parent_id` guard from roadmap item D9, deleted
| -store exclusion, the ActivityLog trail on every write, and the
| cross-tenant refusals.
*/

function ws31Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws31Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'ws31-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws31Category(User $owner, Store $store, array $attributes = []): Category
{
    return Category::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $owner->business_id,
        'name' => 'Shoes',
        'slug' => 'shoes-'.Str::lower(Str::random(6)),
        'status' => 'active',
    ], $attributes));
}

function ws31Staff(Business $business, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ], $attributes));
}

test('the category list carries slugs, product counts and pagination meta', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);

    $bags = ws31Category($owner, $store, ['name' => 'Bags']);
    ws31Category($owner, $store, ['name' => 'Accessories']);

    Product::create([
        'store_id' => $store->id,
        'business_id' => $owner->business_id,
        'category_id' => $bags->id,
        'name' => 'Tote Bag',
        'amount' => 5000,
        'quantity' => 3,
        'status' => 'active',
    ]);

    $response = $this->withToken(ws31Token($owner))
        ->getJson('/api/v1/management/categories?per_page=20');

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Accessories')
        ->assertJsonPath('data.1.name', 'Bags')
        ->assertJsonPath('data.1.products_count', 1)
        ->assertJsonStructure([
            'data' => [['id', 'name', 'slug', 'store_id', 'status', 'products_count']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.1.slug'))->toBe($bags->slug)
        // Roadmap D9: the half-wired hierarchy is gone from the payload too.
        ->and($response->json('data.1'))->not->toHaveKey('parent_id');
});

test('the category list paginates so categories beyond one page stay reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);

    foreach (range(1, 25) as $i) {
        ws31Category($owner, $store, ['name' => sprintf('Category %02d', $i)]);
    }

    $first = $this->withToken(ws31Token($owner))
        ->getJson('/api/v1/management/categories?per_page=10&page=1');
    $third = $this->withToken(ws31Token($owner))
        ->getJson('/api/v1/management/categories?per_page=10&page=3');

    $first->assertOk()->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 3);
    expect($first->json('data'))->toHaveCount(10);

    $third->assertOk()->assertJsonPath('meta.current_page', 3);
    expect($third->json('data'))->toHaveCount(5)
        ->and($third->json('data.0.name'))->toBe('Category 21');
});

test('the category list filters by internal store id and name search', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $lagos = ws31Store($owner, ['name' => 'Lagos']);
    $abuja = ws31Store($owner, ['name' => 'Abuja']);

    ws31Category($owner, $lagos, ['name' => 'Running Shoes']);
    ws31Category($owner, $abuja, ['name' => 'Running Socks']);
    ws31Category($owner, $abuja, ['name' => 'Bags']);

    $token = ws31Token($owner);

    $byStore = $this->withToken($token)->getJson('/api/v1/management/categories?store_id='.$lagos->id);
    $byStore->assertOk();
    expect(array_column($byStore->json('data'), 'name'))->toBe(['Running Shoes']);

    $bySearch = $this->withToken($token)->getJson('/api/v1/management/categories?q=Runn');
    $bySearch->assertOk();
    expect(array_column($bySearch->json('data'), 'name'))->toBe(['Running Shoes', 'Running Socks']);

    $combined = $this->withToken($token)
        ->getJson('/api/v1/management/categories?store_id='.$abuja->id.'&q=Runn');
    $combined->assertOk();
    expect(array_column($combined->json('data'), 'name'))->toBe(['Running Socks']);
});

test('the category list excludes other businesses and deleted stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws31Store($owner, ['name' => 'Mine']);
    $gone = ws31Store($owner, ['name' => 'Gone', 'status' => Store::STATUS_DELETED]);

    ws31Category($owner, $mine, ['name' => 'Kept']);
    ws31Category($owner, $gone, ['name' => 'Deleted Store Category']);
    ws31Category($otherOwner, ws31Store($otherOwner), ['name' => 'Theirs']);

    $response = $this->withToken(ws31Token($owner))->getJson('/api/v1/management/categories');

    $response->assertOk();
    expect(array_column($response->json('data'), 'name'))->toBe(['Kept']);
});

test('filtering by a store from another business is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $otherStore = ws31Store($otherOwner, ['name' => 'Their Shop']);

    $this->withToken(ws31Token($owner))
        ->getJson('/api/v1/management/categories?store_id='.$otherStore->id)
        ->assertStatus(403);
});

test('the per_page whitelist rejects sizes the list UI does not offer', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws31Token($owner))
        ->getJson('/api/v1/management/categories?per_page=500')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('a category is created with a generated slug and an activity log entry', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);

    $response = $this->withToken(ws31Token($owner))->postJson('/api/v1/management/categories', [
        'name' => 'Sneakers',
        'store_id' => $store->id,
        'status' => 'inactive',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.category.name', 'Sneakers')
        ->assertJsonPath('data.category.status', 'inactive')
        ->assertJsonPath('data.category.store_id', $store->id);

    expect($response->json('data.category.slug'))->toStartWith('sneakers-');

    $categoryId = $response->json('data.category.id');

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'category_created',
        'subject_type' => Category::class,
        'subject_id' => $categoryId,
        'business_id' => $business->id,
        'user_id' => $owner->id,
    ]);

    // The create form used to hide the field its controller required; the
    // default stays active when no status is sent.
    $second = $this->withToken(ws31Token($owner))->postJson('/api/v1/management/categories', [
        'name' => 'Sandals',
        'store_id' => $store->id,
    ]);
    $second->assertCreated()->assertJsonPath('data.category.status', 'active');
});

test('category creation validates its fields and refuses foreign, deleted or nested parents', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws31Store($owner);
    $gone = ws31Store($owner, ['name' => 'Gone', 'status' => Store::STATUS_DELETED]);
    $otherStore = ws31Store($otherOwner, ['name' => 'Theirs']);
    $parent = ws31Category($owner, $store, ['name' => 'Parent']);

    $token = ws31Token($owner);

    $this->withToken($token)->postJson('/api/v1/management/categories', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'store_id']);

    $this->withToken($token)->postJson('/api/v1/management/categories', [
        'name' => 'Foreign',
        'store_id' => $otherStore->id,
    ])->assertStatus(422)->assertJsonPath('message', 'Invalid store selection.');

    $this->withToken($token)->postJson('/api/v1/management/categories', [
        'name' => 'Deleted Store',
        'store_id' => $gone->id,
    ])->assertStatus(422)->assertJsonPath('message', 'Invalid store selection.');

    $this->withToken($token)->postJson('/api/v1/management/categories', [
        'name' => 'Bad Status',
        'store_id' => $store->id,
        'status' => 'archived',
    ])->assertStatus(422)->assertJsonValidationErrors('status');

    $this->withToken($token)->postJson('/api/v1/management/categories', [
        'name' => 'Child',
        'store_id' => $store->id,
        'parent_id' => $parent->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('parent_id')
        ->assertJsonPath('message', 'Category hierarchy is not supported yet.');

    expect(Category::where('business_id', $business->id)->count())->toBe(1);
});

test('renaming a category regenerates its storefront slug', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);
    $category = ws31Category($owner, $store, ['name' => 'Shoes', 'slug' => 'shoes-abc123']);

    $renamed = $this->withToken(ws31Token($owner))->putJson('/api/v1/management/categories/'.$category->id, [
        'name' => 'Footwear',
    ]);

    $renamed->assertOk()->assertJsonPath('data.category.name', 'Footwear');

    $newSlug = $renamed->json('data.category.slug');
    expect($newSlug)->toStartWith('footwear-')
        ->and($newSlug)->not->toBe('shoes-abc123');

    $log = ActivityLog::where('action', 'category_updated')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->old_values['slug'])->toBe('shoes-abc123')
        ->and($log->new_values['slug'])->toBe($newSlug);

    // A status-only save leaves the storefront slug alone.
    $toggled = $this->withToken(ws31Token($owner))->putJson('/api/v1/management/categories/'.$category->id, [
        'name' => 'Footwear',
        'status' => 'inactive',
    ]);

    $toggled->assertOk()
        ->assertJsonPath('data.category.status', 'inactive')
        ->assertJsonPath('data.category.slug', $newSlug);
});

test('a category cannot be re-parented through the update endpoint', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);
    $category = ws31Category($owner, $store, ['name' => 'Shoes']);
    $parent = ws31Category($owner, $store, ['name' => 'Parent']);

    $this->withToken(ws31Token($owner))->putJson('/api/v1/management/categories/'.$category->id, [
        'parent_id' => $parent->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('parent_id')
        ->assertJsonPath('message', 'Category hierarchy is not supported yet.');

    expect($category->fresh()->parent_id)->toBeNull();
});

test('category updates and deletes are refused across tenants and deleted stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $gone = ws31Store($owner, ['name' => 'Gone', 'status' => Store::STATUS_DELETED]);
    $theirs = ws31Category($otherOwner, ws31Store($otherOwner));
    $orphaned = ws31Category($owner, $gone);

    $token = ws31Token($owner);

    $this->withToken($token)->putJson('/api/v1/management/categories/'.$theirs->id, ['name' => 'Nope'])
        ->assertStatus(403);
    $this->withToken($token)->deleteJson('/api/v1/management/categories/'.$theirs->id)
        ->assertStatus(403);

    $this->withToken($token)->putJson('/api/v1/management/categories/'.$orphaned->id, ['name' => 'Nope'])
        ->assertStatus(403);
    $this->withToken($token)->deleteJson('/api/v1/management/categories/'.$orphaned->id)
        ->assertStatus(403);

    expect($theirs->fresh()->name)->not->toBe('Nope');
});

test('deleting a category with products is refused with the 409 hint', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);
    $category = ws31Category($owner, $store, ['name' => 'Bags']);

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'category_id' => $category->id,
        'name' => 'Tote',
        'amount' => 5000,
        'quantity' => 1,
        'status' => 'active',
    ]);

    $this->withToken(ws31Token($owner))->deleteJson('/api/v1/management/categories/'.$category->id)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Move or delete the products in this category first.');

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
    $this->assertDatabaseMissing('activity_logs', [
        'action' => 'category_deleted',
        'subject_id' => $category->id,
    ]);
});

test('deleting an empty category removes it and records the activity log', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);
    $category = ws31Category($owner, $store, ['name' => 'Bags']);

    $this->withToken(ws31Token($owner))->deleteJson('/api/v1/management/categories/'.$category->id)
        ->assertOk();

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'category_deleted',
        'subject_type' => Category::class,
        'subject_id' => $category->id,
        'business_id' => $business->id,
        'user_id' => $owner->id,
    ]);
});

test('category endpoints are gated by products permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws31Store($owner);
    $category = ws31Category($owner, $store, ['name' => 'Kept']);

    // A staff member with no role holds no `products *` permissions at all.
    $staffToken = ws31Token(ws31Staff($business));

    $this->withToken($staffToken)->getJson('/api/v1/management/categories')->assertStatus(403);

    // A Store Associate can read (legacy gated the list behind `products
    // view`) but cannot create, edit or delete.
    $associate = ws31Staff($business);
    setPermissionsTeamId($business->id);
    $associate->assignRole('Store Associate');
    $associateToken = ws31Token($associate);

    $this->withToken($associateToken)->getJson('/api/v1/management/categories')->assertOk();

    $this->withToken($associateToken)->postJson('/api/v1/management/categories', [
        'name' => 'Nope',
        'store_id' => $store->id,
    ])->assertStatus(403);
    $this->withToken($associateToken)->putJson('/api/v1/management/categories/'.$category->id, ['name' => 'Nope'])
        ->assertStatus(403);
    $this->withToken($associateToken)->deleteJson('/api/v1/management/categories/'.$category->id)
        ->assertStatus(403);

    expect($category->fresh()->name)->toBe('Kept');

    $this->withToken(ws31Token($owner))->getJson('/api/v1/management/categories')->assertOk();
});
