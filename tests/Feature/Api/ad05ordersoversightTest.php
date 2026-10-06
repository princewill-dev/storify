<?php

use App\Enums\TransactionStatus;
use App\Mail\CustomerOrderStatusUpdatedMail;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-5 — Platform orders oversight (admin console)
|--------------------------------------------------------------------------
| Covers the platform-wide list with derived payment badges and stat cards,
| the combining filters and their validation, the whitelisted sort, the order
| detail payload (items/customer/delivery/transactions/activity/quick info),
| the edit with its total recompute, the status transition (dated note append
| + customer email exactly once), the transaction-syncing payment override,
| the soft delete, the Shop4Me queue, the transaction status override, and the
| audience/permission/platform-role refusals.
*/

function ad05AdminToken(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad05SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad05PlatformAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole('Platform Admin');

    return $user;
}

function ad05Store(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'AD05 Store',
        'slug' => 'ad05-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad05Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ad05-'.Str::lower(Str::random(8)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => 'ACTIVE',
    ], $attributes));
}

function ad05Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'AD05-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ad05Item(Order $order, array $attributes = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'product_name' => 'AD05 Widget',
        'product_code' => 'AD05-SKU',
        'unit_price' => 1000,
        'quantity' => 1,
        'subtotal' => 1000,
        'is_digital' => false,
    ], $attributes));
}

function ad05Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'AD05-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

test('the orders list spans every business and derives payment badges from transactions', function () {
    $admin = ad05SuperAdmin();
    [$ownerA, $businessA] = createBusinessOwner();
    [$ownerB, $businessB] = createBusinessOwner();

    $storeA = ad05Store($ownerA, $businessA, ['name' => 'Alpha Store']);
    $storeB = ad05Store($ownerB, $businessB, ['name' => 'Beta Store']);

    $customerA = ad05Customer($businessA->id, ['first_name' => 'Ada', 'last_name' => 'Obi']);
    $customerB = ad05Customer($businessB->id, ['first_name' => 'Bola', 'last_name' => 'Ade']);

    $paid = ad05Order($storeA, [
        'order_number' => 'AD05-PAID',
        'customer_id' => $customerA->id,
        'status' => 'processing',
        'amount_paid' => 1000,
    ]);
    ad05Item($paid);
    ad05Item($paid);
    ad05Transaction($paid, ['amount' => 1000, 'status' => TransactionStatus::CONFIRMED]);

    $partial = ad05Order($storeB, [
        'order_number' => 'AD05-PART',
        'customer_id' => $customerB->id,
        'amount_paid' => 400,
    ]);
    ad05Transaction($partial, ['reference' => 'AD05-TXN-PART', 'amount' => 400, 'status' => TransactionStatus::CONFIRMED]);

    ad05Order($storeA, [
        'order_number' => 'AD05-UNPAID',
        'customer_id' => $customerA->id,
    ]);

    ad05Order($storeB, [
        'order_number' => 'AD05-S4M',
        'customer_id' => $customerB->id,
        'source' => 'shop4me',
    ]);

    $response = $this->getJson('/api/v1/admin/orders', ['Authorization' => 'Bearer '.ad05AdminToken($admin)]);

    $response->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.stats.total', 4)
        ->assertJsonPath('meta.stats.pending', 3)
        ->assertJsonPath('meta.stats.processing', 1)
        // Revenue sums order totals behind CONFIRMED transactions (1000+1000).
        ->assertJsonPath('meta.stats.revenue', 2000)
        ->assertJsonPath('meta.stats.by_payment.unpaid', 2)
        ->assertJsonPath('meta.stats.by_payment.partial', 1)
        ->assertJsonPath('meta.stats.by_payment.paid', 1);

    $rows = collect($response->json('data.orders'))->keyBy('order_number');

    // Both businesses' orders appear — this is platform oversight, not a
    // tenant-scoped read.
    expect($rows)->toHaveCount(4)
        ->and($rows['AD05-PAID']['payment_status'])->toBe('paid')
        ->and($rows['AD05-PART']['payment_status'])->toBe('partial')
        ->and($rows['AD05-UNPAID']['payment_status'])->toBe('unpaid')
        ->and($rows['AD05-PAID']['customer']['name'])->toBe('Ada Obi')
        ->and($rows['AD05-PAID']['store'])->toBe('Alpha Store')
        ->and($rows['AD05-PAID']['items_count'])->toBe(2)
        ->and($rows['AD05-S4M']['is_shop4me'])->toBeTrue()
        ->and($rows['AD05-PAID']['is_shop4me'])->toBeFalse();
});

test('order list filters combine and every payment facet matches its derived badge', function () {
    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business, ['name' => 'Lekki Store']);
    $otherStore = ad05Store($owner, $business, ['name' => 'Ikeja Store']);
    $customer = ad05Customer($business->id, ['first_name' => 'Chika', 'last_name' => 'Eze']);

    $paid = ad05Order($store, ['order_number' => 'AD05-F-PAID', 'customer_id' => $customer->id, 'status' => 'completed', 'amount_paid' => 1000]);
    ad05Transaction($paid, ['status' => TransactionStatus::CONFIRMED]);

    $partial = ad05Order($store, ['order_number' => 'AD05-F-PART', 'customer_id' => $customer->id, 'amount_paid' => 400]);
    ad05Transaction($partial, ['reference' => 'AD05-F-TXN-PART', 'amount' => 400, 'status' => TransactionStatus::CONFIRMED]);

    $refunded = ad05Order($store, ['order_number' => 'AD05-F-REF', 'customer_id' => $customer->id, 'status' => 'returned']);
    ad05Transaction($refunded, ['reference' => 'AD05-F-TXN-REF', 'status' => TransactionStatus::REFUNDED]);

    $failed = ad05Order($store, ['order_number' => 'AD05-F-FAIL', 'customer_id' => $customer->id]);
    ad05Transaction($failed, ['reference' => 'AD05-F-TXN-FAIL', 'status' => TransactionStatus::CANCELED]);

    $pending = ad05Order($store, ['order_number' => 'AD05-F-PEND', 'customer_id' => $customer->id]);
    ad05Transaction($pending, ['reference' => 'AD05-F-TXN-PEND', 'status' => TransactionStatus::PENDING]);

    $backdated = ad05Order($otherStore, ['order_number' => 'AD05-F-OTHER', 'customer_id' => $customer->id]);
    $backdated->forceFill(['created_at' => Carbon::parse('2025-01-05 10:00:00')])->save();

    $token = ad05AdminToken($admin);

    $numbers = function (string $query) use ($token) {
        $response = $this->getJson('/api/v1/admin/orders?'.$query, ['Authorization' => 'Bearer '.$token]);
        $response->assertOk();

        return collect($response->json('data.orders'))->pluck('order_number')->all();
    };

    // Free text matches the order number and the customer name.
    expect($numbers('q=AD05-F-PAID'))->toContain('AD05-F-PAID')->not->toContain('AD05-F-PART');
    expect($numbers('q=Eze'))->toHaveCount(6);
    expect($numbers('q=Chika'))->toHaveCount(6);

    // Store facets (id and name), order status, and the date range.
    expect($numbers('store_id='.$otherStore->id))->toBe(['AD05-F-OTHER']);
    expect($numbers('store=Ikeja'))->toBe(['AD05-F-OTHER']);
    expect($numbers('store='.$store->id))->toHaveCount(0);
    expect($numbers('status=completed'))->toBe(['AD05-F-PAID']);
    expect($numbers('from=2025-01-01&to=2025-01-31'))->toBe(['AD05-F-OTHER']);

    // Payment facets derive from transaction state, not the dropped column.
    expect($numbers('payment_status=paid'))->toBe(['AD05-F-PAID']);
    expect($numbers('payment_status=partial'))->toBe(['AD05-F-PART']);
    expect($numbers('payment_status=refunded'))->toBe(['AD05-F-REF']);
    expect($numbers('payment_status=failed'))->toBe(['AD05-F-FAIL']);
    expect($numbers('payment_status=pending'))->toBe(['AD05-F-PEND']);
    // Cancelled-only transactions derive as unpaid, matching the badge — so
    // do orders with no transactions at all.
    expect($numbers('payment_status=unpaid'))->toBe(['AD05-F-FAIL', 'AD05-F-OTHER']);

    // Facets combine.
    expect($numbers('store_id='.$otherStore->id.'&payment_status=unpaid'))->toBe(['AD05-F-OTHER']);
    expect($numbers('store_id='.$store->id.'&payment_status=paid'))->toBe(['AD05-F-PAID']);
    expect($numbers('store_id='.$otherStore->id.'&status=completed'))->toHaveCount(0);

    // Stat cards keep their shape when a status facet narrows the table.
    $filtered = $this->getJson('/api/v1/admin/orders?status=completed', ['Authorization' => 'Bearer '.$token]);
    $filtered->assertOk()
        ->assertJsonPath('meta.stats.total', 6)
        ->assertJsonPath('meta.total', 1);
});

test('order list sorting is whitelisted and every filter is validated', function () {
    $admin = ad05SuperAdmin();
    $token = ad05AdminToken($admin);

    $this->getJson('/api/v1/admin/orders?sort=created_at&direction=asc', ['Authorization' => 'Bearer '.$token])->assertOk();
    $this->getJson('/api/v1/admin/orders?sort_by=order_number&sort_order=asc', ['Authorization' => 'Bearer '.$token])->assertOk();

    $invalid = [
        'sort=id' => 'sort',
        'sort_by=notes' => 'sort_by',
        'sort_order=sideways' => 'sort_order',
        'status=bogus' => 'status',
        'payment_status=bogus' => 'payment_status',
        'from=not-a-date' => 'from',
        'to=2020-01-01&from=2021-01-01' => 'to',
        'per_page=500' => 'per_page',
        'store_id=999999' => 'store_id',
    ];

    foreach ($invalid as $query => $field) {
        $this->getJson('/api/v1/admin/orders?'.$query, ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }
});

test('the order detail returns items, totals, customer, delivery, transactions and the activity timeline', function () {
    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business, ['name' => 'Detail Store']);
    $customer = ad05Customer($business->id, [
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'street_address' => '12 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ]);

    $order = ad05Order($store, [
        'order_number' => 'AD05-DETAIL',
        'customer_id' => $customer->id,
        'subtotal' => 2000,
        'shipping_fee' => 500,
        'tax' => 100,
        'total' => 2600,
        'amount_paid' => 2600,
        'status' => 'dispatched',
        'notes' => 'Handle with care.',
        'delivery_state' => 'Lagos',
        'delivery_area' => 'Ikeja',
        'delivery_days' => 2,
    ]);

    ad05Item($order, ['product_name' => 'Blue Shirt', 'product_code' => 'BS-1', 'quantity' => 2, 'subtotal' => 2000]);
    ad05Item($order, ['product_name' => 'Cap', 'product_code' => 'CP-1', 'unit_price' => 500, 'subtotal' => 500]);
    ad05Transaction($order, ['amount' => 1000, 'status' => TransactionStatus::CONFIRMED, 'reference' => 'AD05-D-TXN-1']);
    ad05Transaction($order, ['amount' => 1600, 'status' => TransactionStatus::PAID, 'reference' => 'AD05-D-TXN-2']);

    ActivityLog::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'action' => 'status_updated',
        'subject_type' => Order::class,
        'subject_id' => $order->id,
        'description' => 'Changed order status from pending to dispatched',
    ]);

    $response = $this->getJson('/api/v1/admin/orders/AD05-DETAIL', ['Authorization' => 'Bearer '.ad05AdminToken($admin)]);

    $response->assertOk()
        ->assertJsonPath('data.order.order_number', 'AD05-DETAIL')
        ->assertJsonPath('data.order.status', 'dispatched')
        ->assertJsonPath('data.order.payment_status', 'paid')
        ->assertJsonPath('data.order.subtotal', 2000)
        ->assertJsonPath('data.order.shipping_fee', 500)
        ->assertJsonPath('data.order.tax', 100)
        ->assertJsonPath('data.order.total', 2600)
        ->assertJsonPath('data.order.notes', 'Handle with care.')
        ->assertJsonPath('data.order.customer.name', 'Ada Obi')
        ->assertJsonPath('data.order.customer.full_address', '12 Marina, Lagos, Lagos, Nigeria')
        ->assertJsonPath('data.order.delivery.state', 'Lagos')
        ->assertJsonPath('data.order.delivery.days', 2)
        ->assertJsonPath('data.order.quick_info.store', 'Detail Store')
        ->assertJsonPath('data.order.quick_info.store_owner', $owner->name)
        ->assertJsonPath('data.order.activity.0.description', 'Changed order status from pending to dispatched');

    expect($response->json('data.order.items'))->toHaveCount(2)
        ->and($response->json('data.order.items.0.product_code'))->toBe('BS-1')
        ->and($response->json('data.order.transactions'))->toHaveCount(2)
        ->and($response->json('data.order.activity.0.user'))->toBe($owner->name);
});

test('an order edit persists the fee fields, recomputes the total and audits the change', function () {
    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business);
    $customer = ad05Customer($business->id);

    $order = ad05Order($store, [
        'order_number' => 'AD05-EDIT',
        'customer_id' => $customer->id,
        'subtotal' => 1000,
        'total' => 1000,
        'notes' => 'Original note',
    ]);

    $response = $this->putJson('/api/v1/admin/orders/AD05-EDIT', [
        'shipping_fee' => 250.50,
        'tax' => 49.50,
        'notes' => 'Updated note',
        'status' => 'accepted',
    ], ['Authorization' => 'Bearer '.ad05AdminToken($admin)]);

    $response->assertOk()
        ->assertJsonPath('data.order.shipping_fee', 250.5)
        ->assertJsonPath('data.order.tax', 49.5)
        ->assertJsonPath('data.order.total', 1300)
        ->assertJsonPath('data.order.notes', 'Updated note')
        ->assertJsonPath('data.order.status', 'accepted');

    $order->refresh();

    expect((float) $order->shipping_fee)->toBe(250.5)
        ->and((float) $order->tax)->toBe(49.5)
        ->and((float) $order->total)->toBe(1300.0)
        ->and($order->notes)->toBe('Updated note');

    $log = ActivityLog::where('action', 'updated')->where('subject_id', $order->id)->first();

    expect($log)->not->toBeNull()
        ->and((float) $log->old_values['total'])->toBe(1000.0)
        ->and((float) $log->new_values['total'])->toBe(1300.0)
        ->and($log->old_values['notes'])->toBe('Original note');

    // The fields legacy silently discarded are not accepted as anything else.
    $this->putJson('/api/v1/admin/orders/AD05-EDIT', [
        'shipping_fee' => -5,
    ], ['Authorization' => 'Bearer '.ad05AdminToken($admin)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('shipping_fee');

    $this->putJson('/api/v1/admin/orders/AD05-EDIT', [
        'status' => 'sideways',
    ], ['Authorization' => 'Bearer '.ad05AdminToken($admin)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('a status transition appends the dated note, audits old to new and emails the customer once', function () {
    Mail::fake();

    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business);
    $customer = ad05Customer($business->id);

    $order = ad05Order($store, [
        'order_number' => 'AD05-STATUS',
        'customer_id' => $customer->id,
        'notes' => 'First note',
    ]);

    $token = ad05AdminToken($admin);

    $this->patchJson('/api/v1/admin/orders/AD05-STATUS/status', [
        'status' => 'processing',
        'notes' => 'Packed and ready.',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'processing');

    $order->refresh();

    expect($order->status->value)->toBe('processing')
        ->and($order->notes)->toStartWith('First note')
        ->and($order->notes)->toContain('] Status changed to processing: Packed and ready.');

    Mail::assertQueued(CustomerOrderStatusUpdatedMail::class, 1);

    $mail = Mail::queued(CustomerOrderStatusUpdatedMail::class)->first();

    expect($mail->hasTo($customer->email))->toBeTrue()
        ->and($mail->oldStatus)->toBe('pending')
        ->and($mail->newStatus)->toBe('processing');

    $log = ActivityLog::where('action', 'status_updated')->where('subject_id', $order->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['status'])->toBe('pending')
        ->and($log->new_values['status'])->toBe('processing');

    // Saving the same status is a no-op: no second email, no second audit row.
    $this->patchJson('/api/v1/admin/orders/AD05-STATUS/status', [
        'status' => 'processing',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    Mail::assertQueued(CustomerOrderStatusUpdatedMail::class, 1);
    expect(ActivityLog::where('action', 'status_updated')->count())->toBe(1);

    // An unknown status is refused.
    $this->patchJson('/api/v1/admin/orders/AD05-STATUS/status', [
        'status' => 'teleported',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('a walk-in order transitions without a customer email and still audits', function () {
    Mail::fake();

    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business);

    $order = ad05Order($store, [
        'order_number' => 'AD05-WALKIN',
        'source' => 'pos',
        'customer_id' => null,
        'meta' => ['customer_name' => 'Walk-in Buyer', 'customer_phone' => '08099998888'],
    ]);

    $response = $this->patchJson('/api/v1/admin/orders/AD05-WALKIN/status', [
        'status' => 'completed',
    ], ['Authorization' => 'Bearer '.ad05AdminToken($admin)]);

    $response->assertOk()->assertJsonPath('data.order.customer.is_walk_in', true)
        ->assertJsonPath('data.order.customer.name', 'Walk-in Buyer');

    expect($response->json('message'))->toContain('No customer email is on file');

    Mail::assertQueued(CustomerOrderStatusUpdatedMail::class, 0);
    expect(ActivityLog::where('action', 'status_updated')->count())->toBe(1);
});

test('the payment override creates, updates and deletes the order transactions atomically', function () {
    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business);
    $customer = ad05Customer($business->id);

    PaymentMethod::create(['name' => 'Cash', 'code' => 'cash']);

    $order = ad05Order($store, [
        'order_number' => 'AD05-PAY',
        'customer_id' => $customer->id,
        'total' => 1500,
    ]);

    $token = ad05AdminToken($admin);

    // Paid with no recorded transaction creates a manual MAN- row through the
    // cash method, for the order total.
    $this->patchJson('/api/v1/admin/orders/AD05-PAY/payment-status', [
        'payment_status' => 'paid',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.order.payment_status', 'paid');

    $transaction = $order->transactions()->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->reference)->toStartWith('MAN-')
        ->and($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((float) $transaction->amount)->toBe(1500.0)
        ->and($transaction->paymentMethod->code)->toBe('cash');

    // Refunded updates the same leg instead of adding another.
    $this->patchJson('/api/v1/admin/orders/AD05-PAY/payment-status', [
        'payment_status' => 'refunded',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.order.payment_status', 'refunded');

    expect($order->transactions()->count())->toBe(1)
        ->and($order->transactions()->first()->status)->toBe(TransactionStatus::REFUNDED);

    // Failed maps onto the CANCELED transaction status.
    $this->patchJson('/api/v1/admin/orders/AD05-PAY/payment-status', [
        'payment_status' => 'failed',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    expect($order->transactions()->first()->status)->toBe(TransactionStatus::CANCELED);

    // Unpaid clears every recorded leg, not just the latest one.
    ad05Transaction($order, ['reference' => 'AD05-PAY-LEG-2', 'amount' => 500, 'status' => TransactionStatus::CONFIRMED]);

    $this->patchJson('/api/v1/admin/orders/AD05-PAY/payment-status', [
        'payment_status' => 'unpaid',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.order.payment_status', 'unpaid');

    expect($order->transactions()->count())->toBe(0);

    // One audit row per override, carrying the old and new derived states.
    expect(ActivityLog::where('action', 'payment_status_updated')->count())->toBe(4);

    $last = ActivityLog::where('action', 'payment_status_updated')->latest('id')->first();

    expect($last->new_values['payment_status'])->toBe('unpaid');

    // The override is enum-bounded: pending/partial are badge states, not
    // accepted writes.
    $this->patchJson('/api/v1/admin/orders/AD05-PAY/payment-status', [
        'payment_status' => 'pending',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_status');
});

test('deleting an order soft deletes it, keeps its children, audits it and hides it from every read', function () {
    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business);
    $customer = ad05Customer($business->id);

    $order = ad05Order($store, ['order_number' => 'AD05-DEL', 'customer_id' => $customer->id]);
    ad05Item($order);
    ad05Transaction($order);

    $token = ad05AdminToken($admin);

    $this->deleteJson('/api/v1/admin/orders/AD05-DEL', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    $trashed = Order::withTrashed()->where('order_number', 'AD05-DEL')->first();

    expect($trashed)->not->toBeNull()
        ->and($trashed->trashed())->toBeTrue();

    // Soft delete only: nothing cascades.
    expect(OrderItem::where('order_id', $order->id)->count())->toBe(1)
        ->and(Transaction::where('order_id', $order->id)->count())->toBe(1);

    $log = ActivityLog::where('action', 'deleted')->where('subject_id', $order->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['order_number'])->toBe('AD05-DEL');

    $list = $this->getJson('/api/v1/admin/orders', ['Authorization' => 'Bearer '.$token]);
    expect(collect($list->json('data.orders'))->pluck('order_number'))->not->toContain('AD05-DEL');

    $this->getJson('/api/v1/admin/orders/AD05-DEL', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(404);
});

test('the shop4me queue lists only shop4me orders with its own stats and filters', function () {
    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business);
    $customer = ad05Customer($business->id);

    $shop4me = ad05Order($store, [
        'order_number' => 'AD05-QUEUE',
        'customer_id' => $customer->id,
        'source' => 'shop4me',
        'status' => 'pending',
    ]);
    ad05Transaction($shop4me, ['amount' => 1000, 'status' => TransactionStatus::CONFIRMED]);

    ad05Order($store, [
        'order_number' => 'AD05-NOT-QUEUE',
        'customer_id' => $customer->id,
        'source' => 'checkout',
    ]);

    $token = ad05AdminToken($admin);

    $response = $this->getJson('/api/v1/admin/shop4me-orders', ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.stats.total', 1)
        ->assertJsonPath('meta.stats.pending', 1)
        ->assertJsonPath('meta.stats.revenue', 1000);

    expect(collect($response->json('data.orders'))->pluck('order_number')->all())->toBe(['AD05-QUEUE'])
        ->and($response->json('data.orders.0.is_shop4me'))->toBeTrue()
        // The legacy Shop4Me badge compared enums to strings and rendered
        // "Unpaid" for every row; the derived badge is accurate now.
        ->and($response->json('data.orders.0.payment_status'))->toBe('paid');

    // The main list also accepts the source filter.
    $main = $this->getJson('/api/v1/admin/orders?source=shop4me', ['Authorization' => 'Bearer '.$token]);
    $main->assertOk();
    expect(collect($main->json('data.orders'))->pluck('order_number')->all())->toBe(['AD05-QUEUE']);

    // An unknown store on the queue filter is validated, not trusted.
    $this->getJson('/api/v1/admin/shop4me-orders?store_id=999999', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');
});

test('a transaction status can be overridden with enum validation and an audit row', function () {
    $admin = ad05SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad05Store($owner, $business);
    $customer = ad05Customer($business->id);

    $order = ad05Order($store, ['order_number' => 'AD05-TXN-ORDER', 'customer_id' => $customer->id]);
    $transaction = ad05Transaction($order, ['status' => TransactionStatus::PENDING, 'paid_at' => null]);

    $token = ad05AdminToken($admin);

    $this->patchJson('/api/v1/admin/transactions/'.$transaction->reference.'/status', [
        'status' => 'confirmed',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'confirmed')
        ->assertJsonPath('data.transaction.order', 'AD05-TXN-ORDER')
        ->assertJsonPath('data.transaction.reference', $transaction->reference);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);

    $log = ActivityLog::where('action', 'transaction_status_updated')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['status'])->toBe('pending')
        ->and($log->new_values['status'])->toBe('confirmed')
        ->and($log->subject_id)->toBe($transaction->id);

    $this->patchJson('/api/v1/admin/transactions/'.$transaction->reference.'/status', [
        'status' => 'failed',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('platform order oversight refuses non-platform, unpermitted and guest callers', function () {
    // A business-scoped account whose in-business "Super Admin" role bundles
    // the admin.* permission names still cannot read platform-wide orders.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.orders'))->toBeTrue();

    $this->getJson('/api/v1/admin/orders', [
        'Authorization' => 'Bearer '.ad05AdminToken($owner),
    ])->assertStatus(403);

    $this->getJson('/api/v1/admin/shop4me-orders', [
        'Authorization' => 'Bearer '.ad05AdminToken($owner),
    ])->assertStatus(403);

    // An admin account without the permission is stopped by the gate.
    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $this->getJson('/api/v1/admin/orders', [
        'Authorization' => 'Bearer '.ad05AdminToken($plainAdmin),
    ])->assertStatus(403);

    // A management-audience token never reaches an admin route.
    [$otherOwner] = createBusinessOwner();
    $managementToken = $otherOwner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/orders', ['Authorization' => 'Bearer '.$managementToken])
        ->assertStatus(403);

    // Guests are unauthenticated.
    $this->getJson('/api/v1/admin/orders')->assertStatus(401);
    $this->getJson('/api/v1/admin/shop4me-orders')->assertStatus(401);

    // A platform admin with the seeded role passes.
    $platformAdmin = ad05PlatformAdmin();

    $this->getJson('/api/v1/admin/orders', [
        'Authorization' => 'Bearer '.ad05AdminToken($platformAdmin),
    ])->assertOk();
});
