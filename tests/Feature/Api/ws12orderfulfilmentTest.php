<?php

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-12 — Order fulfilment actions, activity & e-mails
|--------------------------------------------------------------------------
*/

function ws12Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * @return array{0: User, 1: Business, 2: Store}
 */
function ws12Context(array $ownerAttributes = []): array
{
    [$owner, $business] = createBusinessOwner(array_merge(['trial_ends_at' => now()->addWeek()], $ownerAttributes));

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS12 Store',
        'slug' => 'ws12-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ]);

    return [$owner, $business, $store];
}

function ws12Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'WS12-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ws12Product(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'WS12 Widget',
        'amount' => 1000,
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));
}

/** A product with a store stock location, so a return has something to restore. */
function ws12StockedProduct(Store $store, int $stockQuantity, array $attributes = []): Product
{
    static $sequence = 0;
    $sequence++;

    $product = ws12Product($store, array_merge([
        'name' => 'WS12 Product '.$sequence,
        'product_code' => 'WS12-SKU-'.$sequence,
    ], $attributes));

    StockLocation::create([
        'business_id' => $store->business_id,
        'product_id' => $product->id,
        'locationable_type' => Store::class,
        'locationable_id' => $store->id,
        'quantity' => $stockQuantity,
        'min_quantity' => 0,
    ]);

    return $product;
}

function ws12Line(Order $order, Product $product, int $quantity = 1, array $attributes = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'product_code' => $product->product_code,
        'unit_price' => 1000,
        'quantity' => $quantity,
        'subtotal' => 1000 * $quantity,
        'is_digital' => false,
    ], $attributes));
}

function ws12Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS12-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws12DeliveryAgent(Business $business, array $attributes = []): User
{
    $agent = User::factory()->create(array_merge([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => 'Dan Driver',
        'phone' => '08031234567',
        'email' => 'agent-'.Str::lower(Str::random(6)).'@ws12.test',
    ], $attributes));

    setPermissionsTeamId($business->id);
    $agent->assignRole('Delivery Agent');

    return $agent;
}

test('the fulfilment console returns the order with delivery, payments, actions and activity', function () {
    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'processing']);
    $product = ws12StockedProduct($store, 5);
    ws12Line($order, $product, 2);
    ws12Transaction($order);

    ActivityLog::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'action' => 'accepted',
        'subject_type' => Order::class,
        'subject_id' => $order->id,
        'description' => 'Order accepted',
    ]);

    $response = $this->withToken(ws12Token($owner))
        ->getJson("/api/v1/management/orders/{$order->order_number}/fulfilment");

    $response->assertOk()
        ->assertJsonPath('data.order.order_number', $order->order_number)
        ->assertJsonPath('data.order.status', 'processing')
        ->assertJsonPath('data.order.actions.dispatch', true)
        ->assertJsonPath('data.order.actions.accept', false)
        ->assertJsonPath('data.order.actions.return', false)
        // PHP's JSON encoder emits whole floats as integers, so the decoded
        // value is int 1000 — assertSame would reject 1000.0 here.
        ->assertJsonPath('data.order.totals.total', 1000)
        ->assertJsonPath('data.order.items.0.product_code', $product->product_code)
        ->assertJsonPath('data.order.transactions.0.status', 'confirmed')
        ->assertJsonStructure([
            'data' => ['order' => [
                'items', 'totals', 'actions', 'payment_warning', 'delivery', 'transactions', 'activity',
            ]],
        ]);

    expect($response->json('data.order.activity'))->toHaveCount(1)
        ->and($response->json('data.order.activity.0.action'))->toBe('accepted');
});

test('accepting a pending order transitions it, logs activity and queues the status mail to customer, owner and admin', function () {
    config(['mail.admin_email' => 'admin@ws12.test']);
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);
    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Bella',
        'last_name' => 'Buyer',
        'email' => 'buyer@ws12.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
    ]);
    $order->update(['customer_id' => $customer->id]);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/accept")
        ->assertOk()
        ->assertJsonPath('data.order.status', 'accepted');

    expect($order->fresh()->status)->toBe(OrderStatus::ACCEPTED);

    expect(ActivityLog::where('subject_type', Order::class)
        ->where('subject_id', $order->id)
        ->where('action', 'accepted')
        ->where('user_id', $owner->id)
        ->exists())->toBeTrue();

    Mail::assertQueued(OrderStatusUpdatedMail::class, fn (OrderStatusUpdatedMail $mail) => $mail->hasTo('buyer@ws12.test'));
    Mail::assertQueued(OrderStatusUpdatedMail::class, fn (OrderStatusUpdatedMail $mail) => $mail->hasTo(strtolower($owner->email)));
    Mail::assertQueued(OrderStatusUpdatedMail::class, fn (OrderStatusUpdatedMail $mail) => $mail->hasTo('admin@ws12.test'));
    Mail::assertQueued(OrderStatusUpdatedMail::class, 3);
});

test('status e-mails are deduplicated when the customer, owner and admin share an address', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    config(['mail.admin_email' => 'shared@ws12.test']);

    $order = ws12Order($store);
    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Same',
        'last_name' => 'Address',
        'email' => 'shared@ws12.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
    ]);
    $order->update(['customer_id' => $customer->id]);
    $owner->update(['email' => 'shared@ws12.test']);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/accept")
        ->assertOk();

    Mail::assertQueued(OrderStatusUpdatedMail::class, 1);
});

test('accept refuses an order whose first payment is still pending unless the override is explicit', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);
    ws12Transaction($order, ['reference' => 'WS12-PENDING-1', 'status' => TransactionStatus::PENDING, 'paid_at' => null]);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/accept")
        ->assertStatus(409)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'pending'));

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/accept", ['override_pending_payment' => true])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'accepted');

    expect($order->fresh()->status)->toBe(OrderStatus::ACCEPTED);
});

test('guarded transitions refuse the wrong source state with the legacy message', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'pending']);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/process")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only accepted orders can be moved to processing.');

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/dispatch")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only processing orders can be dispatched.');

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/deliver")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only dispatched orders can be marked as delivered.');

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/complete")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only delivered orders can be completed.');

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/return")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only delivered or completed orders can be returned.');
});

test('the full fulfilment chain runs pending to completed', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);
    $token = ws12Token($owner);

    $this->withToken($token)->postJson("/api/v1/management/orders/{$order->order_number}/accept")->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::ACCEPTED);

    $this->withToken($token)->postJson("/api/v1/management/orders/{$order->order_number}/process")->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING);

    $this->withToken($token)->postJson("/api/v1/management/orders/{$order->order_number}/dispatch")->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::DISPATCHED);

    $this->withToken($token)->postJson("/api/v1/management/orders/{$order->order_number}/deliver")->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::DELIVERED);

    $this->withToken($token)->postJson("/api/v1/management/orders/{$order->order_number}/complete")->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);

    expect(ActivityLog::where('subject_id', $order->id)->count())->toBe(5);
});

test('dispatch persists the tracking number and the delivery agent the legacy form dropped', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'processing']);
    $agent = ws12DeliveryAgent($business);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/dispatch", [
            'delivery_agent_id' => $agent->id,
            'tracking_number' => 'TRK-WS12-9',
            'delivery_notes' => 'Call on arrival',
            'estimated_delivery_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'dispatched')
        ->assertJsonPath('data.order.delivery.tracking_number', 'TRK-WS12-9')
        ->assertJsonPath('data.order.delivery.delivery_agent_id', $agent->id)
        ->assertJsonPath('data.order.delivery.driver_name', 'Dan Driver');

    $delivery = OrderDelivery::where('order_id', $order->id)->first();

    expect($delivery)->not->toBeNull()
        // Legacy validated tracking_number and never wrote it; it also posted
        // delivery_agent_id only to prefill the driver inputs. Both persist now.
        ->and($delivery->tracking_number)->toBe('TRK-WS12-9')
        ->and($delivery->delivery_agent_id)->toBe($agent->id)
        ->and($delivery->status)->toBe('assigned')
        ->and($delivery->driver_phone)->toBe('08031234567')
        ->and($delivery->created_by)->toBe($owner->id);
});

test('dispatch rejects an agent who is not an active delivery agent of this business', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $order = ws12Order($store, ['status' => 'processing']);

    $outsider = ws12DeliveryAgent($otherBusiness);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/dispatch", [
            'delivery_agent_id' => $outsider->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('delivery_agent_id');

    expect($order->fresh()->status)->toBe(OrderStatus::PROCESSING);
});

test('delivering updates the delivery record with the actual delivery time', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'dispatched']);
    $delivery = OrderDelivery::create([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'status' => 'assigned',
        'driver_name' => 'Dan Driver',
    ]);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/deliver")
        ->assertOk()
        ->assertJsonPath('data.order.status', 'delivered');

    $delivery->refresh();

    expect($delivery->status)->toBe('delivered')
        ->and($delivery->actual_delivery_at)->not->toBeNull();
});

test('cancelling appends the reason to the notes and refuses anything past accepted', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'accepted', 'notes' => 'Leave at the gate.']);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/cancel", ['reason' => 'Customer changed their mind'])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'cancelled');

    expect($order->fresh()->notes)->toBe("Leave at the gate.\nCancellation reason: Customer changed their mind");

    expect(ActivityLog::where('subject_id', $order->id)->where('action', 'cancelled')->exists())->toBeTrue();

    $processing = ws12Order($store, ['status' => 'processing']);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$processing->order_number}/cancel", ['reason' => 'Too late'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only pending or accepted orders can be cancelled.');
});

test('cancelling enforces the 500 character reason limit', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/cancel", ['reason' => str_repeat('x', 501)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

test('returning restores stock for every stocked line, including digital lines, and unblocks refunds', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'delivered']);

    // One physical line and one digital line — legacy restored stock for any
    // line with a stock location, with no is_digital filter. Adding one here
    // would be behaviour legacy never had.
    $physical = ws12StockedProduct($store, 5, ['name' => 'Physical Widget', 'product_code' => 'WS12-PHYS']);
    $digital = ws12StockedProduct($store, 2, ['name' => 'Digital Widget', 'product_code' => 'WS12-DIGI']);

    ws12Line($order, $physical, 3);
    ws12Line($order, $digital, 2, ['is_digital' => true]);

    $delivery = OrderDelivery::create([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'status' => 'delivered',
        'driver_name' => 'Dan Driver',
    ]);

    $store->update(['balance' => 100000]);
    $transaction = ws12Transaction($order, ['status' => TransactionStatus::CONFIRMED]);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/return", ['reason' => 'Damaged in transit'])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'returned');

    $physicalLocation = StockLocation::where('product_id', $physical->id)->first();
    $digitalLocation = StockLocation::where('product_id', $digital->id)->first();

    expect($physicalLocation->fresh()->quantity)->toBe(8)
        ->and($digitalLocation->fresh()->quantity)->toBe(4);

    expect(StockMovement::where('reference_type', Order::class)->where('reference_id', $order->id)->count())->toBe(2);

    $delivery->refresh();

    expect($delivery->status)->toBe('returned')
        ->and($delivery->return_reason)->toBe('Damaged in transit');

    // Refunds refuse delivered/completed orders with "mark the order as
    // returned first" — the return is what makes the refund reachable.
    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/transactions/{$transaction->reference}/refund", ['reason' => 'Returned order'])
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'refunded');
});

test('returning sums duplicate product lines so the whole quantity is restored', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'completed']);
    $product = ws12StockedProduct($store, 5);

    // Two lines for the same product: recordAddition is idempotent per
    // (order, stock location), so a naive per-line loop silently restores
    // only the first line's quantity.
    ws12Line($order, $product, 2);
    ws12Line($order, $product, 3);

    $this->withToken(ws12Token($owner))
        ->postJson("/api/v1/management/orders/{$order->order_number}/return")
        ->assertOk()
        ->assertJsonPath('data.order.status', 'returned');

    $location = StockLocation::where('product_id', $product->id)->first();

    expect($location->fresh()->quantity)->toBe(10)
        ->and(StockMovement::where('reference_type', Order::class)->where('reference_id', $order->id)->count())->toBe(1);
});

test('marking a payment unpaid voids the transaction instead of deleting it', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);
    $transaction = ws12Transaction($order, ['reference' => 'WS12-VOID-1', 'status' => TransactionStatus::CONFIRMED]);

    $this->withToken(ws12Token($owner))
        ->putJson("/api/v1/management/orders/{$order->order_number}/payment", ['payment_status' => 'unpaid'])
        ->assertOk();

    $transaction->refresh();

    // Legacy deleted the row outright; the audit asked for a void instead.
    expect(Transaction::whereKey($transaction->id)->exists())->toBeTrue()
        ->and($transaction->status)->toBe(TransactionStatus::CANCELED)
        ->and($transaction->paid_at)->toBeNull()
        ->and($transaction->metadata['voided'])->toBeTrue()
        ->and($transaction->metadata['voided_by'])->toBe($owner->id);
});

test('marking a payment paid with no transaction creates a manual cash transaction', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);
    PaymentMethod::firstOrCreate(['code' => 'cash'], [
        'name' => 'Cash',
        'type' => 'traditional',
        'is_active' => true,
    ]);

    $this->withToken(ws12Token($owner))
        ->putJson("/api/v1/management/orders/{$order->order_number}/payment", ['payment_status' => 'paid'])
        ->assertOk()
        ->assertJsonPath('data.order.transactions.0.status', 'confirmed');

    $transaction = Transaction::where('order_id', $order->id)->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->reference)->toStartWith('MAN-')
        ->and($transaction->currency)->toBe('NGN')
        ->and($transaction->payment_method_id)->toBe(PaymentMethod::where('code', 'cash')->value('id'))
        ->and($transaction->paid_at)->not->toBeNull();

    $this->withToken(ws12Token($owner))
        ->putJson("/api/v1/management/orders/{$order->order_number}/payment", ['payment_status' => 'nonsense'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_status');
});

test('the delivery agent picker only returns active delivery agents of the order business', function () {
    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);

    $agent = ws12DeliveryAgent($business, ['name' => 'Active Agent']);
    ws12DeliveryAgent($business, ['name' => 'Suspended Agent', 'status' => 'suspended']);

    // A staff member of the same business without the Delivery Agent role.
    $plain = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => 'Plain Staff',
    ]);

    $response = $this->withToken(ws12Token($owner))
        ->getJson("/api/v1/management/orders/{$order->order_number}/delivery-agents")
        ->assertOk();

    $agents = $response->json('data.delivery_agents');

    expect(array_column($agents, 'name'))->toBe(['Active Agent'])
        ->and($agents[0]['id'])->toBe($agent->id)
        ->and(array_column($agents, 'id'))->not->toContain($plain->id);
});

test('another business cannot read or act on the order', function () {
    Mail::fake();

    [$owner, $business, $store] = ws12Context();
    [$intruder, $intruderBusiness, $intruderStore] = ws12Context();

    $order = ws12Order($store);
    $token = ws12Token($intruder);

    $this->withToken($token)
        ->getJson("/api/v1/management/orders/{$order->order_number}/fulfilment")
        ->assertStatus(403);

    $this->withToken($token)
        ->postJson("/api/v1/management/orders/{$order->order_number}/accept")
        ->assertStatus(403);

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING);
});

test('a user without the orders status_update permission cannot run the transitions', function () {
    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws12Token($staff))
        ->postJson("/api/v1/management/orders/{$order->order_number}/accept")
        ->assertStatus(403);

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING);
});

test('the delivery panel exposes the bank account on a manual transfer transaction', function () {
    [$owner, $business, $store] = ws12Context();
    $order = ws12Order($store, ['status' => 'processing']);

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'WS12 Store Ltd',
        'is_verified' => true,
    ]);

    ws12Transaction($order, [
        'reference' => 'WS12-BANK-1',
        'status' => TransactionStatus::PENDING,
        'paid_at' => null,
        'store_bank_id' => $bank->id,
    ]);

    $this->withToken(ws12Token($owner))
        ->getJson("/api/v1/management/orders/{$order->order_number}/fulfilment")
        ->assertOk()
        ->assertJsonPath('data.order.transactions.0.bank.bank_name', 'GTBank')
        ->assertJsonPath('data.order.transactions.0.bank.account_number', '0123456789')
        ->assertJsonPath('data.order.payment_warning.transaction.reference', 'WS12-BANK-1');
});
