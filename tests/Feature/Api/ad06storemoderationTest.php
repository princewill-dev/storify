<?php

use App\Enums\TransactionStatus;
use App\Mail\AdminStoreCreated;
use App\Mail\StoreActivated;
use App\Mail\StoreReactivated;
use App\Mail\StoreSuspended;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WS-6 — store moderation & lifecycle (admin console).
 *
 * Covers the enriched directory (legacy scanning columns, working deleted
 * filter, store-id/owner search, created range), the detail console payload
 * (info card, business/owner block, computed tiles, panels), create with the
 * main-store bootstrap and both queued mails, edit round-trip with slug retry
 * and logo replacement, the restrictions the audit demanded (no `deleted` via
 * edit, no inactive/suspended homepage store), suspend/activate with the
 * mandatory reason, delete with legacy's three guards, the audit rows, and the
 * platform-admin boundary that keeps tenant accounts out.
 */
function ad06Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad06SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad06AdminWithRole(string $roleName): User
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

function ad06Store(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store '.Str::upper(Str::random(5)),
        'slug' => 'ad06-'.Str::lower(Str::random(10)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad06Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'AD06-ORD-'.$sequence.'-'.random_int(1000, 9999),
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'pending',
    ], $attributes));
}

function ad06Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'AD06-TXN-'.$sequence.'-'.random_int(1000, 9999),
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'status' => TransactionStatus::PENDING,
    ], $attributes));
}

function ad06SetMainStore(Store $store): void
{
    $settings = Setting::query()->first() ?? new Setting;
    $settings->main_store_id = $store->id;
    $settings->save();
}

test('the directory lists stores with the legacy scanning columns and the main badge', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $businessType = BusinessType::create(['name' => 'Retail']);
    $store = ad06Store($owner, $business, [
        'name' => 'Ikeja Flagship',
        'slug' => 'ikeja-flagship',
        'logo_path' => 'stores/logos/ikeja.png',
        'business_type_id' => $businessType->id,
        'has_website' => true,
    ]);
    ad06SetMainStore($store);

    $response = $this->withToken(ad06Token($admin))
        ->getJson('/api/v1/admin/stores')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Ikeja Flagship')
        ->assertJsonPath('data.0.is_main', true)
        ->assertJsonPath('data.0.owner.name', $owner->name)
        ->assertJsonPath('data.0.owner.email', $owner->email)
        ->assertJsonPath('data.0.business_code', $business->business_code)
        ->assertJsonPath('data.0.business_type', 'Retail')
        ->assertJsonPath('data.0.logo_path', 'stores/logos/ikeja.png')
        ->assertJsonPath('data.0.store_id', $store->store_id);

    // The "view shop" deep link legacy had and the new list dropped.
    expect($response->json('data.0.shop_url'))->toContain('ikeja-flagship');

    // Opening the directory is itself audited (WS-1 middleware).
    expect(ActivityLog::query()->where('action', 'admin_route_accessed')->exists())->toBeTrue();
});

test('the directory search matches store id, owner name and business name', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner(['name' => 'Ada Obi']);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['name' => 'Bola Eze']);

    $store = ad06Store($owner, $business, ['name' => 'Yaba Store']);
    $other = ad06Store($otherOwner, $otherBusiness, ['name' => 'Surulere Store']);

    $token = ad06Token($admin);
    $names = function (string $query) use ($token) {
        $response = $this->withToken($token)->getJson('/api/v1/admin/stores?q='.urlencode($query))->assertOk();

        return collect($response->json('data'))->pluck('name');
    };

    // Legacy's placeholder promised store ID and owner/business search; only
    // name worked in the previous endpoint.
    expect($names($store->store_id))->toContain('Yaba Store')->not->toContain('Surulere Store');
    expect($names('Ada Obi'))->toContain('Yaba Store')->not->toContain('Surulere Store');
    expect($names($business->name))->toContain('Yaba Store')->not->toContain('Surulere Store');
});

test('the deleted status filter returns deleted stores while the default hides them', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $live = ad06Store($owner, $business, ['name' => 'Live Store']);
    $removed = ad06Store($owner, $business, ['name' => 'Gone Store', 'status' => Store::STATUS_DELETED]);

    $token = ad06Token($admin);
    $names = function (string $query = '') use ($token) {
        $response = $this->withToken($token)->getJson('/api/v1/admin/stores'.$query)->assertOk();

        return collect($response->json('data'))->pluck('name');
    };

    // Legacy applied `status != deleted` before the filter, so "Deleted"
    // always returned nothing. Here it works.
    expect($names())->toContain('Live Store')->not->toContain('Gone Store');
    expect($names('?status=deleted'))->toContain('Gone Store')->not->toContain('Live Store');
    expect($names('?include_deleted=1'))->toContain('Live Store')->toContain('Gone Store');
});

test('the directory filters by created date range', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $old = ad06Store($owner, $business, ['name' => 'Old Store']);
    $old->forceFill(['created_at' => now()->subDays(10)])->save();

    ad06Store($owner, $business, ['name' => 'New Store']);

    $token = ad06Token($admin);

    $names = function (string $query) use ($token) {
        $response = $this->withToken($token)->getJson('/api/v1/admin/stores'.$query)->assertOk();

        return collect($response->json('data'))->pluck('name');
    };

    expect($names('?from='.now()->subDays(2)->toDateString()))
        ->toContain('New Store')
        ->not->toContain('Old Store');

    expect($names('?to='.now()->subDays(2)->toDateString()))
        ->toContain('Old Store')
        ->not->toContain('New Store');
});

test('store detail reports the info card, business owner, computed tiles and panels', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $store = ad06Store($owner, $business, [
        'name' => 'Detail Store',
        'description' => 'A store with everything filled in.',
        'support_email' => 'help@detail.test',
        'support_phone' => '08030000000',
        'address' => '12 Marina, Lagos',
        'instagram_url' => 'https://instagram.com/detail',
    ]);

    $category = Category::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Beverages',
        'slug' => 'beverages-'.Str::lower(Str::random(6)),
        'status' => 'active',
    ]);

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'category_id' => $category->id,
        'name' => 'Bottled Water',
        'quantity' => 12,
        'amount' => 500,
        'status' => 'active',
    ]);

    Pack::create([
        'store_id' => $store->id,
        'name' => 'Starter Pack',
        'amount' => 4500,
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ad06-'.Str::lower(Str::random(8)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => 'ACTIVE',
    ]);

    $completed = ad06Order($store, [
        'customer_id' => $customer->id,
        'status' => 'completed',
    ]);

    ad06Transaction($completed, [
        'amount' => 1500,
        'status' => TransactionStatus::CONFIRMED,
    ]);

    $response = $this->withToken(ad06Token($admin))
        ->getJson('/api/v1/admin/stores/'.$store->store_id)
        ->assertOk()
        ->assertJsonPath('data.store.description', 'A store with everything filled in.')
        ->assertJsonPath('data.store.support_email', 'help@detail.test')
        ->assertJsonPath('data.store.support_phone', '08030000000')
        ->assertJsonPath('data.store.address', '12 Marina, Lagos')
        ->assertJsonPath('data.store.socials.instagram', 'https://instagram.com/detail')
        ->assertJsonPath('data.store.business_block.business_code', $business->business_code)
        ->assertJsonPath('data.store.owner.email', $owner->email)
        ->assertJsonPath('data.store.categories_count', 1)
        ->assertJsonPath('data.store.categories.0.name', 'Beverages')
        ->assertJsonPath('data.store.recent_products.0.name', 'Bottled Water')
        ->assertJsonPath('data.store.packs_count', 1)
        ->assertJsonPath('data.store.packs.0.name', 'Starter Pack');

    // Legacy hard-coded these tiles to zero; they are real numbers now.
    expect((float) $response->json('data.store.stats.total_earned'))->toBe(1500.0)
        ->and($response->json('data.store.stats.customers_count'))->toBe(1)
        ->and($response->json('data.store.stats.products_count'))->toBe(1)
        ->and($response->json('data.store.stats.sales_count'))->toBe(1);
});

test('an admin can create a store, link its business and claim the homepage bootstrap', function () {
    Mail::fake();
    Storage::fake('public');

    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $response = $this->withToken(ad06Token($admin))
        ->post('/api/v1/admin/stores', [
            'business_id' => $business->id,
            'name' => 'Lekki Store',
            'slug' => 'Lekki Store',
            'description' => 'Opened by the platform team.',
            'support_email' => 'lekki@example.test',
            'status' => 'active',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])
        ->assertCreated()
        ->assertJsonPath('data.store.slug', 'lekki_store')
        ->assertJsonPath('data.store.business_id', $business->id)
        ->assertJsonPath('data.store.owner.id', $owner->id)
        ->assertJsonPath('data.store.is_main', true);

    $store = Store::query()->where('store_id', $response->json('data.store.store_id'))->firstOrFail();

    // Legacy unset business_id before saving; the link must survive here.
    expect($store->business_id)->toBe($business->id)
        ->and($store->user_id)->toBe($owner->id);

    Storage::disk('public')->assertExists($store->logo_path);

    // First superadmin-created store becomes the homepage store.
    expect((int) Setting::query()->value('main_store_id'))->toBe($store->id);

    Mail::assertQueued(AdminStoreCreated::class);
    Mail::assertQueued(StoreActivated::class);

    expect(ActivityLog::query()->where('action', 'store_created')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'main_store_configured')->exists())->toBeTrue();
});

test('store creation is refused when a main store exists and multi-business setup is disabled', function () {
    config(['app.allow_ms_setup' => false]);

    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    ad06SetMainStore(ad06Store($owner, $business, ['name' => 'Homepage Store']));

    $token = ad06Token($admin);

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores', [
            'business_id' => $business->id,
            'name' => 'Blocked Store',
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Multi-business controls are disabled.');

    // The SPA can see the block before the form is filled in.
    $this->withToken($token)
        ->getJson('/api/v1/admin/stores/form-options')
        ->assertOk()
        ->assertJsonPath('data.can_create', false);

    // With the flag on, the same request succeeds.
    config(['app.allow_ms_setup' => true]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores', [
            'business_id' => $business->id,
            'name' => 'Allowed Store',
            'status' => 'active',
        ])
        ->assertCreated();
});

test('store creation validates its fields and rejects lifecycle-only statuses', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $token = ad06Token($admin);

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores', ['business_id' => $business->id, 'status' => 'active'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores', ['name' => 'No Business', 'status' => 'active'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('business_id');

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores', [
            'business_id' => $business->id,
            'name' => 'Sneaky Store',
            'status' => 'deleted',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect(Store::query()->where('name', 'Sneaky Store')->exists())->toBeFalse();
});

test('a store edit round-trips its fields, retries the slug and replaces the logo', function () {
    Storage::fake('public');

    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    [$otherOwner, $otherBusiness] = createBusinessOwner();

    $oldLogo = UploadedFile::fake()->image('old.png')->store('stores/logos', 'public');

    $store = ad06Store($owner, $business, [
        'slug' => 'original_slug',
        'logo_path' => $oldLogo,
    ]);

    ad06Store($otherOwner, $otherBusiness, ['slug' => 'taken_slug']);

    $response = $this->withToken(ad06Token($admin))
        ->post('/api/v1/admin/stores/'.$store->store_id, [
            '_method' => 'PUT',
            'business_id' => $otherBusiness->id,
            'name' => 'Renamed Store',
            'slug' => 'taken_slug',
            'description' => 'Now under a new business.',
            'support_email' => 'renamed@example.test',
            'status' => 'inactive',
            'logo' => UploadedFile::fake()->image('new.png', 150, 150),
        ])
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Renamed Store')
        ->assertJsonPath('data.store.slug', 'taken_slug_2')
        ->assertJsonPath('data.store.business_id', $otherBusiness->id)
        ->assertJsonPath('data.store.status', 'inactive');

    $store->refresh();

    // Re-resolved owner, replaced logo, deleted old file.
    expect($store->user_id)->toBe($otherOwner->id)
        ->and($store->logo_path)->not->toBe($oldLogo);

    Storage::disk('public')->assertMissing($oldLogo);
    Storage::disk('public')->assertExists($store->logo_path);

    expect(ActivityLog::query()->where('action', 'store_updated')->exists())->toBeTrue();

    expect($response->json('data.warnings'))->toBe([]);
});

test('an edit cannot move a store to the deleted status', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad06Store($owner, $business);

    $this->withToken(ad06Token($admin))
        ->putJson('/api/v1/admin/stores/'.$store->store_id, ['status' => 'deleted'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($store->fresh()->status)->toBe('active');
});

test('the homepage store cannot be set inactive or suspended by an edit', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad06Store($owner, $business, ['name' => 'Homepage Store']);
    ad06SetMainStore($store);

    $response = $this->withToken(ad06Token($admin))
        ->putJson('/api/v1/admin/stores/'.$store->store_id, [
            'name' => 'Homepage Store Renamed',
            'status' => 'suspended',
        ])
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Homepage Store Renamed')
        ->assertJsonPath('data.store.status', 'active');

    // Other edits apply; the status change is skipped with a warning, like
    // legacy's flash — but structured.
    expect($response->json('data.warnings'))->toHaveCount(1);
    expect($store->fresh()->status)->toBe('active');
});

test('editing a store into a suspended state emails the owner', function () {
    Mail::fake();

    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad06Store($owner, $business);

    $this->withToken(ad06Token($admin))
        ->putJson('/api/v1/admin/stores/'.$store->store_id, ['status' => 'suspended'])
        ->assertOk();

    expect($store->fresh()->status)->toBe('suspended');
    Mail::assertQueued(StoreSuspended::class);
});

test('suspend and activate require a reason and email the owner', function () {
    Mail::fake();

    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad06Store($owner, $business);
    $token = ad06Token($admin);

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores/'.$store->store_id.'/suspend', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores/'.$store->store_id.'/suspend', ['reason' => 'Fraud review'])
        ->assertOk()
        ->assertJsonPath('data.store.status', 'suspended');

    expect($store->fresh()->status)->toBe('suspended');
    Mail::assertQueued(StoreSuspended::class);
    expect(ActivityLog::query()->where('action', 'store_suspended')->exists())->toBeTrue();

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores/'.$store->store_id.'/activate', ['reason' => 'Cleared by review'])
        ->assertOk()
        ->assertJsonPath('data.store.status', 'active');

    expect($store->fresh()->status)->toBe('active');
    Mail::assertQueued(StoreReactivated::class);
    expect(ActivityLog::query()->where('action', 'store_activated')->exists())->toBeTrue();
});

test('the homepage store cannot be suspended', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad06Store($owner, $business);
    ad06SetMainStore($store);

    $this->withToken(ad06Token($admin))
        ->postJson('/api/v1/admin/stores/'.$store->store_id.'/suspend', ['reason' => 'Trying anyway'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This is the main store and cannot be suspended.');

    expect($store->fresh()->status)->toBe('active');
    expect(ActivityLog::query()->where('action', 'store_suspend_blocked')->exists())->toBeTrue();
});

test('deleting a store is refused for the homepage store, incomplete orders and unconfirmed transactions', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $token = ad06Token($admin);

    $mainStore = ad06Store($owner, $business);
    ad06SetMainStore($mainStore);

    $this->withToken($token)
        ->deleteJson('/api/v1/admin/stores/'.$mainStore->store_id)
        ->assertStatus(422)
        ->assertJsonPath('message', 'This is the main store and cannot be deleted.');

    expect($mainStore->fresh()->status)->toBe('active');

    $storeWithOrder = ad06Store($owner, $business);
    ad06Order($storeWithOrder, ['status' => 'pending']);

    $this->withToken($token)
        ->deleteJson('/api/v1/admin/stores/'.$storeWithOrder->store_id)
        ->assertStatus(422)
        ->assertJsonPath('message', "Deletion rejected: {$storeWithOrder->name} has an incomplete order.");

    $storeWithTransaction = ad06Store($owner, $business);
    $completed = ad06Order($storeWithTransaction, ['status' => 'completed']);
    ad06Transaction($completed, ['status' => TransactionStatus::PENDING]);

    $this->withToken($token)
        ->deleteJson('/api/v1/admin/stores/'.$storeWithTransaction->store_id)
        ->assertStatus(422)
        ->assertJsonPath('message', "Deletion rejected: {$storeWithTransaction->name} has an incomplete transaction.");

    expect($storeWithOrder->fresh()->status)->toBe('active')
        ->and($storeWithTransaction->fresh()->status)->toBe('active');
});

test('a clean store is soft-deleted, audited and hidden from the default directory', function () {
    $admin = ad06SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad06Store($owner, $business, ['name' => 'Clean Store']);
    $token = ad06Token($admin);

    $this->withToken($token)
        ->deleteJson('/api/v1/admin/stores/'.$store->store_id)
        ->assertOk();

    expect($store->fresh()->status)->toBe('deleted');
    expect(ActivityLog::query()->where('action', 'store_deleted')->exists())->toBeTrue();

    $names = function (string $query = '') use ($token) {
        $response = $this->withToken($token)->getJson('/api/v1/admin/stores'.$query)->assertOk();

        return collect($response->json('data'))->pluck('name');
    };

    expect($names())->not->toContain('Clean Store');
    expect($names('?status=deleted'))->toContain('Clean Store');

    // A deleted store cannot be acted on again.
    $this->withToken($token)
        ->deleteJson('/api/v1/admin/stores/'.$store->store_id)
        ->assertStatus(422);

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores/'.$store->store_id.'/activate', ['reason' => 'oops'])
        ->assertStatus(422);
});

test('a platform admin without the stores permission is refused', function () {
    $admin = ad06AdminWithRole('Finance Admin');
    [$owner, $business] = createBusinessOwner();
    ad06Store($owner, $business);

    $this->withToken(ad06Token($admin))
        ->getJson('/api/v1/admin/stores')
        ->assertStatus(403);
});

test('a platform admin with the stores permission can moderate', function () {
    $admin = ad06AdminWithRole('Platform Admin');
    [$owner, $business] = createBusinessOwner();
    ad06Store($owner, $business);

    $this->withToken(ad06Token($admin))
        ->getJson('/api/v1/admin/stores')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a business-scoped account with a leaked admin token cannot moderate stores', function () {
    [$owner, $business] = createBusinessOwner();
    $store = ad06Store($owner, $business, ['name' => 'Target Store']);

    $token = ad06Token($owner);

    // The in-business "Super Admin" role carries the admin.* permission
    // strings, so permission middleware alone would let this through; the
    // platform-role guard is what refuses it — hence the message assertion.
    $guardMessage = 'This endpoint is restricted to platform administrators.';

    $this->withToken($token)
        ->getJson('/api/v1/admin/stores')
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)
        ->getJson('/api/v1/admin/stores/'.$store->store_id)
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)
        ->postJson('/api/v1/admin/stores/'.$store->store_id.'/suspend', ['reason' => 'Not allowed'])
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    $this->withToken($token)
        ->deleteJson('/api/v1/admin/stores/'.$store->store_id)
        ->assertStatus(403)
        ->assertJsonPath('message', $guardMessage);

    expect($store->fresh()->status)->toBe('active');
});
