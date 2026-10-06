<?php

use App\Models\Customer;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-34 — Global search, shell badges & avatar
|--------------------------------------------------------------------------
| The palette (six legacy groups + Orders), the one shell/counts endpoint
| every badge, pill and avatar reads, and the profile photo resource.
*/

function ws34Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws34Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS34 Store',
        'slug' => 'ws34-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws34Warehouse(User $owner, $business, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS34 Depot',
        'status' => Warehouse::STATUS_ACTIVE,
    ], $attributes));
}

function ws34Product(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'WS34 Widget',
        'amount' => 2500,
        'quantity' => 5,
        'status' => 'active',
    ], $attributes));
}

function ws34Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ada-'.Str::lower(Str::random(6)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ], $attributes));
}

function ws34Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'WS34-ORD-'.$sequence,
        'subtotal' => 2500,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 2500,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ws34Transaction(?Order $order, int $businessId, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'order_id' => $order?->id,
        'business_id' => $businessId,
        'reference' => 'WS34-TXN-'.Str::upper(Str::random(6)),
        'amount' => 2500,
        'currency' => 'NGN',
        'status' => 'pending',
    ], $attributes));
}

function ws34Delivery(Order $order, array $attributes = []): OrderDelivery
{
    return OrderDelivery::create(array_merge([
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'status' => 'assigned',
        'driver_name' => 'Musa Ibrahim',
        'driver_phone' => '08031234567',
    ], $attributes));
}

function ws34Staff($business, string $role, array $attributes = []): User
{
    $staff = User::factory()->create(array_merge([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ], $attributes));

    setPermissionsTeamId($business->id);
    $staff->assignRole($role);

    return $staff;
}

// -------------------------------------------------------------------------
// Global search
// -------------------------------------------------------------------------

test('the palette returns all six legacy groups plus orders, each with a deep link', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws34Store($owner, $business, ['name' => 'Zeta Store']);
    $warehouse = ws34Warehouse($owner, $business, ['name' => 'Zeta Depot']);
    $customer = ws34Customer($business->id, [
        'first_name' => 'Zeta',
        'last_name' => 'Buyer',
        'account_id' => 'cus_ZETA0001',
    ]);
    $product = ws34Product($store, ['name' => 'Zeta Widget', 'product_code' => 'ZETA-PRD-1']);
    $order = ws34Order($store, ['order_number' => 'ZETA-ORD-1', 'customer_id' => $customer->id]);
    $transaction = ws34Transaction($order, $business->id, ['reference' => 'ZETA-TXN-1']);
    $staff = ws34Staff($business, 'Store Associate', ['name' => 'Zeta Staff']);

    $response = $this->withToken(ws34Token($owner))->getJson('/api/v1/management/search?q=Zeta');

    $response->assertOk()
        ->assertJsonPath('data.products.0.url', '/products/ZETA-PRD-1')
        ->assertJsonPath('data.stores.0.url', '/stores/'.$store->store_id)
        ->assertJsonPath('data.warehouses.0.url', '/warehouses/'.$warehouse->warehouse_code)
        ->assertJsonPath('data.customers.0.url', '/customers/cus_ZETA0001')
        ->assertJsonPath('data.orders.0.url', '/orders/ZETA-ORD-1')
        ->assertJsonPath('data.transactions.0.url', '/transactions/ZETA-TXN-1');

    expect(collect($response->json('data.staff'))->pluck('url'))
        ->toContain('/staff/'.$staff->account_code);

    // Titles/subtitles are pre-built so the modal renders without guessing.
    expect($response->json('data.products.0.title'))->toBe('Zeta Widget')
        ->and($response->json('data.products.0.subtitle'))->toBe('ZETA-PRD-1')
        ->and($response->json('data.transactions.0.subtitle'))->toContain('₦');

    expect(collect($response->json('data.groups'))->pluck('key')->all())
        ->toBe(['products', 'stores', 'warehouses', 'customers', 'orders', 'transactions', 'staff']);

    expect($product->product_code)->toBe('ZETA-PRD-1');
    expect($transaction->reference)->toBe('ZETA-TXN-1');
});

test('an owner row in the staff group is not turned into a broken staff link', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'name' => 'Zeta Owner',
    ]);

    ws34Staff($business, 'Store Associate', ['name' => 'Zeta Staff']);

    $response = $this->withToken(ws34Token($owner))->getJson('/api/v1/management/search?q=Zeta');

    $response->assertOk();

    $staff = collect($response->json('data.staff'));
    expect($staff)->toHaveCount(2);
    expect($staff->firstWhere('role', 'business_owner')['url'])->toBeNull();
    expect($staff->firstWhere('role', 'staff')['url'])->toStartWith('/staff/');
});

test('the palette needs two characters, like legacy', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws34Store($owner, $business, ['name' => 'Zeta Store']);
    ws34Product($store, ['name' => 'Zeta Widget']);
    ws34Customer($business->id, ['first_name' => 'Zeta']);

    $short = $this->withToken(ws34Token($owner))->getJson('/api/v1/management/search?q=Z');
    $short->assertOk();
    expect($short->json('data.groups'))->toBe([])
        ->and($short->json('data.products'))->toBe([])
        ->and($short->json('data.staff'))->toBe([]);

    $empty = $this->withToken(ws34Token($owner))->getJson('/api/v1/management/search?q=');
    $empty->assertOk();
    expect($empty->json('data.groups'))->toBe([]);
});

test('search groups are gated on the caller module permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws34Store($owner, $business, ['name' => 'Zeta Store']);
    ws34Warehouse($owner, $business, ['name' => 'Zeta Depot']);
    ws34Product($store, ['name' => 'Zeta Widget']);
    ws34Customer($business->id, ['first_name' => 'Zeta']);
    ws34Order($store, ['order_number' => 'ZETA-ORD-9']);
    ws34Staff($business, 'Store Associate', ['name' => 'Zeta Staff']);

    // Store Associate: products/orders/customers view only — no stores,
    // warehouses, transactions or staff permissions.
    $associate = ws34Staff($business, 'Store Associate', ['name' => 'Zeta Associate']);

    $response = $this->withToken(ws34Token($associate))->getJson('/api/v1/management/search?q=Zeta');

    $response->assertOk();

    expect($response->json('data.stores'))->toBe([]);
    expect($response->json('data.warehouses'))->toBe([]);
    expect($response->json('data.transactions'))->toBe([]);
    expect($response->json('data.staff'))->toBe([]);

    expect(collect($response->json('data.groups'))->pluck('key')->all())
        ->not->toContain('stores')
        ->not->toContain('warehouses')
        ->not->toContain('transactions')
        ->not->toContain('staff');
});

test('search never reaches another business records', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws34Store($owner, $business, ['name' => 'Zeta Store']);
    $otherStore = ws34Store($otherOwner, $otherBusiness, ['name' => 'Zeta Store Other']);

    $mine = ws34Customer($business->id, ['first_name' => 'Zeta', 'account_id' => 'cus_MINE0001']);
    $theirs = ws34Customer($otherBusiness->id, ['first_name' => 'Zeta', 'account_id' => 'cus_THEIR001']);

    $myOrder = ws34Order($store, ['order_number' => 'ZETA-MINE', 'customer_id' => $mine->id]);
    $otherOrder = ws34Order($otherStore, ['order_number' => 'ZETA-THEIRS', 'customer_id' => $theirs->id]);

    $myTransaction = ws34Transaction($myOrder, $business->id, ['reference' => 'ZETA-MINE-TXN']);
    $otherTransaction = ws34Transaction($otherOrder, $otherBusiness->id, ['reference' => 'ZETA-THEIRS-TXN']);

    $response = $this->withToken(ws34Token($owner))->getJson('/api/v1/management/search?q=Zeta');

    $response->assertOk();

    expect(array_column($response->json('data.customers'), 'account_id'))->toBe(['cus_MINE0001']);
    expect(array_column($response->json('data.orders'), 'order_number'))->toBe(['ZETA-MINE']);
    expect(array_column($response->json('data.transactions'), 'reference'))->toBe(['ZETA-MINE-TXN']);
    expect(array_column($response->json('data.stores'), 'store_id'))->toBe([$store->store_id]);

    expect($myTransaction->reference)->toBe('ZETA-MINE-TXN');
    expect($otherTransaction->reference)->toBe('ZETA-THEIRS-TXN');
});

test('a restricted manager only searches the stores they are assigned to', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws34Store($owner, $business, ['name' => 'Zeta Assigned']);
    $unassigned = ws34Store($owner, $business, ['name' => 'Zeta Unassigned']);

    ws34Product($assigned, ['name' => 'Zeta Visible', 'product_code' => 'ZETA-VISIBLE']);
    ws34Product($unassigned, ['name' => 'Zeta Hidden', 'product_code' => 'ZETA-HIDDEN']);

    $visibleCustomer = ws34Customer($business->id, ['first_name' => 'Zeta', 'account_id' => 'cus_VISIBLE01']);
    $hiddenCustomer = ws34Customer($business->id, ['first_name' => 'Zeta', 'account_id' => 'cus_HIDDEN001']);

    ws34Order($assigned, ['order_number' => 'ZETA-VISIBLE-ORD', 'customer_id' => $visibleCustomer->id]);
    ws34Order($unassigned, ['order_number' => 'ZETA-HIDDEN-ORD', 'customer_id' => $hiddenCustomer->id]);

    $manager = ws34Staff($business, 'Store Manager', ['name' => 'Zeta Manager']);
    $manager->assignedStores()->attach($assigned->id);

    $response = $this->withToken(ws34Token($manager))->getJson('/api/v1/management/search?q=Zeta');

    $response->assertOk();

    expect(array_column($response->json('data.products'), 'product_code'))->toBe(['ZETA-VISIBLE']);
    expect(array_column($response->json('data.orders'), 'order_number'))->toBe(['ZETA-VISIBLE-ORD']);
    expect(array_column($response->json('data.customers'), 'account_id'))->toBe(['cus_VISIBLE01']);
});

test('a restricted accountant only searches their store transactions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws34Store($owner, $business, ['name' => 'Zeta Assigned']);
    $unassigned = ws34Store($owner, $business, ['name' => 'Zeta Unassigned']);

    $visibleOrder = ws34Order($assigned, ['order_number' => 'ZETA-V-ORD']);
    $hiddenOrder = ws34Order($unassigned, ['order_number' => 'ZETA-H-ORD']);

    ws34Transaction($visibleOrder, $business->id, ['reference' => 'ZETA-VISIBLE-TXN']);
    ws34Transaction($hiddenOrder, $business->id, ['reference' => 'ZETA-HIDDEN-TXN']);

    $accountant = ws34Staff($business, 'Accountant', ['name' => 'Zeta Accountant']);
    $accountant->assignedStores()->attach($assigned->id);

    $response = $this->withToken(ws34Token($accountant))->getJson('/api/v1/management/search?q=Zeta');

    $response->assertOk();

    expect(array_column($response->json('data.transactions'), 'reference'))->toBe(['ZETA-VISIBLE-TXN']);
});

// -------------------------------------------------------------------------
// Shell counts
// -------------------------------------------------------------------------

test('shell counts returns every legacy sidebar counter', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws34Store($owner, $business, ['name' => 'Counted Store', 'pos_enabled' => true]);
    ws34Store($owner, $business, ['name' => 'Deleted Store', 'status' => Store::STATUS_DELETED]);
    ws34Warehouse($owner, $business, ['name' => 'Counted Depot']);
    ws34Warehouse($owner, $business, ['name' => 'Deleted Depot', 'status' => Warehouse::STATUS_DELETED]);

    ws34Staff($business, 'Store Associate');
    ws34Staff($business, 'Cashier');
    User::factory()->create(['role' => 'staff', 'status' => 'invited', 'business_id' => $business->id]);

    ws34Customer($business->id, ['first_name' => 'Ada']);
    ws34Customer($business->id, ['first_name' => 'Bola']);
    ws34Customer($business->id, ['first_name' => 'Deleted', 'status' => Customer::STATUS_DELETED]);

    $pendingA = ws34Order($store, ['status' => 'pending']);
    $pendingB = ws34Order($store, ['status' => 'pending']);
    ws34Order($store, ['status' => 'completed']);

    $openA = ws34Delivery($pendingA, ['status' => 'assigned']);
    ws34Delivery($pendingB, ['status' => 'in_transit']);
    $closed = ws34Order($store, ['status' => 'delivered']);
    ws34Delivery($closed, ['status' => 'delivered']);

    ws34Transaction($pendingA, $business->id, ['status' => 'pending']);
    ws34Transaction($pendingB, $business->id, ['status' => 'confirmed']);

    PosSession::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'staff_id' => $owner->id,
        'status' => PosSession::STATUS_OPEN,
    ]);

    $response = $this->withToken(ws34Token($owner))->getJson('/api/v1/management/shell/counts');

    $response->assertOk()
        ->assertJsonPath('data.counts.stores', 1)
        ->assertJsonPath('data.counts.warehouses', 1)
        ->assertJsonPath('data.counts.staff', 2)
        ->assertJsonPath('data.counts.customers', 2)
        ->assertJsonPath('data.counts.orders_pending', 2)
        ->assertJsonPath('data.counts.dispatches_open', 2)
        ->assertJsonPath('data.counts.transactions_pending', 1)
        ->assertJsonPath('data.counts.pos_open_sessions', 1)
        ->assertJsonPath('data.counts.pos_stores', 1)
        ->assertJsonPath('data.kyc.status', 'required')
        ->assertJsonPath('data.subscription.state', 'trial');

    expect($response->json('data.user.photo_url'))->toContain('gravatar.com');
    expect($openA->status)->toBe('assigned');
});

test('shell counts zeroes counters the caller has no permission for', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws34Store($owner, $business, ['name' => 'Assigned Store', 'pos_enabled' => true]);
    $other = ws34Store($owner, $business, ['name' => 'Other Store']);

    $mine = ws34Order($store, ['status' => 'pending']);
    ws34Order($other, ['status' => 'pending']);
    ws34Transaction($mine, $business->id, ['status' => 'pending']);

    $customer = ws34Customer($business->id, ['first_name' => 'Ada']);
    ws34Order($store, ['customer_id' => $customer->id]);
    ws34Customer($business->id, ['first_name' => 'Bola']);

    PosSession::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'staff_id' => $owner->id,
        'status' => PosSession::STATUS_OPEN,
    ]);
    PosSession::create([
        'store_id' => $other->id,
        'business_id' => $business->id,
        'staff_id' => $owner->id,
        'status' => PosSession::STATUS_OPEN,
    ]);

    // Store Associate: products/orders/customers/pos view only.
    $associate = ws34Staff($business, 'Store Associate');
    $associate->assignedStores()->attach($store->id);

    $response = $this->withToken(ws34Token($associate))->getJson('/api/v1/management/shell/counts');

    $response->assertOk()
        ->assertJsonPath('data.counts.stores', 0)
        ->assertJsonPath('data.counts.warehouses', 0)
        ->assertJsonPath('data.counts.staff', 0)
        ->assertJsonPath('data.counts.transactions_pending', 0)
        // Scoped to the assigned store.
        ->assertJsonPath('data.counts.orders_pending', 1)
        ->assertJsonPath('data.counts.customers', 1)
        ->assertJsonPath('data.counts.pos_open_sessions', 1);

    // The KYC pill is owner-only.
    expect($response->json('data.kyc'))->toBeNull();
});

test('shell counts never includes another business records', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $otherStore = ws34Store($otherOwner, $otherBusiness, ['name' => 'Theirs', 'pos_enabled' => true]);
    $otherOrder = ws34Order($otherStore, ['status' => 'pending']);
    ws34Transaction($otherOrder, $otherBusiness->id, ['status' => 'pending']);

    $response = $this->withToken(ws34Token($owner))->getJson('/api/v1/management/shell/counts');

    $response->assertOk()
        ->assertJsonPath('data.counts.stores', 0)
        ->assertJsonPath('data.counts.orders_pending', 0)
        ->assertJsonPath('data.counts.transactions_pending', 0);
});

test('the kyc pill switches to submitted once an application exists', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // forceFill, not create: the model's $fillable predates business_id.
    (new KycApplication)->forceFill([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'status' => KycApplication::STATUS_SUBMITTED,
        'legal_name' => 'Zeta Ventures',
        'submitted_at' => now(),
    ])->save();

    $this->withToken(ws34Token($owner))
        ->getJson('/api/v1/management/shell/counts')
        ->assertOk()
        ->assertJsonPath('data.kyc.status', 'submitted')
        ->assertJsonPath('data.kyc.has_application', true);
});

test('shell counts is cached for fifteen seconds per user', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws34Store($owner, $business, ['name' => 'Cached Store']);
    ws34Order($store, ['status' => 'pending']);

    $token = ws34Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/shell/counts')
        ->assertJsonPath('data.counts.orders_pending', 1);

    expect(Cache::has("shell.counts.{$owner->id}"))->toBeTrue();

    // A new order inside the TTL is deliberately not visible yet.
    ws34Order($store, ['status' => 'pending']);

    $this->withToken($token)->getJson('/api/v1/management/shell/counts')
        ->assertJsonPath('data.counts.orders_pending', 1);

    Cache::flush();

    $this->withToken($token)->getJson('/api/v1/management/shell/counts')
        ->assertJsonPath('data.counts.orders_pending', 2);
});

test('shell counts requires authentication', function () {
    $this->getJson('/api/v1/management/shell/counts')->assertUnauthorized();
});

// -------------------------------------------------------------------------
// Avatar
// -------------------------------------------------------------------------

test('an avatar can be uploaded, replaced and removed', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $token = ws34Token($owner);

    $upload = $this->withToken($token)->post('/api/v1/management/profile/photo', [
        'photo' => UploadedFile::fake()->image('me.jpg', 120, 120),
    ]);

    $upload->assertOk()->assertJsonPath('data.has_photo', true);

    $firstPath = $owner->fresh()->photo_path;
    expect($firstPath)->toStartWith('photos/');
    Storage::disk('public')->assertExists($firstPath);
    expect($upload->json('data.photo_url'))->toContain('storage/'.$firstPath);

    $replace = $this->withToken($token)->post('/api/v1/management/profile/photo', [
        'photo' => UploadedFile::fake()->image('new.png', 120, 120),
    ]);

    $replace->assertOk();
    $secondPath = $owner->fresh()->photo_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($secondPath);

    $remove = $this->withToken($token)->deleteJson('/api/v1/management/profile/photo');

    $remove->assertOk()->assertJsonPath('data.has_photo', false);
    expect($owner->fresh()->photo_path)->toBeNull();
    Storage::disk('public')->assertMissing($secondPath);
    expect($remove->json('data.photo_url'))->toContain('gravatar.com');
});

test('the avatar upload validates mime type and size', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $token = ws34Token($owner);

    $this->withToken($token)->post('/api/v1/management/profile/photo', [
        'photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
    ])->assertStatus(422)->assertJsonValidationErrors('photo');

    $this->withToken($token)->post('/api/v1/management/profile/photo', [
        'photo' => UploadedFile::fake()->image('big.jpg', 100, 100)->size(2500),
    ])->assertStatus(422)->assertJsonValidationErrors('photo');

    $this->withToken($token)->post('/api/v1/management/profile/photo', [])
        ->assertStatus(422)->assertJsonValidationErrors('photo');

    expect($owner->fresh()->photo_path)->toBeNull();
});

test('the avatar endpoints require authentication', function () {
    Storage::fake('public');

    $this->getJson('/api/v1/management/profile/photo')->assertUnauthorized();
    $this->postJson('/api/v1/management/profile/photo')->assertUnauthorized();
    $this->deleteJson('/api/v1/management/profile/photo')->assertUnauthorized();
});

test('uploading a photo never touches another user row', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws34Token($owner))->post('/api/v1/management/profile/photo', [
        'photo' => UploadedFile::fake()->image('me.jpg', 60, 60),
    ])->assertOk();

    expect($owner->fresh()->photo_path)->not->toBeNull();
    expect($otherOwner->fresh()->photo_path)->toBeNull();
});
