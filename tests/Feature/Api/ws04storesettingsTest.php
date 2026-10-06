<?php

use App\Mail\StoreReactivated;
use App\Mail\StoreSuspended;
use App\Models\DeliveryRoute;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\ServiceCharge;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function ws04Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws04Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'shop-'.fake()->unique()->numerify('#####'),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws04Staff(User $owner, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $owner->business_id,
    ], $attributes));
}

function ws04Url(Store $store, string $suffix = ''): string
{
    return '/api/v1/management/stores/'.$store->store_id.$suffix;
}

// --- Settings screen -------------------------------------------------------

test('the settings screen returns the full workspace payload', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner, [
        'name' => 'Ikeja Flagship',
        'description' => 'Our busiest store.',
        'support_email' => 'support@example.test',
        'instagram_url' => 'https://instagram.com/ikeja',
        'has_website' => false,
    ]);

    ServiceCharge::create(['store_id' => $store->id, 'name' => 'Packaging', 'amount' => 500, 'is_active' => true]);
    DeliveryRoute::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'country' => 'Nigeria',
        'state' => 'Lagos',
        'area' => 'Ikeja',
        'fee' => 150000,
        'delivery_days' => 2,
        'active' => true,
    ]);

    $assigned = ws04Staff($owner, ['name' => 'Assigned Ada']);
    $available = ws04Staff($owner, ['name' => 'Available Bola']);
    $store->assignedStaff()->attach($assigned->id);

    $response = $this->withToken(ws04Token($owner))
        ->getJson(ws04Url($store, '/settings'))
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Ikeja Flagship')
        ->assertJsonPath('data.store.description', 'Our busiest store.')
        ->assertJsonPath('data.store.support_email', 'support@example.test')
        ->assertJsonPath('data.service_charges.0.name', 'Packaging')
        ->assertJsonPath('data.service_charges.0.amount', 500.0)
        ->assertJsonPath('data.delivery_routes.0.fee', 150000)
        ->assertJsonPath('data.delivery_routes.0.delivery_days', 2)
        ->assertJsonPath('data.pos.enabled', false)
        ->assertJsonPath('data.storefront.enabled', false);

    $assignedNames = array_column($response->json('data.staff.assigned'), 'name');
    $availableNames = array_column($response->json('data.staff.available'), 'name');

    expect($assignedNames)->toBe(['Assigned Ada']);
    expect($availableNames)->toBe(['Available Bola']);
});

test('store details, logo and socials round-trip', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner, ['logo_path' => 'stores/logos/old.png']);
    Storage::disk('public')->put('stores/logos/old.png', 'old-bytes');

    $response = $this->withToken(ws04Token($owner))->post(ws04Url($store), [
        '_method' => 'PUT',
        'name' => 'Renamed Store',
        'description' => 'A new description',
        'support_email' => 'hello@example.test',
        'support_phone' => '+2348000000000',
        'address' => '12 Marina Road',
        'instagram_url' => 'https://instagram.com/renamed',
        'facebook_url' => 'https://facebook.com/renamed',
        'twitter_url' => 'https://x.com/renamed',
        'tiktok_url' => 'https://tiktok.com/@renamed',
        'logo' => UploadedFile::fake()->image('logo.png', 20, 20),
    ]);

    $response->assertOk()->assertJsonPath('data.store.name', 'Renamed Store');

    $fresh = $store->fresh();
    expect($fresh->support_email)->toBe('hello@example.test');
    expect($fresh->instagram_url)->toBe('https://instagram.com/renamed');
    expect($fresh->tiktok_url)->toBe('https://tiktok.com/@renamed');

    // The old file is only removed once the new row is safely persisted.
    Storage::disk('public')->assertMissing('stores/logos/old.png');
    expect($fresh->logo_path)->not->toBe('stores/logos/old.png');
    Storage::disk('public')->assertExists($fresh->logo_path);
});

test('saving one settings card does not clear the others', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner, [
        'description' => 'Keep me',
        'address' => '1 Marina Road',
        'support_email' => 'keep@example.test',
    ]);

    // The Socials card only submits socials; details must survive untouched.
    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store), ['instagram_url' => 'https://instagram.com/keep'])
        ->assertOk();

    $fresh = $store->fresh();
    expect($fresh->instagram_url)->toBe('https://instagram.com/keep');
    expect($fresh->description)->toBe('Keep me');
    expect($fresh->address)->toBe('1 Marina Road');
    expect($fresh->support_email)->toBe('keep@example.test');
});

test('a store update requires a name', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store), ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

// --- Slug rules ------------------------------------------------------------

test('the slug can move while the storefront is offline but reserved words are refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner, ['has_website' => false, 'slug' => 'old-address']);

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store), ['name' => 'Store', 'slug' => 'Lagos Flagship'])
        ->assertOk();

    expect($store->fresh()->slug)->toBe('lagos-flagship');

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store), ['name' => 'Store', 'slug' => 'admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');

    expect($store->fresh()->slug)->toBe('lagos-flagship');
});

test('a live storefront freezes its slug', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner, ['has_website' => true, 'slug' => 'live-store']);

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store), ['name' => 'Live Store', 'slug' => 'moved-store'])
        ->assertStatus(422);

    expect($store->fresh()->slug)->toBe('live-store');

    // Saving the details again with the current slug is allowed.
    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store), ['name' => 'Live Store', 'slug' => 'live-store'])
        ->assertOk();

    expect($store->fresh()->name)->toBe('Live Store');
});

test('a slug collision is de-duplicated with a suffix', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws04Store($owner, ['slug' => 'shop-one']);
    $store = ws04Store($owner, ['slug' => 'something-else']);

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store), ['name' => 'Store', 'slug' => 'shop-one'])
        ->assertOk();

    expect($store->fresh()->slug)->toBe('shop-one-1');
});

// --- Service charges -------------------------------------------------------

test('service charges can be created, updated, toggled and deleted', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    $created = $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/service-charges'), [
            'name' => 'Packaging',
            'amount' => 750.5,
            'description' => 'Box and tape',
        ])
        ->assertCreated()
        ->assertJsonPath('data.service_charge.name', 'Packaging')
        ->assertJsonPath('data.service_charge.is_active', true);

    $chargeId = $created->json('data.service_charge.id');

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store, "/service-charges/{$chargeId}"), [
            'name' => 'Packaging & Tape',
            'amount' => 900,
            'description' => 'Box, tape and bubble wrap',
        ])
        ->assertOk()
        ->assertJsonPath('data.service_charge.amount', 900.0);

    $this->withToken(ws04Token($owner))
        ->patchJson(ws04Url($store, "/service-charges/{$chargeId}/toggle"))
        ->assertOk()
        ->assertJsonPath('data.service_charge.is_active', false);

    $this->withToken(ws04Token($owner))
        ->patchJson(ws04Url($store, "/service-charges/{$chargeId}/toggle"))
        ->assertOk()
        ->assertJsonPath('data.service_charge.is_active', true);

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store, "/service-charges/{$chargeId}"))
        ->assertOk();

    expect(ServiceCharge::find($chargeId))->toBeNull();
});

test('a charge created in settings is visible to the POS read endpoint', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    $staff = ws04Staff($owner);
    $store->assignedStaff()->attach($staff->id);

    $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/service-charges'), ['name' => 'Service Fee', 'amount' => 250])
        ->assertCreated();

    $posToken = $staff->createToken('pos-access', ['pos'], now()->addHour())->plainTextToken;

    $this->withToken($posToken)
        ->getJson("/api/v1/pos/stores/{$store->store_id}/service-charges")
        ->assertOk()
        ->assertJsonPath('data.charges.0.name', 'Service Fee')
        ->assertJsonPath('data.charges.0.amount', 250.0);
});

test('service charge validation and store scoping', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);
    $other = ws04Store($owner, ['slug' => 'other-store']);

    $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/service-charges'), ['name' => '', 'amount' => -5])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'amount']);

    // A charge that belongs to a sibling store is not reachable through it.
    $foreign = ServiceCharge::create(['store_id' => $other->id, 'name' => 'Foreign', 'amount' => 100]);

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store, "/service-charges/{$foreign->id}"), ['name' => 'Hijack', 'amount' => 1])
        ->assertStatus(404);

    expect($foreign->fresh()->name)->toBe('Foreign');
});

// --- Staff assignment ------------------------------------------------------

test('staff can be assigned and removed from the store context', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);
    $staff = ws04Staff($owner, ['name' => 'Ada Cashier']);

    $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/staff'), ['user_id' => $staff->id])
        ->assertOk()
        ->assertJsonPath('data.staff.name', 'Ada Cashier');

    // Assigning twice is idempotent rather than duplicating the pivot.
    $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/staff'), ['user_id' => $staff->id])
        ->assertOk();

    expect($store->assignedStaff()->count())->toBe(1);

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store, '/staff/'.$staff->account_code))
        ->assertOk();

    expect($store->assignedStaff()->count())->toBe(0);
});

test('deactivated staff are neither offered nor assignable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);
    $removed = ws04Staff($owner, ['name' => 'Gone Grace', 'status' => 'deleted']);

    $response = $this->withToken(ws04Token($owner))
        ->getJson(ws04Url($store, '/settings'))
        ->assertOk();

    expect(array_column($response->json('data.staff.available'), 'name'))->not->toContain('Gone Grace');

    $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/staff'), ['user_id' => $removed->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');
});

test('staff from another business cannot be assigned or removed', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);
    $intruder = ws04Staff($otherOwner, ['name' => 'Intruder']);

    $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/staff'), ['user_id' => $intruder->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store, '/staff/'.$intruder->account_code))
        ->assertStatus(404);
});

// --- Lifecycle -------------------------------------------------------------

test('suspending a store records the reason and queues the owner mail', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    // The acting user is a staff member, but the mail must reach the owner.
    $staff = ws04Staff($owner);
    setPermissionsTeamId($owner->business_id);
    $staff->givePermissionTo('stores settings');
    $store->assignedStaff()->attach($staff->id);

    $this->withToken(ws04Token($staff))
        ->patchJson(ws04Url($store, '/suspend'), ['reason' => 'Repeat policy breaches'])
        ->assertOk();

    expect($store->fresh()->status)->toBe(Store::STATUS_SUSPENDED);

    Mail::assertQueued(StoreSuspended::class, function (StoreSuspended $mail) use ($owner) {
        return $mail->hasTo($owner->email) && $mail->reason === 'Repeat policy breaches';
    });
});

test('a store cannot be suspended twice', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner, ['status' => Store::STATUS_SUSPENDED]);

    $this->withToken(ws04Token($owner))
        ->patchJson(ws04Url($store, '/suspend'))
        ->assertStatus(422);
});

test('reactivation is gated on the owners approved KYC', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner, ['status' => Store::STATUS_SUSPENDED]);

    $this->withToken(ws04Token($owner))
        ->patchJson(ws04Url($store, '/activate'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Complete KYC verification before activating this store.');

    expect($store->fresh()->status)->toBe(Store::STATUS_SUSPENDED);

    KycApplication::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'status' => KycApplication::STATUS_APPROVED,
        'legal_name' => 'Ada Owner',
    ]);

    $this->withToken(ws04Token($owner))
        ->patchJson(ws04Url($store, '/activate'), ['reason' => 'KYC approved'])
        ->assertOk();

    expect($store->fresh()->status)->toBe(Store::STATUS_ACTIVE);

    Mail::assertQueued(StoreReactivated::class, fn (StoreReactivated $mail) => $mail->hasTo($owner->email));
});

test('deleting a store is refused while orders or transactions are incomplete', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'user_id' => $owner->id,
        'status' => 'pending',
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete: store has incomplete orders.');

    $order->update(['status' => 'completed']);

    $transaction = Transaction::create([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'amount' => 1000,
        'status' => 'pending',
    ]);

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete: store has incomplete transactions.');

    $transaction->update(['status' => 'confirmed']);

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store))
        ->assertOk();

    expect($store->fresh()->status)->toBe(Store::STATUS_DELETED);
});

// --- Delivery routes -------------------------------------------------------

test('delivery routes are stored in kobo and scoped to their store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    $created = $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/delivery-routes'), [
            'country' => 'Nigeria',
            'state' => 'Lagos',
            'area' => 'Ikeja',
            'fee' => 150000,
            'delivery_days' => 3,
            'active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.delivery_route.fee', 150000);

    $routeId = $created->json('data.delivery_route.id');

    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store, "/delivery-routes/{$routeId}"), [
            'country' => 'Nigeria',
            'state' => 'Lagos',
            'area' => 'Ikeja',
            'fee' => 200000,
            'delivery_days' => 5,
            'active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.delivery_route.fee', 200000)
        ->assertJsonPath('data.delivery_route.active', false);

    expect(DeliveryRoute::find($routeId)->delivery_days)->toBe(5);

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store, "/delivery-routes/{$routeId}"))
        ->assertOk();

    expect(DeliveryRoute::find($routeId))->toBeNull();
});

test('delivery route validation rejects fractional kobo and zero days', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/delivery-routes'), [
            'fee' => 1500.75,
            'delivery_days' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['country', 'state', 'fee', 'delivery_days']);
});

test('a delivery route without an area saves instead of hitting the NOT NULL column', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);

    // delivery_routes.area is NOT NULL with no default under MySQL strict
    // mode, so an omitted area must persist as '' rather than a null insert.
    $created = $this->withToken(ws04Token($owner))
        ->postJson(ws04Url($store, '/delivery-routes'), [
            'country' => 'Nigeria',
            'state' => 'Lagos',
            'fee' => 100000,
            'delivery_days' => 2,
            'active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.delivery_route.area', '');

    $routeId = $created->json('data.delivery_route.id');

    expect(DeliveryRoute::find($routeId)->area)->toBe('');

    // Clearing the area on edit keeps the route editable too.
    $this->withToken(ws04Token($owner))
        ->putJson(ws04Url($store, "/delivery-routes/{$routeId}"), [
            'country' => 'Nigeria',
            'state' => 'Lagos',
            'area' => null,
            'fee' => 120000,
            'delivery_days' => 2,
            'active' => true,
        ])
        ->assertOk();

    expect(DeliveryRoute::find($routeId)->area)->toBe('');
});

test('a delivery route from a sibling store is not reachable', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws04Store($owner);
    $other = ws04Store($owner, ['slug' => 'other-store']);

    $foreign = DeliveryRoute::create([
        'store_id' => $other->id,
        'business_id' => $business->id,
        'country' => 'Nigeria',
        'state' => 'Abuja',
        'fee' => 100000,
        'delivery_days' => 2,
        'active' => true,
    ]);

    $this->withToken(ws04Token($owner))
        ->deleteJson(ws04Url($store, "/delivery-routes/{$foreign->id}"))
        ->assertStatus(404);

    expect(DeliveryRoute::find($foreign->id))->not->toBeNull();
});

// --- Tenant isolation ------------------------------------------------------

test('every settings route refuses another business store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $theirs = ws04Store($otherOwner, ['name' => 'Theirs']);
    $token = ws04Token($owner);

    $this->withToken($token)->getJson(ws04Url($theirs, '/settings'))->assertStatus(403);
    $this->withToken($token)->putJson(ws04Url($theirs), ['name' => 'Hijacked'])->assertStatus(403);
    $this->withToken($token)->patchJson(ws04Url($theirs, '/suspend'))->assertStatus(403);
    $this->withToken($token)->patchJson(ws04Url($theirs, '/activate'))->assertStatus(403);
    $this->withToken($token)->deleteJson(ws04Url($theirs))->assertStatus(403);
    $this->withToken($token)->postJson(ws04Url($theirs, '/service-charges'), ['name' => 'X', 'amount' => 1])->assertStatus(403);
    $this->withToken($token)->postJson(ws04Url($theirs, '/staff'), ['user_id' => $otherOwner->id])->assertStatus(403);
    $this->withToken($token)->postJson(ws04Url($theirs, '/delivery-routes'), [
        'country' => 'Nigeria', 'state' => 'Lagos', 'fee' => 100, 'delivery_days' => 1,
    ])->assertStatus(403);

    expect($theirs->fresh()->name)->toBe('Theirs');
    expect($theirs->fresh()->status)->toBe(Store::STATUS_ACTIVE);
});
