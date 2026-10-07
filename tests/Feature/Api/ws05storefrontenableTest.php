<?php

use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;

function ws05Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws05Store(User $owner, array $attributes = []): Store
{
    static $sequence = 0;

    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.++$sequence,
        'slug' => 'ws05-store-'.$sequence,
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws05Url(string $slug): string
{
    return 'https://'.$slug.'.'.config('frontend.storefront_main_domain');
}

test('the storefront overview lists accessible stores with their online state', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws05Store($owner, ['name' => 'Offline Shop', 'slug' => 'offline-shop']);
    $live = ws05Store($owner, ['name' => 'Live Shop', 'slug' => 'live-shop', 'has_website' => true]);

    DeliveryRoute::create([
        'store_id' => $live->id,
        'business_id' => $live->business_id,
        'country' => 'Nigeria',
        'state' => 'All States',
        'area' => '',
        'fee' => 150000,
        'delivery_days' => 4,
        'active' => true,
    ]);

    $response = $this->withToken(ws05Token($owner))->getJson('/api/v1/management/storefront/stores');

    $response->assertOk()
        ->assertJsonPath('data.stores.0.name', 'Live Shop')
        ->assertJsonPath('data.stores.0.has_website', true)
        ->assertJsonPath('data.stores.0.storefront_url', ws05Url('live-shop'))
        ->assertJsonPath('data.stores.0.nationwide_delivery.fee', 150000)
        ->assertJsonPath('data.stores.0.nationwide_delivery.delivery_days', 4)
        ->assertJsonPath('data.stores.1.name', 'Offline Shop')
        ->assertJsonPath('data.stores.1.has_website', false)
        ->assertJsonPath('data.stores.1.storefront_url', null)
        ->assertJsonPath('data.stats.total', 2)
        ->assertJsonPath('data.stats.live', 1)
        ->assertJsonPath('data.stats.offline', 1);

    expect($response->json('meta.total'))->toBe(2);
});

test('the storefront overview excludes other businesses and deleted stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws05Store($owner, ['name' => 'Mine', 'slug' => 'mine']);
    ws05Store($owner, ['name' => 'Gone', 'slug' => 'gone', 'status' => Store::STATUS_DELETED]);
    ws05Store($otherOwner, ['name' => 'Theirs', 'slug' => 'theirs']);

    $response = $this->withToken(ws05Token($owner))->getJson('/api/v1/management/storefront/stores');

    $response->assertOk();

    expect(array_column($response->json('data.stores'), 'name'))->toBe(['Mine']);
});

test('the storefront overview filters by online state and search term', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws05Store($owner, ['name' => 'Lagos Outlet', 'slug' => 'lagos-outlet', 'has_website' => true]);
    ws05Store($owner, ['name' => 'Abuja Outlet', 'slug' => 'abuja-outlet']);

    $live = $this->withToken(ws05Token($owner))
        ->getJson('/api/v1/management/storefront/stores?storefront=live');
    $live->assertOk();
    expect(array_column($live->json('data.stores'), 'name'))->toBe(['Lagos Outlet']);

    $offline = $this->withToken(ws05Token($owner))
        ->getJson('/api/v1/management/storefront/stores?storefront=offline');
    $offline->assertOk();
    expect(array_column($offline->json('data.stores'), 'name'))->toBe(['Abuja Outlet']);

    $searched = $this->withToken(ws05Token($owner))
        ->getJson('/api/v1/management/storefront/stores?q=lagos');
    $searched->assertOk();
    expect(array_column($searched->json('data.stores'), 'name'))->toBe(['Lagos Outlet']);
});

test('the storefront detail reports delivery state and counts', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner, ['has_website' => true]);

    DeliveryRoute::create([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'country' => 'Nigeria',
        'state' => 'All States',
        'area' => '',
        'fee' => 50000,
        'delivery_days' => 3,
        'active' => true,
    ]);

    $this->withToken(ws05Token($owner))
        ->getJson("/api/v1/management/storefront/stores/{$store->store_id}")
        ->assertOk()
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.store.can_enable', false)
        ->assertJsonPath('data.store.delivery_routes_count', 1)
        ->assertJsonPath('data.store.nationwide_delivery.fee', 50000)
        ->assertJsonStructure(['data' => ['store' => ['id', 'store_id', 'name', 'slug', 'storefront_url']]]);
});

test('a slug check returns the slug and the live url preview', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws05Token($owner))
        ->postJson('/api/v1/management/stores/check-slug', ['name' => 'Ada Fashions'])
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.slug', 'ada-fashions')
        ->assertJsonPath('data.original', 'ada-fashions')
        ->assertJsonPath('data.url', ws05Url('ada-fashions'));
});

test('a taken slug is offered with a numbered suffix', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws05Store($owner, ['slug' => 'ada-fashions']);

    $this->withToken(ws05Token($owner))
        ->postJson('/api/v1/management/stores/check-slug', ['name' => 'Ada Fashions'])
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.slug', 'ada-fashions-1');
});

test('a reserved slug is never offered', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // Legacy only checked collisions, so "admin" read as available here and
    // then failed validation at submit.
    $this->withToken(ws05Token($owner))
        ->postJson('/api/v1/management/stores/check-slug', ['name' => 'Admin'])
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.slug', 'admin-1');
});

test('a slug check can ignore the store it is editing', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner, ['name' => 'Downtown', 'slug' => 'downtown']);

    $this->withToken(ws05Token($owner))
        ->postJson('/api/v1/management/stores/check-slug', [
            'name' => 'Downtown',
            'ignore_store' => $store->store_id,
        ])
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.slug', 'downtown');
});

test('a slug check without a name or slug is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws05Token($owner))
        ->postJson('/api/v1/management/stores/check-slug', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('enabling a storefront puts the store live and creates the nationwide delivery route', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner, ['name' => 'Old Name', 'slug' => 'old-name']);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'New Name',
            'slug' => 'new-name',
            'is_nationwide' => true,
            'nationwide_fee' => 1500,
            'nationwide_days' => 4,
        ])
        ->assertOk()
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.store.name', 'New Name')
        ->assertJsonPath('data.store.slug', 'new-name')
        ->assertJsonPath('data.store.storefront_url', ws05Url('new-name'))
        ->assertJsonPath('data.store.nationwide_delivery.fee', 150000)
        ->assertJsonPath('data.store.nationwide_delivery.delivery_days', 4);

    $store->refresh();
    expect($store->has_website)->toBeTrue();

    $route = DeliveryRoute::where('store_id', $store->id)->firstOrFail();

    expect($route->state)->toBe('All States')
        ->and($route->country)->toBe('Nigeria')
        ->and((int) $route->fee)->toBe(150000) // ₦1,500 -> kobo
        ->and((int) $route->delivery_days)->toBe(4)
        ->and((bool) $route->active)->toBeTrue()
        ->and((int) $route->business_id)->toBe((int) $store->business_id);
});

test('nationwide delivery defaults to three days and a zero fee', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Free Shipping Co',
            'slug' => 'free-shipping-co',
            'is_nationwide' => true,
        ])
        ->assertOk();

    $route = DeliveryRoute::where('store_id', $store->id)->firstOrFail();

    expect((int) $route->fee)->toBe(0)
        ->and((int) $route->delivery_days)->toBe(3)
        ->and($route->area)->toBe('');
});

test('enabling without nationwide delivery creates no route', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Pickup Only',
            'slug' => 'pickup-only',
        ])
        ->assertOk()
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.store.nationwide_delivery', null);

    expect(DeliveryRoute::where('store_id', $store->id)->count())->toBe(0);
});

test('enabling an already online store is refused rather than re-slugged', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner, ['name' => 'Live Shop', 'slug' => 'live-shop', 'has_website' => true]);

    // Legacy had no guard here, so a direct POST renamed and re-slugged a
    // live store out from under every link already shared.
    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Hijacked',
            'slug' => 'hijacked',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store');

    $store->refresh();
    expect($store->name)->toBe('Live Shop')
        ->and($store->slug)->toBe('live-shop');
});

test('the storefront wizard creates the storefront and ignores the legacy template field', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/storefront", [
            'store_name' => 'Wizard Shop',
            'slug' => 'wizard-shop',
            // Legacy demanded `template: basic` then threw the value away;
            // the chooser is gone, so any value is simply not read.
            'template' => 'nonsense',
            'is_nationwide' => true,
            'nationwide_fee' => 250,
        ])
        ->assertOk()
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.store.slug', 'wizard-shop');

    $store->refresh();
    expect($store->has_website)->toBeTrue();
    expect((int) DeliveryRoute::where('store_id', $store->id)->value('fee'))->toBe(25000);
});

test('the storefront wizard refuses a store that is already online', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner, ['has_website' => true]);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/storefront", [
            'store_name' => 'Another',
            'slug' => 'another',
        ])
        ->assertStatus(422);
});

test('a storefront requires a store name', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['store_name', 'slug']);
});

test('a slug that slugs to nothing is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Shop',
            'slug' => '!!!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('a reserved slug is refused when enabling', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Admin Shop',
            'slug' => 'admin',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('a slug already used by another store is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws05Store($owner, ['slug' => 'taken-slug']);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Taken Slug',
            'slug' => 'taken-slug',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('a negative nationwide fee is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Cheap Shop',
            'slug' => 'cheap-shop',
            'is_nationwide' => true,
            'nationwide_fee' => -1,
            'nationwide_days' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nationwide_fee', 'nationwide_days']);
});

test('a hand typed slug is normalised before it is stored', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Normalised Shop',
            'slug' => 'My Normalised Slug',
        ])
        ->assertOk()
        ->assertJsonPath('data.store.slug', 'my-normalised-slug');
});

test('a storefront cannot be enabled for another business store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws05Store($otherOwner);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$theirs->store_id}/enable-website", [
            'store_name' => 'Mine Now',
            'slug' => 'mine-now',
        ])
        ->assertStatus(403);

    expect($theirs->fresh()->has_website)->toBeFalse();
});

test('another business storefront detail is not reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws05Store($otherOwner);

    $this->withToken(ws05Token($owner))
        ->getJson("/api/v1/management/storefront/stores/{$theirs->store_id}")
        ->assertStatus(403);
});

test('a deleted store cannot be brought online', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner, ['status' => Store::STATUS_DELETED]);

    $this->withToken(ws05Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Zombie Shop',
            'slug' => 'zombie-shop',
        ])
        ->assertStatus(404);
});

test('managing a storefront needs the stores settings permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws05Store($owner);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $staff->assignRole('Managing Director');

    // Read access is enough for the overview…
    $this->withToken(ws05Token($staff))
        ->getJson('/api/v1/management/storefront/stores')
        ->assertOk();

    // …but enabling a storefront is a stores-settings action.
    $this->withToken(ws05Token($staff))
        ->postJson("/api/v1/management/stores/{$store->store_id}/enable-website", [
            'store_name' => 'Not Allowed',
            'slug' => 'not-allowed',
        ])
        ->assertStatus(403);
});

test('storefront endpoints require authentication', function () {
    $this->getJson('/api/v1/management/storefront/stores')->assertStatus(401);
    $this->postJson('/api/v1/management/stores/check-slug', ['name' => 'Nope'])->assertStatus(401);
});
