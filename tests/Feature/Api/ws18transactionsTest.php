<?php

use App\Enums\TransactionStatus;
use App\Mail\PaymentConfirmedMail;
use App\Mail\PaymentRejectedMail;
use App\Mail\RefundProcessedMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-18 — Transactions parity & payment emails
|--------------------------------------------------------------------------
*/

function ws18Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws18Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS18 Store',
        'slug' => 'ws18-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws18Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ada-'.Str::lower(Str::random(6)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => 'ACTIVE',
    ], $attributes));
}

function ws18Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'WS18-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ws18Item(Order $order, array $attributes = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'product_name' => 'WS18 Widget',
        'product_code' => 'WS18-SKU',
        'unit_price' => 1000,
        'quantity' => 1,
        'subtotal' => 1000,
        'is_digital' => false,
    ], $attributes));
}

function ws18Invoice(Store $store, array $attributes = []): Invoice
{
    static $sequence = 0;
    $sequence++;

    return Invoice::create(array_merge([
        'invoice_number' => 'WS18-INV-'.$sequence,
        'business_id' => $store->business_id,
        'user_id' => $store->user_id,
        'store_id' => $store->id,
        'recipient_name' => 'Ada Obi',
        'recipient_email' => 'ada@example.test',
        'status' => 'sent',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addWeek()->toDateString(),
        'subtotal' => 1000,
        'tax_amount' => 0,
        'total' => 1000,
        'amount_paid' => 0,
    ], $attributes));
}

function ws18Transaction(array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS18-TXN-'.$sequence,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::PENDING,
        'paid_at' => now(),
    ], $attributes));
}

test('the transaction list carries the legacy store and customer columns and filters', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $storeA = ws18Store($owner, $business, ['name' => 'Lekki Store']);
    $storeB = ws18Store($owner, $business, ['name' => 'Ikeja Store']);
    $customer = ws18Customer($business->id, ['first_name' => 'Ada', 'last_name' => 'Obi']);

    $orderA = ws18Order($storeA, ['order_number' => 'WS18-A', 'customer_id' => $customer->id]);
    $orderB = ws18Order($storeB, ['order_number' => 'WS18-B']);

    $findMe = ws18Transaction([
        'order_id' => $orderA->id,
        'business_id' => $business->id,
        'reference' => 'WS18-FIND-ME',
    ]);
    $findMe->created_at = Carbon::parse('2026-06-10 09:00:00');
    $findMe->save();

    $other = ws18Transaction([
        'order_id' => $orderB->id,
        'business_id' => $business->id,
        'reference' => 'WS18-OTHER',
        'status' => TransactionStatus::CONFIRMED,
    ]);
    $other->created_at = Carbon::parse('2026-06-20 09:00:00');
    $other->save();

    $token = ws18Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/transactions')
        ->assertOk()
        ->assertJsonPath('data.transactions.0.reference', 'WS18-OTHER')
        ->assertJsonPath('data.transactions.0.store', 'Ikeja Store')
        ->assertJsonPath('data.transactions.0.customer', null)
        ->assertJsonPath('data.transactions.1.reference', 'WS18-FIND-ME')
        ->assertJsonPath('data.transactions.1.customer', 'Ada Obi')
        ->assertJsonPath('data.transactions.1.status_label', 'Pending')
        ->assertJsonStructure(['data' => ['transactions', 'stores', 'statuses'], 'meta' => ['total']]);

    // The legacy filter name (`reference`) and the SPA's `q` both work.
    $this->withToken($token)->getJson('/api/v1/management/transactions?reference=FIND-ME')
        ->assertOk()
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.store', 'Lekki Store');

    $this->withToken($token)->getJson('/api/v1/management/transactions?q=OTHER')
        ->assertOk()
        ->assertJsonCount(1, 'data.transactions');

    $this->withToken($token)->getJson('/api/v1/management/transactions?store_id='.$storeA->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.reference', 'WS18-FIND-ME');

    $this->withToken($token)->getJson('/api/v1/management/transactions?status=confirmed')
        ->assertOk()
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.reference', 'WS18-OTHER');

    $this->withToken($token)->getJson('/api/v1/management/transactions?date_from=2026-06-15&date_to=2026-06-30')
        ->assertOk()
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.reference', 'WS18-OTHER');

    // The status list carries labels so the SPA does not print raw values.
    $this->withToken($token)->getJson('/api/v1/management/transactions')
        ->assertJsonFragment(['value' => 'refund_pending', 'label' => 'Refund Pending']);
});

test('an invalid status filter is rejected', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws18Token($owner))
        ->getJson('/api/v1/management/transactions?status=exploded')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('transactions never leak across businesses', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws18Store($owner, $business, ['name' => 'Mine']);
    $theirs = ws18Store($otherOwner, $otherBusiness, ['name' => 'Theirs']);

    $myOrder = ws18Order($mine, ['order_number' => 'WS18-MINE']);
    $theirOrder = ws18Order($theirs, ['order_number' => 'WS18-THEIRS']);

    $myTransaction = ws18Transaction(['order_id' => $myOrder->id, 'business_id' => $business->id]);
    $theirTransaction = ws18Transaction(['order_id' => $theirOrder->id, 'business_id' => $otherBusiness->id]);

    $token = ws18Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/transactions')
        ->assertOk()
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.reference', $myTransaction->reference);

    $this->withToken($token)->getJson('/api/v1/management/transactions/'.$theirTransaction->reference)
        ->assertStatus(403);

    $this->withToken($token)->postJson('/api/v1/management/transactions/'.$theirTransaction->reference.'/confirm')
        ->assertStatus(403);

    expect($theirTransaction->fresh()->status)->toBe(TransactionStatus::PENDING);
});

test('staff assigned to a store only see and act on that store transactions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $assigned = ws18Store($owner, $business, ['name' => 'Assigned']);
    $unassigned = ws18Store($owner, $business, ['name' => 'Unassigned']);

    $assignedOrder = ws18Order($assigned, ['order_number' => 'WS18-ASSIGNED']);
    $unassignedOrder = ws18Order($unassigned, ['order_number' => 'WS18-UNASSIGNED']);

    $visible = ws18Transaction(['order_id' => $assignedOrder->id, 'business_id' => $business->id, 'reference' => 'WS18-VISIBLE']);
    $hidden = ws18Transaction(['order_id' => $unassignedOrder->id, 'business_id' => $business->id, 'reference' => 'WS18-HIDDEN']);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    // The Accountant holds transactions view/confirm/reject/refund, so the
    // request passes the route middleware and the store scoping is what
    // should keep the other store's payment out of reach.
    $staff->assignRole('Accountant');
    $staff->assignedStores()->attach($assigned->id);

    $token = ws18Token($staff);

    $this->withToken($token)->getJson('/api/v1/management/transactions')
        ->assertOk()
        ->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.reference', 'WS18-VISIBLE')
        // The store filter options are scoped like the rows, so the other
        // store is not even offered in the dropdown.
        ->assertJsonCount(1, 'data.stores')
        ->assertJsonPath('data.stores.0.name', 'Assigned');

    $this->withToken($token)->getJson('/api/v1/management/transactions/'.$visible->reference)
        ->assertOk();

    $this->withToken($token)->getJson('/api/v1/management/transactions/'.$hidden->reference)
        ->assertStatus(403);

    $this->withToken($token)->postJson('/api/v1/management/transactions/'.$hidden->reference.'/confirm')
        ->assertStatus(403);

    $this->withToken($token)->getJson('/api/v1/management/transactions/pending/count')
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    expect($hidden->fresh()->status)->toBe(TransactionStatus::PENDING);
});

test('a staff role without transactions view cannot open the transaction list', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business);
    $order = ws18Order($store);
    $transaction = ws18Transaction(['order_id' => $order->id, 'business_id' => $business->id]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws18Token($staff))
        ->getJson('/api/v1/management/transactions')
        ->assertStatus(403);

    $this->withToken(ws18Token($staff))
        ->getJson('/api/v1/management/transactions/'.$transaction->reference)
        ->assertStatus(403);
});

test('transaction detail returns the order context, customer block, slip and balance impact', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws18Store($owner, $business, ['name' => 'Lekki Store', 'balance' => 105000]);
    $customer = ws18Customer($business->id);
    $cashier = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'name' => 'Chidi Cashier',
    ]);

    $method = PaymentMethod::create(['name' => 'Bank Transfer', 'code' => 'bank_transfer']);
    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Storify Ltd',
        'is_primary' => true,
        'is_verified' => true,
    ]);

    $order = ws18Order($store, [
        'order_number' => 'WS18-DETAIL',
        'customer_id' => $customer->id,
        'staff_id' => $cashier->id,
        'source' => 'pos',
        'subtotal' => 900,
        'total' => 1000,
        'service_charge_amount' => 100,
        'meta' => ['service_charge_name' => 'Packaging'],
    ]);
    ws18Item($order);

    $transaction = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-DETAIL-TXN',
        'payment_method_id' => $method->id,
        'store_bank_id' => $bank->id,
        'payment_slip' => 'payment-slips/ws18-proof.png',
        'gateway_reference' => 'PSK-WS18',
        'store_balance_before' => 5000,
        'store_balance_after' => 105000,
    ]);

    $this->withToken(ws18Token($owner))
        ->getJson('/api/v1/management/transactions/'.$transaction->reference)
        ->assertOk()
        ->assertJsonPath('data.transaction.order_context.order_number', 'WS18-DETAIL')
        ->assertJsonPath('data.transaction.order_context.items_count', 1)
        ->assertJsonPath('data.transaction.order_context.service_charge.amount', 100)
        ->assertJsonPath('data.transaction.order_context.service_charge.name', 'Packaging')
        ->assertJsonPath('data.transaction.order_context.source', 'pos')
        ->assertJsonPath('data.transaction.order_context.staff.name', 'Chidi Cashier')
        ->assertJsonPath('data.transaction.order_context.store.name', 'Lekki Store')
        ->assertJsonPath('data.transaction.customer_context.name', 'Ada Obi')
        ->assertJsonPath('data.transaction.customer', 'Ada Obi')
        ->assertJsonPath('data.transaction.store_balance_before', 5000)
        ->assertJsonPath('data.transaction.store_balance_after', 105000)
        ->assertJsonPath('data.transaction.store_balance_current', 105000)
        ->assertJsonPath('data.transaction.payment_slip.is_image', true)
        ->assertJsonPath('data.transaction.bank.bank_name', 'GTBank')
        ->assertJsonPath('data.transaction.bank.is_verified', true)
        ->assertJsonPath('data.transaction.gateway_reference', 'PSK-WS18');
});

test('rejection and refund reasons are returned as structured blocks', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business);
    $order = ws18Order($store, ['order_number' => 'WS18-REASON']);

    $rejected = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-REJECTED',
        'status' => TransactionStatus::CANCELED,
        'metadata' => [
            'rejection_reason' => 'Amount does not match the order total',
            'rejected_at' => '2026-06-11 10:00:00',
            'rejected_by' => $owner->id,
        ],
    ]);

    $this->withToken(ws18Token($owner))
        ->getJson('/api/v1/management/transactions/'.$rejected->reference)
        ->assertOk()
        ->assertJsonPath('data.transaction.rejection.reason', 'Amount does not match the order total')
        ->assertJsonPath('data.transaction.rejection.at', '2026-06-11 10:00:00')
        ->assertJsonPath('data.transaction.rejection.by_name', $owner->name)
        ->assertJsonPath('data.transaction.refund', null);

    $refunded = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-REFUNDED',
        'status' => TransactionStatus::REFUNDED,
        'metadata' => [
            'refund_reason' => 'Customer changed their mind',
            'refunded_at' => '2026-06-12 11:00:00',
            'refunded_by' => $owner->id,
            'refund_balance_before' => 105000,
            'refund_balance_after' => 5000,
        ],
    ]);

    $this->withToken(ws18Token($owner))
        ->getJson('/api/v1/management/transactions/'.$refunded->reference)
        ->assertOk()
        ->assertJsonPath('data.transaction.refund.reason', 'Customer changed their mind')
        ->assertJsonPath('data.transaction.refund.balance_before', 105000)
        ->assertJsonPath('data.transaction.refund.balance_after', 5000);
});

test('confirming a pending payment credits the store and queues the legacy mails', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business, ['balance' => 0]);
    $customer = ws18Customer($business->id);
    $order = ws18Order($store, ['customer_id' => $customer->id, 'total' => 1000, 'amount_paid' => 0]);

    $transaction = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-CONFIRM',
        'amount' => 1000,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/confirm')
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'confirmed');

    expect((int) $store->fresh()->balance)->toBe(100000);
    expect((float) $order->fresh()->amount_paid)->toBe(1000.0);
    expect($order->fresh()->status->value)->toBe('accepted');

    $transaction = $transaction->fresh();
    expect((int) $transaction->store_balance_before)->toBe(0)
        ->and((int) $transaction->store_balance_after)->toBe(100000)
        ->and($transaction->balance_updated_at)->not->toBeNull();

    // Customer + store owner + platform admin.
    Mail::assertQueued(PaymentConfirmedMail::class, 3);
    Mail::assertQueued(PaymentConfirmedMail::class, fn (PaymentConfirmedMail $mail) => $mail->recipient?->email === $customer->email);
});

test('confirming an invoice payment credits its store without order mails', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business, ['balance' => 0]);
    $invoice = ws18Invoice($store, ['invoice_number' => 'WS18-INV-CONFIRM', 'total' => 2500, 'amount_paid' => 0]);

    $transaction = ws18Transaction([
        'invoice_id' => $invoice->id,
        'business_id' => $business->id,
        'reference' => 'WS18-INV-CONFIRM-TXN',
        'amount' => 2500,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/confirm')
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'confirmed')
        ->assertJsonPath('data.transaction.invoice_context.invoice_number', 'WS18-INV-CONFIRM');

    expect((int) $store->fresh()->balance)->toBe(250000);

    // The legacy mail block assumed an order and died on invoice payments.
    Mail::assertNothingQueued();
});

test('only pending transactions can be confirmed', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business);
    $order = ws18Order($store);

    $transaction = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'status' => TransactionStatus::CONFIRMED,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/confirm')
        ->assertStatus(409);

    Mail::assertNothingQueued();
});

test('rejecting a payment takes an optional reason and queues the rejection mails', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business);
    $customer = ws18Customer($business->id);
    $order = ws18Order($store, ['customer_id' => $customer->id]);

    $transaction = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-REJECT',
    ]);

    // No reason at all — the legacy modal marked it optional.
    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/reject')
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'cancelled')
        ->assertJsonPath('data.transaction.rejection.reason', null);

    $transaction = $transaction->fresh();
    expect($transaction->metadata['rejected_by'])->toBe($owner->id)
        ->and($transaction->metadata['rejected_at'])->not->toBeNull();

    Mail::assertQueued(PaymentRejectedMail::class, 3);

    // A too-long reason is still a validation failure.
    $second = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-REJECT-LONG',
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$second->reference.'/reject', [
            'reason' => str_repeat('x', 501),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

test('rejecting an invoice payment short-circuits the emails', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business);
    $invoice = ws18Invoice($store);

    $transaction = ws18Transaction([
        'invoice_id' => $invoice->id,
        'business_id' => $business->id,
        'reference' => 'WS18-INV-REJECT',
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/reject', [
            'reason' => 'Wrong amount transferred',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Invoice payment rejected.');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CANCELED);
    Mail::assertNothingQueued();
});

test('refunding a confirmed payment debits the store and emails the customer', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business, ['balance' => 100000]);
    $customer = ws18Customer($business->id);
    $order = ws18Order($store, ['customer_id' => $customer->id, 'total' => 1000, 'amount_paid' => 1000, 'status' => 'accepted']);

    $transaction = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-REFUND',
        'status' => TransactionStatus::CONFIRMED,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/refund', [
            'reason' => 'Item never arrived',
        ])
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'refunded')
        ->assertJsonPath('data.transaction.refund.reason', 'Item never arrived');

    expect((int) $store->fresh()->balance)->toBe(0);
    expect((float) $order->fresh()->amount_paid)->toBe(0.0);

    $transaction = $transaction->fresh();
    expect((int) $transaction->metadata['refund_balance_before'])->toBe(100000)
        ->and((int) $transaction->metadata['refund_balance_after'])->toBe(0);

    Mail::assertQueued(RefundProcessedMail::class, 1);
});

test('a refund beyond the store balance fails with the legacy friendly message', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business, ['balance' => 500]);
    $order = ws18Order($store, ['total' => 1000, 'amount_paid' => 1000, 'status' => 'accepted']);

    $transaction = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-POOR',
        'status' => TransactionStatus::CONFIRMED,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/refund', [
            'reason' => 'Testing insufficient balance',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Insufficient store balance to process refund. Current balance: ₦5.00');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);
    expect((int) $store->fresh()->balance)->toBe(500);
    Mail::assertNothingQueued();
});

test('refunds refuse delivered orders, invoice payments and missing reasons', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business, ['balance' => 1000000]);

    $delivered = ws18Order($store, ['status' => 'delivered', 'total' => 1000, 'amount_paid' => 1000]);
    $deliveredTransaction = ws18Transaction([
        'order_id' => $delivered->id,
        'business_id' => $business->id,
        'reference' => 'WS18-DELIVERED',
        'status' => TransactionStatus::CONFIRMED,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$deliveredTransaction->reference.'/refund', ['reason' => 'Late'])
        ->assertStatus(409);

    $invoice = ws18Invoice($store);
    $invoiceTransaction = ws18Transaction([
        'invoice_id' => $invoice->id,
        'business_id' => $business->id,
        'reference' => 'WS18-INV-REFUND',
        'status' => TransactionStatus::CONFIRMED,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$invoiceTransaction->reference.'/refund', ['reason' => 'No longer needed'])
        ->assertStatus(422);

    $order = ws18Order($store, ['total' => 1000, 'amount_paid' => 1000, 'status' => 'accepted']);
    $transaction = ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-NO-REASON',
        'status' => TransactionStatus::CONFIRMED,
    ]);

    $this->withToken(ws18Token($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/refund')
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    Mail::assertNothingQueued();
});

test('the pending count feeds the sidebar badge', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business);
    $order = ws18Order($store);
    $invoice = ws18Invoice($store);

    ws18Transaction(['order_id' => $order->id, 'business_id' => $business->id, 'reference' => 'WS18-P1']);
    ws18Transaction(['invoice_id' => $invoice->id, 'business_id' => $business->id, 'reference' => 'WS18-P2']);
    ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-P3',
        'status' => TransactionStatus::CONFIRMED,
    ]);

    // Legacy's badge joined orders only; invoice payments count here too.
    $this->withToken(ws18Token($owner))
        ->getJson('/api/v1/management/transactions/pending/count')
        ->assertOk()
        ->assertJsonPath('data.count', 2);
});

test('the export streams the filtered rows as csv', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws18Store($owner, $business, ['name' => 'Lekki Store']);
    $order = ws18Order($store, ['order_number' => 'WS18-EXPORT-ORDER']);

    ws18Transaction([
        'order_id' => $order->id,
        'business_id' => $business->id,
        'reference' => 'WS18-EXPORT',
    ]);

    $response = $this->withToken(ws18Token($owner))
        ->get('/api/v1/management/transactions/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('Reference')
        ->and($csv)->toContain('WS18-EXPORT')
        ->and($csv)->toContain('Lekki Store');
});

test('an unknown reference is a 404 while the static siblings still resolve', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws18Token($owner))
        ->getJson('/api/v1/management/transactions/DOES-NOT-EXIST')
        ->assertStatus(404);

    // The show binding deliberately excludes `export` so this static route
    // keeps matching even though the binding was registered first.
    $this->withToken(ws18Token($owner))
        ->get('/api/v1/management/transactions/export')
        ->assertOk();
});
