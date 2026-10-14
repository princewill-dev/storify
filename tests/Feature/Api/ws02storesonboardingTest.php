<?php

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function ws02Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws02Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'ws02-'.strtolower(str()->random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

test('the store list returns the onboarding payload and hides deleted and foreign stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws02Store($owner, [
        'name' => 'Mine',
        'slug' => 'ws02-mine',
        'description' => 'Sells widgets',
        'instagram_url' => 'https://instagram.com/mine',
    ]);
    ws02Store($owner, ['name' => 'Gone', 'slug' => 'ws02-gone', 'status' => Store::STATUS_DELETED]);
    ws02Store($otherOwner, ['name' => 'Theirs', 'slug' => 'ws02-theirs']);

    $response = $this->withToken(ws02Token($owner))->getJson('/api/v1/management/stores/onboarding/list');

    $response->assertOk();

    expect(array_column($response->json('data.stores'), 'name'))->toBe(['Mine']);

    $row = $response->json('data.stores.0');

    expect($row['description'])->toBe('Sells widgets')
        ->and($row['instagram_url'])->toBe('https://instagram.com/mine')
        ->and($row['categories_count'])->toBe(0)
        ->and($row['products_count'])->toBe(0)
        ->and($row['customers_count'])->toBe(0)
        ->and($row['storefront_url'])->toBeNull()
        ->and($response->json('meta.total'))->toBe(1);
});

test('the store list honours the legacy status, search and date filters', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $aged = ws02Store($owner, ['name' => 'Alpha Store', 'slug' => 'ws02-alpha']);
    ws02Store($owner, ['name' => 'Beta Store', 'slug' => 'ws02-beta', 'status' => Store::STATUS_SUSPENDED]);
    ws02Store($owner, ['name' => 'Gamma Store', 'slug' => 'ws02-gamma', 'status' => Store::STATUS_DELETED]);

    DB::table('stores')->where('id', $aged->id)->update(['created_at' => now()->subDays(60)]);

    $token = ws02Token($owner);

    $suspended = $this->withToken($token)->getJson('/api/v1/management/stores/onboarding/list?status=suspended');
    expect(array_column($suspended->json('data.stores'), 'name'))->toBe(['Beta Store']);

    $deleted = $this->withToken($token)->getJson('/api/v1/management/stores/onboarding/list?status=deleted');
    expect(array_column($deleted->json('data.stores'), 'name'))->toBe(['Gamma Store']);

    $search = $this->withToken($token)->getJson('/api/v1/management/stores/onboarding/list?q=Alpha');
    expect(array_column($search->json('data.stores'), 'name'))->toBe(['Alpha Store']);

    $from = $this->withToken($token)->getJson('/api/v1/management/stores/onboarding/list?from='.now()->subDays(30)->toDateString());
    expect(array_column($from->json('data.stores'), 'name'))->not->toContain('Alpha Store');

    $to = $this->withToken($token)->getJson('/api/v1/management/stores/onboarding/list?to='.now()->subDays(30)->toDateString());
    expect(array_column($to->json('data.stores'), 'name'))->toBe(['Alpha Store']);
});

test('the store list counts the distinct customers behind its orders', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws02Store($owner, ['name' => 'Counting Store', 'slug' => 'ws02-counting']);

    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'ada@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);

    $second = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Bola',
        'last_name' => 'Buyer',
        'email' => 'bola@example.test',
        'phone' => '08033334444',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);

    foreach ([$customer, $second, $customer] as $index => $buyer) {
        Order::create([
            'business_id' => $business->id,
            'store_id' => $store->id,
            'customer_id' => $buyer->id,
            'source' => 'checkout',
            'order_number' => 'ORD-WS02-'.$index,
            'subtotal' => 1000,
            'total' => 1000,
            'amount_paid' => 1000,
            'status' => 'completed',
        ]);
    }

    $response = $this->withToken(ws02Token($owner))->getJson('/api/v1/management/stores/onboarding/list');

    $response->assertOk()->assertJsonPath('data.stores.0.customers_count', 2)
        ->assertJsonPath('data.stores.0.orders_count', 3);
});

test('a store can be created with the store model, branding and several staff', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = collect(['Ada', 'Bola', 'Chidi'])->map(fn ($name) => User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => $name,
    ]));

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Acme Ltd',
    ]);

    $response = $this->withToken(ws02Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->post('/api/v1/management/stores', [
            'name' => 'Swift Essentials',
            'description' => 'Daily essentials delivered.',
            'has_website' => true,
            'is_physical' => true,
            'physical_address' => '12 Clifford Street, Lagos',
            'instagram_url' => 'https://instagram.com/swift',
            'bank_id' => $bank->id,
            'staff_ids' => $staff->pluck('id')->all(),
            'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.store.name', 'Swift Essentials')
        ->assertJsonPath('data.store.slug', 'swift-essentials')
        ->assertJsonPath('data.store.status', Store::STATUS_PENDING)
        ->assertJsonPath('data.store.store_type', 'both')
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.store.description', 'Daily essentials delivered.')
        ->assertJsonPath('data.store.instagram_url', 'https://instagram.com/swift');

    $store = Store::where('slug', 'swift-essentials')->firstOrFail();

    // The legacy staff picker kept only the last id it was handed.
    expect($store->assignedStaff()->count())->toBe(3)
        ->and($store->assignedBanks()->whereKey($bank->id)->exists())->toBeTrue()
        ->and($store->logo_path)->not->toBeNull()
        ->and($response->json('data.store.logo_url'))->not->toBeNull();

    Storage::disk('public')->assertExists($store->logo_path);
});

test('store creation validates the name and refuses reserved or taken slugs', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $token = ws02Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/stores', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->withToken($token)
        ->postJson('/api/v1/management/stores', ['name' => 'Reserved', 'slug' => 'admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');

    ws02Store($owner, ['name' => 'Taken', 'slug' => 'ws02-taken']);

    $this->withToken($token)
        ->postJson('/api/v1/management/stores', ['name' => 'Taken Again', 'slug' => 'ws02-taken'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('a generated slug colliding with an existing store is suffixed rather than randomised', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws02Store($owner, ['name' => 'Existing', 'slug' => 'swift-essentials']);

    $this->withToken(ws02Token($owner))
        ->postJson('/api/v1/management/stores', ['name' => 'Swift Essentials'])
        ->assertCreated()
        ->assertJsonPath('data.store.slug', 'swift-essentials-1');
});

test('the shared slug check reports availability, a suggestion and reserved words', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws02Store($owner, ['name' => 'Existing', 'slug' => 'swift-essentials']);

    $token = ws02Token($owner);

    $free = $this->withToken($token)
        ->postJson('/api/v1/management/stores/check-slug', ['name' => 'Bright Foods']);

    $free->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.slug', 'bright-foods')
        ->assertJsonPath('data.original', 'bright-foods');

    expect($free->json('data.url'))->toContain('bright-foods');

    $this->withToken($token)
        ->postJson('/api/v1/management/stores/check-slug', ['name' => 'Swift Essentials'])
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.slug', 'swift-essentials-1');

    // Reserved words read as unavailable — legacy reported "available" and
    // then refused the save, which left the form dead-ended.
    $this->withToken($token)
        ->postJson('/api/v1/management/stores/check-slug', ['name' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.slug', 'admin-1');
});

test('a store cannot be created with another business bank or staff', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $foreignBank = StoreBank::create([
        'business_id' => $otherBusiness->id,
        'bank_name' => 'Theirs',
        'bank_code' => '011',
        'account_number' => '0000000000',
        'account_name' => 'Theirs Ltd',
    ]);

    $foreignStaff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $otherBusiness->id,
    ]);

    $token = ws02Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/stores', ['name' => 'Nope Bank', 'bank_id' => $foreignBank->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('bank_id');

    $this->withToken($token)
        ->postJson('/api/v1/management/stores', ['name' => 'Nope Staff', 'staff_ids' => [$foreignStaff->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('staff_ids');

    expect(Store::where('name', 'like', 'Nope%')->exists())->toBeFalse();
});

test('store creation is closed to unverified owners and users without the permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $token = ws02Token($owner);

    $owner->update(['is_verified' => false]);

    $this->withToken($token)
        ->postJson('/api/v1/management/stores', ['name' => 'Unverified Store'])
        ->assertStatus(403);

    $owner->update(['is_verified' => true]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    // The sanctum request guard memoises the first user it resolves for the
    // whole test, so without dropping it the owner would still answer for the
    // staff token — and its stale verification flag would decide the 403.
    $this->app['auth']->forgetGuards();

    $this->withToken(ws02Token($staff))
        ->postJson('/api/v1/management/stores', ['name' => 'Staff Store'])
        ->assertStatus(403);

    // Dropping the header alone is not enough: the guard still holds the user
    // it resolved above, so the header-less request would come back 403, not 401.
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/management/stores', ['name' => 'Guest Store'])
        ->assertStatus(401);
});

test('the create form options carry the defaults, banks and staff — and no currency list', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => '08031112222',
    ]);

    Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);

    User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => 'Ada',
    ]);

    $response = $this->withToken(ws02Token($owner))
        ->getJson('/api/v1/management/stores/onboarding/options');

    $response->assertOk()
        ->assertJsonPath('data.defaults.support_email', $owner->email)
        ->assertJsonPath('data.defaults.support_phone', '08031112222');

    // The create form stopped asking for a currency: Storify is the merchant of
    // record and holds one wallet currency, so the server writes the platform
    // default and there is nothing left for the client to pick from. A payload
    // that still carried the list would be a query nothing consumes.
    expect(array_column($response->json('data.staff'), 'name'))->toContain('Ada')
        ->and($response->json())->not->toHaveKey('data.currencies')
        ->and($response->json('data.banks'))->toBe([]);
});

test('the create form options report the store model the signup answers imply', function () {
    [$owner, $business] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['onboarding_intents' => ['sell_online', 'sell_in_store']],
    );

    $response = $this->withToken(ws02Token($owner))
        ->getJson('/api/v1/management/stores/onboarding/options');

    $response->assertOk()
        // Both answers present: a storefront and a counter. The form uses this
        // to decide which address fields to ask for, and never shows it.
        ->assertJsonPath('data.defaults.store_model.has_website', true)
        ->assertJsonPath('data.defaults.store_model.is_physical', true)
        ->assertJsonPath('data.defaults.store_model.pos_enabled', true);

    // The raw answers are not sent alongside it. Nothing renders them, and the
    // client deriving the model for itself is exactly what this replaces.
    expect($response->json('data.defaults'))->not->toHaveKey('intents');

    // A business that predates the column reads as no answer at all, which
    // implies no model — the store the old form created when nobody ticked a
    // box.
    $business->update(['onboarding_intents' => null]);

    $this->withToken(ws02Token($owner))
        ->getJson('/api/v1/management/stores/onboarding/options')
        ->assertOk()
        ->assertJsonPath('data.defaults.store_model.has_website', false)
        ->assertJsonPath('data.defaults.store_model.is_physical', false)
        ->assertJsonPath('data.defaults.store_model.pos_enabled', false);
});

test('a store is created from the signup answers when the form does not state a model', function () {
    // "Sell in store" is the only answer that implies a counter, and the form
    // no longer offers the toggles that used to say so.
    [$owner] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['onboarding_intents' => ['sell_in_store']],
    );

    $created = $this->withToken(ws02Token($owner))
        ->postJson('/api/v1/management/stores', ['name' => 'Front Counter'])
        ->assertCreated();

    $store = Store::find($created->json('data.store.id'));

    expect($store->store_type)->toBe('physical')
        ->and($store->has_website)->toBeFalse()
        ->and($store->pos_enabled)->toBeTrue();

    // The other direction: selling online implies a storefront and no counter.
    [$onlineOwner] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['onboarding_intents' => ['sell_online']],
    );

    $online = Store::find(
        $this->withToken(ws02Token($onlineOwner))
            ->postJson('/api/v1/management/stores', ['name' => 'Web Only'])
            ->assertCreated()
            ->json('data.store.id'),
    );

    expect($online->store_type)->toBe('online')
        ->and($online->has_website)->toBeTrue()
        ->and($online->pos_enabled)->toBeFalse();
});

test('an explicit flag still wins over the signup answer', function () {
    // The inference fills silence, it does not overrule a caller that has
    // stated what it wants — which is what keeps a store created from any
    // other client behaving as that client asked.
    [$owner] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['onboarding_intents' => ['sell_in_store']],
    );

    $store = Store::find(
        $this->withToken(ws02Token($owner))
            ->postJson('/api/v1/management/stores', [
                'name' => 'Deliberate',
                'has_website' => true,
                'pos_enabled' => false,
            ])
            ->assertCreated()
            ->json('data.store.id'),
    );

    expect($store->store_type)->toBe('both')
        ->and($store->has_website)->toBeTrue()
        ->and($store->pos_enabled)->toBeFalse();
});

test('a store with no currency in the request takes the platform default', function () {
    $currency = Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::find(
        $this->withToken(ws02Token($owner))
            ->postJson('/api/v1/management/stores', ['name' => 'Currencyless'])
            ->assertCreated()
            ->json('data.store.id'),
    );

    // The create form no longer offers a picker, so silence has to mean the
    // platform's currency rather than a null the dashboards render as a
    // missing symbol.
    expect($store->currency_id)->toBe($currency->id);
});

test('the till can be switched on at creation, and silence leaves the column default alone', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$quietOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $created = $this->withToken(ws02Token($owner))
        ->postJson('/api/v1/management/stores', [
            'name' => 'Front Counter',
            'is_physical' => true,
            'pos_enabled' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.store.pos_enabled', true);

    expect(Store::find($created->json('data.store.id'))->pos_enabled)->toBeTrue();

    // Saying nothing about the till means the column's own default, untouched:
    // an SPA built before this flag existed keeps creating stores exactly as it
    // did, rather than having `false` written over the default on its behalf.
    $quiet = $this->withToken(ws02Token($quietOwner))
        ->postJson('/api/v1/management/stores', ['name' => 'Back Office'])
        ->assertCreated()
        ->assertJsonPath('data.store.pos_enabled', false);

    expect(Store::find($quiet->json('data.store.id'))->pos_enabled)->toBeFalse();
});

test('the finalize payload carries the live URL and the next step', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws02Store($owner, [
        'name' => 'Live Store',
        'slug' => 'ws02-live',
        'has_website' => true,
    ]);

    $response = $this->withToken(ws02Token($owner))
        ->getJson("/api/v1/management/stores/{$store->store_id}/finalize");

    $response->assertOk()
        ->assertJsonPath('data.store.store_id', $store->store_id)
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.subscription_active', false)
        ->assertJsonPath('data.next_step', 'settings');

    expect($response->json('data.storefront_url'))->toContain('ws02-live');

    // A store with no storefront has no URL to celebrate with.
    $offline = ws02Store($owner, ['name' => 'Offline', 'slug' => 'ws02-offline']);

    $this->withToken(ws02Token($owner))
        ->getJson("/api/v1/management/stores/{$offline->store_id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.storefront_url', null);
});

test('another business store is never reachable through the onboarding routes', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws02Store($otherOwner, ['name' => 'Theirs', 'slug' => 'ws02-theirs-final']);

    $this->withToken(ws02Token($owner))
        ->getJson("/api/v1/management/stores/{$theirs->store_id}/finalize")
        ->assertStatus(403);
});
