<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceCharge;
use App\Models\Store;
use App\Models\User;

/**
 * @return array{0: User, 1: Business, 2: Store}
 */
function posContext(): array
{
    [$owner, $business] = createBusinessOwner();

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'POS Store',
        'slug' => 'pos-store-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
        'pos_enabled' => true,
    ]);

    return [$owner->fresh(), $business, $store];
}

function posLogin($test, User $user): string
{
    $token = $test->postJson('/api/v1/pos/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('data.token');

    // The login endpoint authenticates through the session guard internally;
    // clear that state so subsequent requests use the bearer token only.
    app('auth')->forgetGuards();
    $test->flushSession();

    return $token;
}

/**
 * @return array<string, string>
 */
function posHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

function posProduct(Store $store, float $amount = 1000, int $quantity = 10): Product
{
    return Product::create([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'POS Item '.uniqid(),
        'amount' => $amount,
        'quantity' => $quantity,
        'status' => 'active',
    ]);
}

test('pos login exposes only the business stores and issues pos audience tokens', function () {
    [$owner, , $store] = posContext();
    [, , $otherStore] = posContext();

    $response = $this->postJson('/api/v1/pos/login', [
        'email' => $owner->email,
        'password' => 'password',
    ])->assertOk();

    $storeIds = collect($response->json('data.stores'))->pluck('id')->all();
    expect($storeIds)->toBe([$store->id]);
    expect($storeIds)->not->toContain($otherStore->id);

    $token = $response->json('data.token');

    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->getJson('/api/v1/pos/me', posHeaders($token))->assertOk();

    // POS tokens must not be accepted by the management API.
    $this->getJson('/api/v1/management/dashboard', posHeaders($token))->assertStatus(403);
});

test('pos owner cannot switch to another business store', function () {
    [$owner, , $store] = posContext();
    [, , $otherStore] = posContext();

    $token = posLogin($this, $owner);

    $this->postJson('/api/v1/pos/switch-store', ['store_id' => $otherStore->id], posHeaders($token))
        ->assertStatus(403);

    $this->postJson('/api/v1/pos/switch-store', ['store_id' => $store->id], posHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.store.id', $store->id);
});

test('pos checkout computes kobo change, replays idempotent requests, and returns a full receipt', function () {
    [$owner, , $store] = posContext();
    $product = posProduct($store, 1000, 10);

    $token = posLogin($this, $owner);
    $headers = posHeaders($token);

    $this->postJson('/api/v1/pos/stores/'.$store->store_id.'/session/open', ['opening_balance' => 0], $headers)
        ->assertCreated();

    $payload = [
        'idempotency_key' => 'test-idem-'.uniqid(),
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 2000, 'amount_tendered' => 500000]],
    ];

    $first = $this->postJson('/api/v1/pos/stores/'.$store->store_id.'/checkout', $payload, $headers)
        ->assertCreated();

    expect((float) $first->json('data.order.total'))->toBe(2000.0)
        ->and((int) $first->json('data.order.amount_tendered'))->toBe(500000)
        ->and((int) $first->json('data.order.change'))->toBe(300000);

    $orderNumber = $first->json('data.order.order_number');

    $this->postJson('/api/v1/pos/stores/'.$store->store_id.'/checkout', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('data.order.order_number', $orderNumber);

    expect(Order::where('store_id', $store->id)->where('source', 'pos')->count())->toBe(1);

    $order = Order::where('order_number', $orderNumber)->sole();

    $receipt = $this->getJson('/api/v1/pos/stores/'.$store->store_id.'/orders/'.$order->id.'/receipt', $headers)
        ->assertOk();

    expect((int) $receipt->json('data.order.change'))->toBe(300000)
        ->and((float) $receipt->json('data.order.tax'))->toBe(0.0)
        ->and($receipt->json('data.order.payments'))->toHaveCount(1);
});

test('pos invoice applies the service charge exactly once', function () {
    [$owner, , $store] = posContext();

    $charge = ServiceCharge::create([
        'store_id' => $store->id,
        'name' => 'Packaging',
        'amount' => 500,
        'is_active' => true,
    ]);

    $token = posLogin($this, $owner);

    $response = $this->postJson('/api/v1/pos/stores/'.$store->store_id.'/invoices', [
        'recipient_name' => 'Ada Tester',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 1500,
        'service_charge_id' => $charge->id,
        'items' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 1000]],
    ], posHeaders($token))->assertCreated();

    expect((float) $response->json('data.total'))->toBe(1500.0);
});

test('pos customer detail is scoped to the store business', function () {
    [$owner, , $store] = posContext();
    [, $otherBusiness] = posContext();

    $otherCustomer = Customer::create([
        'business_id' => $otherBusiness->id,
        'first_name' => 'Other',
        'last_name' => 'Tenant',
        'email' => 'other-'.uniqid().'@example.com',
        'phone' => '08000000001',
        'status' => Customer::STATUS_ACTIVE,
        'password' => 'secret-'.uniqid(),
    ]);

    $token = posLogin($this, $owner);

    $this->getJson('/api/v1/pos/stores/'.$store->store_id.'/customers/'.$otherCustomer->id, posHeaders($token))
        ->assertStatus(404);
});
