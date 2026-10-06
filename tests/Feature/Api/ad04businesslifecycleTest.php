<?php

use App\Enums\TransactionStatus;
use App\Mail\AdminBusinessCreated;
use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\OwnershipType;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * WS-4 — business lifecycle & directory (admin console).
 *
 * Covers the directory filters the audit listed as missing (created range,
 * deleted handling, warehouses count, owner-phone search), create / edit /
 * delete with legacy's guards, suspend / activate with the owner cascade and
 * KYC auto-approval, owner verification, the platform-admin boundary, and the
 * two type CRUDs.
 */
function ad04Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad04SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad04AdminWithRole(string $roleName): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole($roleName);

    return $user;
}

function ad04Store(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Main Store',
        'slug' => 'ad04-store-'.random_int(100000, 999999),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad04Warehouse(User $owner, Business $business, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Main Warehouse',
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ad04KycApplication(User $owner, Business $business, array $attributes = []): KycApplication
{
    // forceFill, not create: business_id is a column the model's $fillable
    // predates, so fill() would silently drop it and the console's
    // business-scoped KYC panel would find nothing (the trap ad03/ad08 also
    // documented).
    $application = new KycApplication;

    $application->forceFill(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'status' => KycApplication::STATUS_SUBMITTED,
        'legal_name' => 'Acme Holdings Ltd',
        'submitted_at' => now(),
    ], $attributes))->save();

    return $application;
}

function ad04Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'AD04-ORD-'.$sequence.'-'.random_int(1000, 9999),
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'pending',
    ], $attributes));
}

function ad04Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'AD04-TXN-'.$sequence.'-'.random_int(1000, 9999),
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'status' => TransactionStatus::PENDING,
    ], $attributes));
}

test('the directory hides deleted businesses by default and can include them', function () {
    $admin = ad04SuperAdmin();
    [$ownerA, $live] = createBusinessOwner();
    [, $removed] = createBusinessOwner();
    $removed->update(['status' => 'deleted']);

    $token = ad04Token($admin);

    $codes = function (string $query = '') use ($token) {
        $response = $this->getJson('/api/v1/admin/businesses'.$query, ['Authorization' => 'Bearer '.$token]);
        $response->assertOk();

        return collect($response->json('data'))->pluck('business_code');
    };

    // Legacy default: deleted rows stay out of the directory.
    expect($codes())->toContain($live->business_code)->not->toContain($removed->business_code);

    // The status option the previous endpoint did not offer.
    expect($codes('?status=deleted'))->toContain($removed->business_code)->not->toContain($live->business_code);

    // Deleted-inclusive listing.
    expect($codes('?include_deleted=1'))
        ->toContain($live->business_code)
        ->toContain($removed->business_code);
});

test('the directory filters by created date range and searches the owner phone', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $owner->update(['phone' => '08031234567']);
    $business->forceFill(['created_at' => now()->subDays(10)])->save();

    $token = ad04Token($admin);

    $matches = function (string $query) use ($token, $business) {
        $response = $this->getJson('/api/v1/admin/businesses?'.$query, ['Authorization' => 'Bearer '.$token]);
        $response->assertOk();

        return in_array($business->business_code, collect($response->json('data'))->pluck('business_code')->all(), true);
    };

    // The legacy placeholder advertised phone but never searched it.
    expect($matches('q=08031234567'))->toBeTrue();

    // Owner name and business name still match.
    expect($matches('q='.urlencode($owner->name)))->toBeTrue();
    expect($matches('q='.urlencode($business->name)))->toBeTrue();

    // Ten days old: excluded by today's `from`, included by an early `to`.
    expect($matches('from='.now()->toDateString()))->toBeFalse();
    expect($matches('to='.now()->subDays(9)->toDateString()))->toBeTrue();
});

test('the directory payload carries owner contact, counts and the warehouses count', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $owner->update(['phone' => '08030000000']);

    ad04Warehouse($owner, $business);
    ad04Warehouse($owner, $business, ['name' => 'Closed depot', 'status' => Warehouse::STATUS_DELETED]);
    ad04Store($owner, $business, ['name' => 'Flagship']);
    ad04Store($owner, $business, ['name' => 'Gone', 'status' => Store::STATUS_DELETED]);

    $token = ad04Token($admin);

    $this->getJson('/api/v1/admin/businesses?q='.$business->business_code, ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.business_code', $business->business_code)
        ->assertJsonPath('data.0.owner.phone', '08030000000')
        ->assertJsonPath('data.0.owner.is_verified', true)
        // Deleted stores and warehouses are not part of what the business runs.
        ->assertJsonPath('data.0.stores_count', 1)
        ->assertJsonPath('data.0.warehouses_count', 1)
        ->assertJsonPath('data.0.users_count', 1);
});

test('directory filters are validated', function () {
    $token = ad04Token(ad04SuperAdmin());

    $this->getJson('/api/v1/admin/businesses?from=not-a-date', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    $this->getJson('/api/v1/admin/businesses?from='.now()->toDateString().'&to='.now()->subDay()->toDateString(), ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/admin/businesses?status=nonsense', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    // Never pass a request-supplied column to orderBy.
    $this->getJson('/api/v1/admin/businesses?sort=owner_id', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');

    $this->getJson('/api/v1/admin/businesses?per_page=500', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('only platform accounts can reach the business directory', function () {
    // A business-scoped owner: its in-business "Super Admin" role carries the
    // full permission bundle, admin.* names included, so the permission gate
    // alone would let it through — the controller's platform guard is what
    // stops a leaked admin-audience token from reading every tenant.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);
    expect($owner->can('admin.businesses'))->toBeTrue();

    $this->getJson('/api/v1/admin/businesses', [
        'Authorization' => 'Bearer '.$owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);

    // A management token cannot cross audiences.
    $this->getJson('/api/v1/admin/businesses', [
        'Authorization' => 'Bearer '.$owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);

    // An admin account without the permission is refused by the route gate.
    $permissionless = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $this->getJson('/api/v1/admin/businesses', ['Authorization' => 'Bearer '.ad04Token($permissionless)])
        ->assertStatus(403);

    // A Platform Admin holds admin.businesses and is allowed.
    $this->getJson('/api/v1/admin/businesses', ['Authorization' => 'Bearer '.ad04Token(ad04AdminWithRole('Platform Admin'))])
        ->assertOk();

    $this->getJson('/api/v1/admin/businesses')->assertStatus(401);
});

test('an admin can provision a business and its owner', function () {
    $admin = ad04SuperAdmin();
    config(['app.allow_ms_setup' => true]);
    Mail::fake();

    $response = $this->postJson('/api/v1/admin/businesses', [
        'name' => 'Acme Trading',
        'email' => 'acme-owner@example.test',
        'phone' => '08031112222',
    ], ['Authorization' => 'Bearer '.ad04Token($admin)]);

    $response->assertCreated()
        ->assertJsonPath('data.business.name', 'Acme Trading')
        ->assertJsonPath('data.business.status', 'active')
        ->assertJsonPath('data.business.slug', 'acme_trading')
        ->assertJsonPath('data.business.owner.email', 'acme-owner@example.test');

    // Legacy created the owner with no password at all (users.password is NOT
    // NULL); the admin gets a one-time temporary password instead.
    expect($response->json('data.temporary_password'))->toBeString()->not->toBeEmpty();

    $owner = User::where('email', 'acme-owner@example.test')->firstOrFail();

    expect($owner->role)->toBe(User::ROLE_BUSINESS_OWNER)
        ->and((bool) $owner->force_password_change)->toBeTrue()
        ->and((bool) $owner->is_verified)->toBeFalse()
        ->and($owner->status)->toBe('active')
        ->and($owner->business_id)->toBe($response->json('data.business.id'));

    expect($owner->business)->not->toBeNull()
        ->and($owner->business->business_code)->toBe($response->json('data.business.business_code'));

    Mail::assertQueued(AdminBusinessCreated::class);

    expect(ActivityLog::where('action', 'business_created')->exists())->toBeTrue();
});

test('business creation is refused while multi-business setup is disabled', function () {
    $admin = ad04SuperAdmin();
    config(['app.allow_ms_setup' => false]);

    $this->postJson('/api/v1/admin/businesses', [
        'name' => 'Blocked Trading',
        'email' => 'blocked@example.test',
    ], ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'ALLOW_MS_SETUP'));

    expect(User::where('email', 'blocked@example.test')->exists())->toBeFalse();
});

test('business creation is allowed before a superadmin account exists', function () {
    $admin = ad04AdminWithRole('Platform Admin');
    config(['app.allow_ms_setup' => false]);

    $this->postJson('/api/v1/admin/businesses', [
        'name' => 'First Trading',
        'email' => 'first@example.test',
    ], ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertCreated();
});

test('business creation validates the owner name and email', function () {
    $admin = ad04SuperAdmin();
    config(['app.allow_ms_setup' => true]);
    $token = ad04Token($admin);

    $this->postJson('/api/v1/admin/businesses', ['email' => 'no-name@example.test'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/admin/businesses', ['name' => 'No Email Ltd'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->postJson('/api/v1/admin/businesses', ['name' => 'Bad Email Ltd', 'email' => 'not-an-email'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    [$existing] = createBusinessOwner();

    $this->postJson('/api/v1/admin/businesses', ['name' => 'Duplicate Ltd', 'email' => $existing->email], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('an admin can update the business and its owner', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $originalName = $business->name;

    $this->putJson('/api/v1/admin/businesses/'.$business->business_code, [
        'name' => 'Renamed Ventures',
        'owner_name' => 'New Owner Name',
        'email' => 'renamed-owner@example.test',
        'phone' => '08035556666',
    ], ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertOk()
        ->assertJsonPath('data.business.name', 'Renamed Ventures')
        // Slug re-normalised from the name, as legacy did on every save.
        ->assertJsonPath('data.business.slug', 'renamed_ventures')
        ->assertJsonPath('data.business.owner.name', 'New Owner Name')
        ->assertJsonPath('data.business.owner.email', 'renamed-owner@example.test')
        ->assertJsonPath('data.business.owner.phone', '08035556666');

    $owner->refresh();
    expect($owner->email)->toBe('renamed-owner@example.test')
        ->and($owner->phone)->toBe('08035556666');

    $log = ActivityLog::where('action', 'business_updated')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['name'])->toBe($originalName)
        ->and($log->new_values['name'])->toBe('Renamed Ventures');
});

test('an edit cannot set the deleted status or cascade it by stealth', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    // Deletion has guards; the status select deliberately does not offer it.
    $this->putJson('/api/v1/admin/businesses/'.$business->business_code, ['status' => 'deleted'], ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($business->fresh()->status)->toBe('active')
        ->and($owner->fresh()->status)->toBe('active');

    // A supported status change cascades to the owner account.
    $this->putJson('/api/v1/admin/businesses/'.$business->business_code, ['status' => 'suspended'], ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertOk();

    expect($business->fresh()->status)->toBe('suspended')
        ->and($owner->fresh()->status)->toBe('suspended');
});

test('a deleted business cannot be edited or activated', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $business->update(['status' => 'deleted']);
    $token = ad04Token($admin);

    $this->putJson('/api/v1/admin/businesses/'.$business->business_code, ['name' => 'Nope'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422);

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/activate', ['reason' => 'Mistake'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422);

    expect($business->fresh()->name)->not->toBe('Nope')
        ->and($business->fresh()->status)->toBe('deleted');
});

test('suspending a business requires a reason, cascades to the owner and emails them', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    Mail::fake();
    $token = ad04Token($admin);

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/suspend', [], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/suspend', ['reason' => 'Policy breach'], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.business.status', 'suspended');

    // Business.status is canonical; the owner follows deliberately.
    expect($business->fresh()->status)->toBe('suspended')
        ->and($owner->fresh()->status)->toBe('suspended');

    Mail::assertQueued(BusinessSuspended::class);

    $log = ActivityLog::where('action', 'business_suspended')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['status'])->toBe('active')
        ->and($log->new_values['status'])->toBe('suspended')
        ->and($log->metadata['reason'])->toBe('Policy breach');
});

test('the main store business cannot be suspended or deleted', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad04Store($owner, $business);
    Setting::create(['main_store_id' => $store->id]);

    $token = ad04Token($admin);

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/suspend', ['reason' => 'Testing'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This business owns the main store and cannot be suspended.');

    $this->deleteJson('/api/v1/admin/businesses/'.$business->business_code, [], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This business owns the main store and cannot be deleted.');

    expect($business->fresh()->status)->toBe('active');
});

test('deleting a business is guarded by orders and transactions', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad04Store($owner, $business);
    $order = ad04Order($store, ['status' => 'pending']);

    $token = ad04Token($admin);

    // (b) an order that is not completed.
    $this->deleteJson('/api/v1/admin/businesses/'.$business->business_code, [], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'incomplete orders'));

    // (c) a transaction that is not confirmed.
    $order->update(['status' => 'completed']);
    $transaction = ad04Transaction($order, ['status' => TransactionStatus::PENDING]);

    $this->deleteJson('/api/v1/admin/businesses/'.$business->business_code, [], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'incomplete transactions'));

    // Both guards clear: the delete goes through as a soft delete.
    $transaction->update(['status' => TransactionStatus::CONFIRMED]);

    $this->deleteJson('/api/v1/admin/businesses/'.$business->business_code, [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    // The row survives; the owner is pulled in with it.
    expect($business->fresh())->not->toBeNull()
        ->and($business->fresh()->status)->toBe('deleted')
        ->and($owner->fresh()->status)->toBe('deleted');

    expect(ActivityLog::where('action', 'business_deleted')->exists())->toBeTrue();
});

test('activating a business cascades the owner and auto-approves open KYC', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $business->update(['status' => 'suspended']);
    $owner->update(['status' => 'suspended']);

    $application = ad04KycApplication($owner, $business);

    Mail::fake();

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/activate', ['reason' => 'KYC verified'], [
        'Authorization' => 'Bearer '.ad04Token($admin),
    ])
        ->assertOk()
        ->assertJsonPath('data.kyc_approved', true)
        ->assertJsonPath('message', 'Business activated and KYC approved.')
        ->assertJsonPath('data.business.status', 'active');

    expect($business->fresh()->status)->toBe('active')
        ->and($owner->fresh()->status)->toBe('active');

    $application->refresh();

    // Legacy persisted only status + reviewer here (reviewed_at was not a
    // column, reviewer_notes was not fillable, approved_at never set).
    expect($application->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($application->approved_at)->not->toBeNull()
        ->and($application->reviewed_by)->toBe($admin->id)
        ->and($application->review_notes)->toBe('Auto-approved during business activation: KYC verified');

    Mail::assertQueued(BusinessReactivated::class);

    expect(ActivityLog::where('action', 'business_activated')->exists())->toBeTrue();
});

test('activation without an open KYC application still activates the business', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $business->update(['status' => 'suspended']);
    Mail::fake();

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/activate', ['reason' => 'Paid up'], [
        'Authorization' => 'Bearer '.ad04Token($admin),
    ])
        ->assertOk()
        ->assertJsonPath('data.kyc_approved', false)
        ->assertJsonPath('message', 'Business activated.');

    expect($business->fresh()->status)->toBe('active')
        ->and($owner->fresh()->status)->toBe('active');
});

test('the owner can be marked email-verified from the business console', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $owner->forceFill(['is_verified' => false, 'email_verified_at' => null])->save();

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/verify-owner', [], [
        'Authorization' => 'Bearer '.ad04Token($admin),
    ])
        ->assertOk()
        ->assertJsonPath('data.business.owner.is_verified', true);

    expect((bool) $owner->fresh()->is_verified)->toBeTrue()
        ->and($owner->fresh()->email_verified_at)->not->toBeNull()
        ->and(ActivityLog::where('action', 'business_owner_verified')->exists())->toBeTrue();

    // The verify action carries legacy's users-domain gate as well.
    $financeAdmin = ad04AdminWithRole('Finance Admin');

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/verify-owner', [], [
        'Authorization' => 'Bearer '.ad04Token($financeAdmin),
    ])->assertStatus(403);
});

test('business types are listed alphabetically and can be created, renamed and deleted', function () {
    $admin = ad04SuperAdmin();
    Mail::fake();
    $token = ad04Token($admin);

    BusinessType::create(['name' => 'Zeta']);
    $alpha = BusinessType::create(['name' => 'Alpha']);

    $response = $this->getJson('/api/v1/admin/business-types', ['Authorization' => 'Bearer '.$token])->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Alpha', 'Zeta'])
        ->and($response->json('meta.total'))->toBe(2);

    $created = $this->postJson('/api/v1/admin/business-types', ['name' => 'Retail'], ['Authorization' => 'Bearer '.$token])
        ->assertCreated()
        ->assertJsonPath('data.business_type.name', 'Retail');

    $this->putJson('/api/v1/admin/business-types/'.$alpha->id, ['name' => 'Alpha Co'], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.business_type.name', 'Alpha Co');

    $this->deleteJson('/api/v1/admin/business-types/'.$created->json('data.business_type.id'), ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect(BusinessType::find($created->json('data.business_type.id')))->toBeNull()
        ->and(ActivityLog::where('action', 'business_type_created')->exists())->toBeTrue();
});

test('a business type that is still referenced cannot be deleted', function () {
    $admin = ad04SuperAdmin();
    $type = BusinessType::create(['name' => 'Retail']);
    [$owner, $business] = createBusinessOwner([], ['business_type_id' => $type->id]);
    $store = ad04Store($owner, $business);

    $this->deleteJson('/api/v1/admin/business-types/'.$type->id, [], ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertStatus(422);

    expect(BusinessType::find($type->id))->not->toBeNull();

    // A store reference blocks it too.
    $other = BusinessType::create(['name' => 'Wholesale']);
    $store->update(['business_type_id' => $other->id]);

    $this->deleteJson('/api/v1/admin/business-types/'.$other->id, [], ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertStatus(422);
});

test('business type names are unique', function () {
    $admin = ad04SuperAdmin();
    $token = ad04Token($admin);
    $type = BusinessType::create(['name' => 'Retail']);

    $this->postJson('/api/v1/admin/business-types', ['name' => 'Retail'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    // Renaming a type to its own name is not a collision.
    $this->putJson('/api/v1/admin/business-types/'.$type->id, ['name' => 'Retail'], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    BusinessType::create(['name' => 'Wholesale']);

    $this->putJson('/api/v1/admin/business-types/'.$type->id, ['name' => 'Wholesale'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('business types are gated behind the content permission', function () {
    $this->getJson('/api/v1/admin/business-types', ['Authorization' => 'Bearer '.ad04Token(ad04SuperAdmin())])
        ->assertOk();

    // Support Admin holds admin.content; Finance Admin does not.
    $this->getJson('/api/v1/admin/business-types', ['Authorization' => 'Bearer '.ad04Token(ad04AdminWithRole('Support Admin'))])
        ->assertOk();

    $this->getJson('/api/v1/admin/business-types', ['Authorization' => 'Bearer '.ad04Token(ad04AdminWithRole('Finance Admin'))])
        ->assertStatus(403);
});

test('ownership types mirror the business type CRUD', function () {
    $admin = ad04SuperAdmin();
    $token = ad04Token($admin);

    $sole = OwnershipType::create(['name' => 'Sole Proprietorship']);
    OwnershipType::create(['name' => 'Partnership']);

    $response = $this->getJson('/api/v1/admin/ownership-types', ['Authorization' => 'Bearer '.$token])->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['Partnership', 'Sole Proprietorship']);

    $created = $this->postJson('/api/v1/admin/ownership-types', ['name' => 'Limited Company'], ['Authorization' => 'Bearer '.$token])
        ->assertCreated()
        ->assertJsonPath('data.ownership_type.name', 'Limited Company');

    $this->putJson('/api/v1/admin/ownership-types/'.$sole->id, ['name' => 'Sole Trader'], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.ownership_type.name', 'Sole Trader');

    // Referenced by a business: refused rather than orphaned.
    [, $business] = createBusinessOwner([], ['ownership_type_id' => $created->json('data.ownership_type.id')]);

    $this->deleteJson('/api/v1/admin/ownership-types/'.$created->json('data.ownership_type.id'), ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422);

    expect(OwnershipType::find($created->json('data.ownership_type.id')))->not->toBeNull()
        ->and($business->fresh()->ownership_type_id)->toBe($created->json('data.ownership_type.id'));

    $this->deleteJson('/api/v1/admin/ownership-types/'.$sole->id, ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect(OwnershipType::find($sole->id))->toBeNull();
});

test('the business console answers who works here, what they run and whether they are verified', function () {
    $admin = ad04SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $owner->update(['phone' => '08037778888']);

    $ownership = OwnershipType::create(['name' => 'Partnership']);
    $type = BusinessType::create(['name' => 'Retail']);
    $business->update(['ownership_type_id' => $ownership->id, 'business_type_id' => $type->id]);

    $store = ad04Store($owner, $business, [
        'name' => 'Flagship',
        'ownership_type_id' => $ownership->id,
        'business_type_id' => $type->id,
    ]);
    ad04Warehouse($owner, $business);

    User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => 'Ada Staff',
    ]);

    ad04KycApplication($owner, $business);

    $response = $this->getJson('/api/v1/admin/businesses/'.$business->business_code, ['Authorization' => 'Bearer '.ad04Token($admin)])
        ->assertOk()
        ->assertJsonPath('data.business.owner.phone', '08037778888')
        ->assertJsonPath('data.business.ownership_type', 'Partnership')
        ->assertJsonPath('data.business.business_type', 'Retail')
        ->assertJsonPath('data.business.stores.0.name', 'Flagship')
        ->assertJsonPath('data.business.stores.0.ownership_type', 'Partnership')
        ->assertJsonPath('data.business.stores.0.business_type', 'Retail')
        ->assertJsonPath('data.business.warehouses.0.warehouse_code', fn ($code) => is_string($code) && $code !== '')
        ->assertJsonPath('data.business.warehouses.0.stock_items_count', 0)
        ->assertJsonPath('data.business.kyc.status', 'submitted')
        ->assertJsonPath('data.business.kyc.legal_name', 'Acme Holdings Ltd')
        ->assertJsonStructure([
            'data' => ['business' => [
                'prefix', 'slug', 'status', 'owner' => ['email_verified_at', 'last_login_at'],
                'subscription' => ['name', 'status', 'ends_at'],
                'team', 'stores', 'warehouses', 'kyc',
            ]],
        ]);

    expect(collect($response->json('data.business.team'))->pluck('name'))->toContain('Ada Staff');
});
