<?php

use App\Models\Currency;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

function ws30Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws30Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws30Service(Store $store, array $attributes = []): Service
{
    return Service::create(array_merge([
        'store_id' => $store->id,
        'name' => 'Consultation',
        'amount' => 5000,
        'status' => 'active',
    ], $attributes));
}

function ws30Currency(array $attributes = []): Currency
{
    return Currency::create(array_merge([
        'name' => 'Naira',
        'code' => 'NGN',
        'symbol' => '₦',
        'is_default' => true,
    ], $attributes));
}

/** A staff member holding exactly the given permissions in the owner's team. */
function ws30StaffWith(User $owner, array $permissions, array $storeIds = []): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $owner->business_id,
        'name' => 'Associate '.random_int(100, 999),
    ]);

    setPermissionsTeamId($owner->business_id);

    $role = Role::firstOrCreate([
        'name' => 'WS30 Role '.substr(md5(implode(',', $permissions)), 0, 8),
        'business_id' => $owner->business_id,
        'guard_name' => 'web',
    ]);
    $role->syncPermissions($permissions);

    $staff->assignRole($role);

    foreach ($storeIds as $storeId) {
        $staff->assignedStores()->attach($storeId);
    }

    return $staff;
}

test('the service list returns rows with store, currency and thumbnail', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $currency = ws30Currency();

    $service = ws30Service($store, ['name' => 'Logo Design', 'amount' => 15000, 'currency_id' => $currency->id]);

    $response = $this->withToken(ws30Token($owner))
        ->getJson('/api/v1/management/services')
        ->assertOk()
        ->assertJsonPath('data.services.0.name', 'Logo Design')
        ->assertJsonPath('data.services.0.service_code', $service->service_code)
        ->assertJsonPath('data.services.0.amount', 15000.0)
        ->assertJsonPath('data.services.0.currency.code', 'NGN')
        ->assertJsonPath('data.services.0.currency.symbol', '₦')
        ->assertJsonPath('data.services.0.store.id', $store->id)
        ->assertJsonPath('data.services.0.status', 'active')
        // The filter's option list ships with the page so the SPA needs no
        // second request (and the "no stores" state can be detected).
        ->assertJsonPath('data.stores.0.id', $store->id)
        ->assertJsonPath('meta.total', 1);

    expect($response->json('data.services.0.images_count'))->toBe(0);
});

test('the service list filters by search, status and store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $lekki = ws30Store($owner, ['name' => 'Lekki']);
    $ikeja = ws30Store($owner, ['name' => 'Ikeja']);

    ws30Service($lekki, ['name' => 'Logo Design', 'status' => 'active', 'service_code' => 'svc_LOGO1234']);
    ws30Service($lekki, ['name' => 'SEO Audit', 'status' => 'inactive']);
    ws30Service($ikeja, ['name' => 'Consultation', 'status' => 'active']);

    $names = fn ($response) => collect($response->json('data.services'))->pluck('name')->all();

    $response = $this->withToken(ws30Token($owner))->getJson('/api/v1/management/services');
    expect($response->json('meta.total'))->toBe(3);

    $response = $this->withToken(ws30Token($owner))->getJson('/api/v1/management/services?q=logo');
    expect($names($response))->toBe(['Logo Design']);

    // Search also covers the code, as legacy's controller did.
    $response = $this->withToken(ws30Token($owner))->getJson('/api/v1/management/services?q=LOGO1234');
    expect($names($response))->toBe(['Logo Design']);

    $response = $this->withToken(ws30Token($owner))->getJson('/api/v1/management/services?status=inactive');
    expect($names($response))->toBe(['SEO Audit']);

    $response = $this->withToken(ws30Token($owner))->getJson('/api/v1/management/services?store_id='.$ikeja->id);
    expect($names($response))->toBe(['Consultation']);
});

test('the service list excludes other businesses and deleted stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $live = ws30Store($owner, ['name' => 'Lekki']);
    $deleted = ws30Store($owner, ['name' => 'Ikeja', 'status' => Store::STATUS_DELETED]);
    $theirs = ws30Store($otherOwner, ['name' => 'Theirs']);

    ws30Service($live, ['name' => 'Mine']);
    ws30Service($deleted, ['name' => 'Gone']);
    ws30Service($theirs, ['name' => 'Theirs']);

    $response = $this->withToken(ws30Token($owner))
        ->getJson('/api/v1/management/services')
        ->assertOk();

    expect(collect($response->json('data.services'))->pluck('name')->all())->toBe(['Mine'])
        ->and(collect($response->json('data.stores'))->pluck('name')->all())->toBe(['Lekki']);
});

test('an owner with no stores gets an empty page instead of legacy\'s 500', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws30Token($owner))
        ->getJson('/api/v1/management/services')
        ->assertOk()
        ->assertJsonPath('data.services', [])
        ->assertJsonPath('data.stores', [])
        ->assertJsonPath('meta.total', 0);
});

test('a service can be created with images and a chosen primary', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $currency = ws30Currency();

    $response = $this->withToken(ws30Token($owner))
        ->postJson('/api/v1/management/services', [
            'store_id' => $store->id,
            'name' => 'Logo Design',
            'description' => 'Brand identity package',
            'amount' => 15000,
            'currency_id' => $currency->id,
            'images' => [
                UploadedFile::fake()->image('front.jpg', 40, 40),
                UploadedFile::fake()->image('back.png', 40, 40),
            ],
            // The legacy form never sent a primary flag; the rebuild lets the
            // caller pick and the test proves the second upload wins.
            'primary_image_index' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.service.name', 'Logo Design')
        ->assertJsonPath('data.service.status', 'active')
        ->assertJsonPath('data.service.store.id', $store->id)
        ->assertJsonPath('data.service.currency.code', 'NGN')
        ->assertJsonPath('data.service.images_count', 2)
        ->assertJsonPath('data.service.images.0.is_primary', false)
        ->assertJsonPath('data.service.images.1.is_primary', true);

    $service = Service::firstOrFail();

    expect($service->business_id)->toBe($owner->business_id)
        ->and($service->slug)->not->toBeEmpty();

    foreach ($service->images as $image) {
        expect(Storage::disk('public')->exists($image->path))->toBeTrue();
    }

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'service_created',
        'subject_type' => Service::class,
        'subject_id' => $service->id,
    ]);

    // The acceptance criterion: a created service appears on the public
    // storefront.
    $this->getJson('/api/v1/storefront/'.$store->slug.'/services')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Logo Design');
});

test('a service without an explicit currency takes the default', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    ws30Currency();

    $this->withToken(ws30Token($owner))
        ->postJson('/api/v1/management/services', [
            'store_id' => $store->id,
            'name' => 'Haircut',
            'amount' => 3000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.service.currency.code', 'NGN');
});

test('service creation validates its payload', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $foreign = ws30Store($otherOwner, ['name' => 'Theirs']);

    $this->withToken(ws30Token($owner))
        ->postJson('/api/v1/management/services', ['store_id' => $store->id, 'amount' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->withToken(ws30Token($owner))
        ->postJson('/api/v1/management/services', ['store_id' => $store->id, 'name' => 'Bad', 'amount' => -5])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');

    $this->withToken(ws30Token($owner))
        ->postJson('/api/v1/management/services', ['store_id' => $foreign->id, 'name' => 'Bad', 'amount' => 100])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid store selection.');

    $this->withToken(ws30Token($owner))
        ->postJson('/api/v1/management/services', [
            'store_id' => $store->id,
            'name' => 'Bad',
            'amount' => 100,
            'images' => [UploadedFile::fake()->create('notes.txt', 4, 'text/plain')],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('images.0');
});

test('a service from another business is not reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws30Service(ws30Store($otherOwner, ['name' => 'Theirs']), ['name' => 'Theirs']);

    $this->withToken(ws30Token($owner))
        ->getJson('/api/v1/management/services/'.$theirs->service_code)
        ->assertForbidden();

    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$theirs->service_code, [
            'store_id' => $theirs->store_id,
            'name' => 'Hijack',
            'amount' => 1,
            'status' => 'active',
        ])
        ->assertForbidden();

    $this->withToken(ws30Token($owner))
        ->deleteJson('/api/v1/management/services/'.$theirs->service_code)
        ->assertForbidden();

    expect(Service::find($theirs->id))->not->toBeNull();
});

test('a restricted staff member only reaches services in assigned stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $assigned = ws30Store($owner, ['name' => 'Lekki']);
    $other = ws30Store($owner, ['name' => 'Ikeja']);

    ws30Service($assigned, ['name' => 'Mine']);
    $hidden = ws30Service($other, ['name' => 'Hidden']);

    $staff = ws30StaffWith($owner, ['products view'], [$assigned->id]);

    $response = $this->withToken(ws30Token($staff))
        ->getJson('/api/v1/management/services')
        ->assertOk();

    expect(collect($response->json('data.services'))->pluck('name')->all())->toBe(['Mine']);

    $this->withToken(ws30Token($staff))
        ->getJson('/api/v1/management/services/'.$hidden->service_code)
        ->assertForbidden();
});

test('a service can be updated, including its store and status', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $lekki = ws30Store($owner, ['name' => 'Lekki']);
    $ikeja = ws30Store($owner, ['name' => 'Ikeja']);
    $currency = ws30Currency();
    $service = ws30Service($lekki, ['name' => 'Logo Design', 'amount' => 1000]);
    $slug = $service->slug;

    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$service->service_code, [
            'store_id' => $ikeja->id,
            'name' => 'Logo & Identity',
            'description' => 'Now with a brand book.',
            'amount' => 25000,
            'currency_id' => $currency->id,
            'status' => 'inactive',
        ])
        ->assertOk()
        ->assertJsonPath('data.service.name', 'Logo & Identity')
        ->assertJsonPath('data.service.store.id', $ikeja->id)
        ->assertJsonPath('data.service.status', 'inactive')
        ->assertJsonPath('data.service.amount', 25000.0);

    $service->refresh();

    expect($service->store_id)->toBe($ikeja->id)
        // The slug is deliberately stable across renames: the storefront
        // detail route resolves by slug *or* service_code, and rewrites would
        // silently break links the public may already hold.
        ->and($service->slug)->toBe($slug);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'service_updated',
        'subject_id' => $service->id,
    ]);

    // Inactive services disappear from the public storefront.
    $this->getJson('/api/v1/storefront/'.$ikeja->slug.'/services')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('service update validates the store and the status', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $foreign = ws30Store($otherOwner, ['name' => 'Theirs']);
    $service = ws30Service($store, ['name' => 'Logo Design']);

    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$service->service_code, [
            'store_id' => $foreign->id,
            'name' => 'Logo Design',
            'amount' => 1000,
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid store selection.');

    // Legacy persisted any status string; the rebuild refuses it.
    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$service->service_code, [
            'store_id' => $store->id,
            'name' => 'Logo Design',
            'amount' => 1000,
            'status' => 'deleted',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($service->fresh()->store_id)->toBe($store->id);
});

test('update manages the image gallery', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $service = ws30Service($store, ['name' => 'Photo Shoot']);

    Storage::disk('public')->put('services/images/old.jpg', 'old');
    $old = ServiceImage::create([
        'service_id' => $service->id,
        'path' => 'services/images/old.jpg',
        'is_primary' => true,
        'position' => 0,
    ]);

    Storage::disk('public')->put('services/images/keep.jpg', 'keep');
    $keep = ServiceImage::create([
        'service_id' => $service->id,
        'path' => 'services/images/keep.jpg',
        'is_primary' => false,
        'position' => 1,
    ]);

    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$service->service_code, [
            'store_id' => $store->id,
            'name' => 'Photo Shoot',
            'amount' => 20000,
            'status' => 'active',
            'delete_image_ids' => [$old->id],
            'primary_image_id' => $keep->id,
            'images' => [UploadedFile::fake()->image('new.jpg', 30, 30)],
        ])
        ->assertOk()
        ->assertJsonPath('data.service.images_count', 2);

    // The removed row and its file are gone.
    $this->assertDatabaseMissing('service_images', ['id' => $old->id]);
    Storage::disk('public')->assertMissing('services/images/old.jpg');

    $keep->refresh();
    expect($keep->is_primary)->toBeTrue();

    $newest = ServiceImage::where('id', '!=', $keep->id)->firstOrFail();
    expect($newest->is_primary)->toBeFalse()
        ->and($newest->position)->toBe(2);
});

test('a newly uploaded image can be elected as the cover on update', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $service = ws30Service($store, ['name' => 'Photo Shoot']);

    Storage::disk('public')->put('services/images/old.jpg', 'old');
    $old = ServiceImage::create([
        'service_id' => $service->id,
        'path' => 'services/images/old.jpg',
        'is_primary' => true,
        'position' => 0,
    ]);

    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$service->service_code, [
            'store_id' => $store->id,
            'name' => 'Photo Shoot',
            'amount' => 20000,
            'status' => 'active',
            'images' => [
                UploadedFile::fake()->image('one.jpg', 30, 30),
                UploadedFile::fake()->image('two.jpg', 30, 30),
            ],
            'primary_image_index' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('data.service.images_count', 3)
        ->assertJsonPath('data.service.images.2.is_primary', true)
        ->assertJsonPath('data.service.images.0.is_primary', false);

    expect($old->fresh()->is_primary)->toBeFalse();
});

test('the primary image must belong to the service being edited', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $first = ws30Service($store, ['name' => 'One']);
    $second = ws30Service($store, ['name' => 'Two']);

    $foreignImage = ServiceImage::create([
        'service_id' => $second->id,
        'path' => 'services/images/second.jpg',
        'is_primary' => true,
        'position' => 0,
    ]);

    // Legacy validated only `exists:service_images,id`, so this id passed and
    // the scoped update silently did nothing.
    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$first->service_code, [
            'store_id' => $store->id,
            'name' => 'One',
            'amount' => 1000,
            'status' => 'active',
            'primary_image_id' => $foreignImage->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The selected primary image does not belong to this service.');
});

test('deleting the primary image promotes the next upload', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $service = ws30Service($store, ['name' => 'Photo Shoot']);

    Storage::disk('public')->put('services/images/a.jpg', 'a');
    $a = ServiceImage::create([
        'service_id' => $service->id,
        'path' => 'services/images/a.jpg',
        'is_primary' => true,
        'position' => 0,
    ]);

    Storage::disk('public')->put('services/images/b.jpg', 'b');
    $b = ServiceImage::create([
        'service_id' => $service->id,
        'path' => 'services/images/b.jpg',
        'is_primary' => false,
        'position' => 1,
    ]);

    $this->withToken(ws30Token($owner))
        ->putJson('/api/v1/management/services/'.$service->service_code, [
            'store_id' => $store->id,
            'name' => 'Photo Shoot',
            'amount' => 20000,
            'status' => 'active',
            'delete_image_ids' => [$a->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.service.images.0.is_primary', true)
        ->assertJsonPath('data.service.images.0.id', $b->id);
});

test('deleting a service removes its image files and logs it', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $service = ws30Service($store, ['name' => 'Photo Shoot']);

    Storage::disk('public')->put('services/images/gone.jpg', 'gone');
    $image = ServiceImage::create([
        'service_id' => $service->id,
        'path' => 'services/images/gone.jpg',
        'is_primary' => true,
        'position' => 0,
    ]);

    $this->withToken(ws30Token($owner))
        ->deleteJson('/api/v1/management/services/'.$service->service_code)
        ->assertOk();

    $this->assertDatabaseMissing('services', ['id' => $service->id]);
    $this->assertDatabaseMissing('service_images', ['id' => $image->id]);
    Storage::disk('public')->assertMissing('services/images/gone.jpg');

    // Legacy deleted without an audit entry; the rebuild records it.
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'service_deleted',
        'subject_id' => $service->id,
    ]);
});

test('the currencies meta endpoint returns the default currency', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws30Currency();
    ws30Currency(['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'is_default' => false]);

    $this->withToken(ws30Token($owner))
        ->getJson('/api/v1/management/meta/currencies')
        ->assertOk()
        ->assertJsonCount(2, 'data.currencies')
        ->assertJsonPath('data.default_currency_code', 'NGN')
        ->assertJsonPath('data.currencies.0.code', 'NGN')
        ->assertJsonPath('data.currencies.0.is_default', true);

    expect($this->withToken(ws30Token($owner))->getJson('/api/v1/management/meta/currencies')->json('data.default_currency_id'))
        ->toBe(Currency::where('code', 'NGN')->value('id'));
});

test('the service routes require the product permissions', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws30Store($owner);
    $service = ws30Service($store, ['name' => 'Consultation']);

    $staff = ws30StaffWith($owner, ['warehouses view'], [$store->id]);

    $this->withToken(ws30Token($staff))
        ->getJson('/api/v1/management/services')
        ->assertForbidden();

    $this->withToken(ws30Token($staff))
        ->getJson('/api/v1/management/meta/currencies')
        ->assertForbidden();

    $this->withToken(ws30Token($staff))
        ->postJson('/api/v1/management/services', ['store_id' => $store->id, 'name' => 'Nope', 'amount' => 100])
        ->assertForbidden();

    $this->withToken(ws30Token($staff))
        ->putJson('/api/v1/management/services/'.$service->service_code, [
            'store_id' => $store->id,
            'name' => 'Nope',
            'amount' => 100,
            'status' => 'active',
        ])
        ->assertForbidden();

    $this->withToken(ws30Token($staff))
        ->deleteJson('/api/v1/management/services/'.$service->service_code)
        ->assertForbidden();

    expect($service->fresh())->not->toBeNull();
});
