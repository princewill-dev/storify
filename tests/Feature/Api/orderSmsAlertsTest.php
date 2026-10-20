<?php

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\KycPhoneVerification;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sms\OrderAlertService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Transactional SMS — order, invoice and status alerts
|--------------------------------------------------------------------------
| Two things are being proved here. The first is content: the right person is
| texted at the right moment, and POS sales, replays and the customer's second
| order stay silent. The second is containment: Termii's answer never changes
| what the settlement recorded. The confirm endpoint is used wherever a money
| path exists, so the hook is exercised rather than merely the service.
|
| Helpers are local to this file because Pest runs filtered files in isolation.
*/

function smsAlertToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function smsAlertStore(User $owner, int $businessId, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $businessId,
        'name' => 'Alert Store',
        'slug' => 'alert-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function smsAlertCustomer(int $businessId, array $attributes = []): Customer
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

function smsAlertOrder(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'ALERT-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function smsAlertInvoice(Store $store, array $attributes = []): Invoice
{
    static $sequence = 0;
    $sequence++;

    return Invoice::create(array_merge([
        'invoice_number' => 'ALERT-INV-'.$sequence,
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

function smsAlertTransaction(int $businessId, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'ALERT-TXN-'.$sequence,
        'business_id' => $businessId,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::PENDING,
        'paid_at' => now(),
    ], $attributes));
}

function smsAlertConfirm(object $test, User $owner, Transaction $transaction)
{
    return $test->withToken(smsAlertToken($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/confirm')
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'confirmed');
}

beforeEach(function () {
    config([
        'services.termii.api_key' => 'test-termii-key',
        'services.termii.base_url' => 'https://api.ng.termii.com',
        'services.termii.sender_id' => 'OE Alert',
        'services.termii.channel' => 'dnd',
        'services.termii.default_country_code' => '234',
        'services.termii.timeout_ms' => 10000,
    ]);

    // A fresh factory per test: stubs merge in registration order, so an
    // earlier fake would otherwise answer for this one.
    Http::swap(new Factory);
    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message_id' => 'termii-message-1'], 200),
    ]);
});

test('a confirmed order texts the owner once, and a replay sends nothing', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => '08030000001',
    ]);
    $store = smsAlertStore($owner, $business->id);
    // This test is about the owner's copy; the customer's first-order message
    // is exercised on its own below.
    $customer = smsAlertCustomer($business->id);
    $order = smsAlertOrder($store, ['customer_id' => $customer->id]);
    $transaction = smsAlertTransaction($business->id, ['order_id' => $order->id]);

    smsAlertConfirm($this, $owner, $transaction);

    $sms = SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_PAID_OWNER)->sole();

    expect($sms->status)->toBe(SmsMessage::STATUS_SENT)
        ->and($sms->provider_message_id)->toBe('termii-message-1')
        ->and($sms->phone)->toBe('+2348030000001')
        ->and($sms->user_id)->toBe($owner->id)
        ->and($sms->sent_at)->not->toBeNull()
        ->and($sms->message)->toContain($order->order_number);

    // A webhook redelivery reaches the same alert again. The dedupe key is
    // the guarantee, not the controller's own status guard.
    app(OrderAlertService::class)->orderPaid($transaction->fresh());

    // Owner plus the customer's first-order acknowledgement, and no more.
    Http::assertSentCount(2);
    expect(SmsMessage::query()->count())->toBe(2);
});

test('the customer is thanked for their first paid order and not the second', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => '08030000001',
    ]);
    $store = smsAlertStore($owner, $business->id);
    $customer = smsAlertCustomer($business->id);

    $first = smsAlertOrder($store, ['customer_id' => $customer->id]);
    smsAlertConfirm($this, $owner, smsAlertTransaction($business->id, ['order_id' => $first->id]));

    $firstCustomerSms = SmsMessage::query()
        ->where('type', SmsMessage::TYPE_ORDER_PAID_CUSTOMER_FIRST)
        ->sole();

    expect($firstCustomerSms->customer_id)->toBe($customer->id)
        ->and($firstCustomerSms->phone)->toBe('+2348011112222')
        ->and($firstCustomerSms->message)->toContain($first->order_number);

    $second = smsAlertOrder($store, ['customer_id' => $customer->id]);
    smsAlertConfirm($this, $owner, smsAlertTransaction($business->id, ['order_id' => $second->id]));

    // The owner hears about both; the "thank you" is a first-order fact.
    expect(SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_PAID_OWNER)->count())->toBe(2)
        ->and(SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_PAID_CUSTOMER_FIRST)->count())->toBe(1);
});

test('a POS sale never sends an SMS', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => '08030000001',
    ]);
    $store = smsAlertStore($owner, $business->id);
    $customer = smsAlertCustomer($business->id);
    $order = smsAlertOrder($store, [
        'customer_id' => $customer->id,
        'source' => 'pos',
        'amount_paid' => 1000,
        'status' => 'completed',
    ]);
    $transaction = smsAlertTransaction($business->id, [
        'order_id' => $order->id,
        'status' => TransactionStatus::CONFIRMED,
    ]);

    app(OrderAlertService::class)->orderPaid($transaction);
    app(OrderAlertService::class)->orderStatusChanged($order, 'accepted', 'completed');

    Http::assertNothingSent();
    expect(SmsMessage::query()->count())->toBe(0);
});

test('a confirmed invoice texts the owner once, and a replay sends nothing', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => '08030000001',
    ]);
    $store = smsAlertStore($owner, $business->id);
    $invoice = smsAlertInvoice($store, ['invoice_number' => 'ALERT-INV-900', 'total' => 2500]);
    $transaction = smsAlertTransaction($business->id, [
        'invoice_id' => $invoice->id,
        'amount' => 2500,
    ]);

    smsAlertConfirm($this, $owner, $transaction);

    $sms = SmsMessage::query()->where('type', SmsMessage::TYPE_INVOICE_PAID_OWNER)->sole();

    expect($sms->phone)->toBe('+2348030000001')
        ->and($sms->message)->toContain('ALERT-INV-900');

    app(OrderAlertService::class)->invoicePaid($transaction->fresh());

    Http::assertSentCount(1);
    expect(SmsMessage::query()->count())->toBe(1);
});

test('a status change texts the customer once and skips uninteresting transitions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = smsAlertStore($owner, $business->id);
    $customer = smsAlertCustomer($business->id);
    $order = smsAlertOrder($store, ['customer_id' => $customer->id, 'status' => 'accepted']);

    $alerts = app(OrderAlertService::class);

    $alerts->orderStatusChanged($order, 'accepted', 'processing');

    $sms = SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_STATUS_CUSTOMER)->sole();

    expect($sms->customer_id)->toBe($customer->id)
        ->and($sms->phone)->toBe('+2348011112222')
        ->and($sms->message)->toContain($order->order_number);

    // The same status twice is not worth a second segment.
    $alerts->orderStatusChanged($order, 'processing', 'processing');

    // `accepted` is written by a successful payment, and `pending` is birth;
    // the first-order acknowledgement already covers the former.
    $alerts->orderStatusChanged($order, 'processing', 'accepted');
    $alerts->orderStatusChanged($order, 'accepted', 'pending');

    expect(SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_STATUS_CUSTOMER)->count())->toBe(1);

    $posOrder = smsAlertOrder($store, [
        'customer_id' => $customer->id,
        'source' => 'pos',
        'status' => 'completed',
    ]);

    $alerts->orderStatusChanged($posOrder, 'accepted', 'completed');

    expect(SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_STATUS_CUSTOMER)->count())->toBe(1);
});

test('an owner with no phone falls back to the KYC number, and with neither is skipped quietly', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => null,
    ]);
    $store = smsAlertStore($owner, $business->id);
    $customer = smsAlertCustomer($business->id);
    $order = smsAlertOrder($store, ['customer_id' => $customer->id]);
    $transaction = smsAlertTransaction($business->id, ['order_id' => $order->id]);

    verifyKycPhone($owner, '08039998888');

    app(OrderAlertService::class)->orderPaid($transaction);

    expect(SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_PAID_OWNER)->sole()->phone)
        ->toBe('+2348039998888');

    // No account phone and no proved number: nothing to send, and nothing to
    // fail either. A second order is needed because the first one's dedupe key
    // is already spent.
    $owner->update(['phone' => null]);
    KycPhoneVerification::query()->delete();

    $second = smsAlertOrder($store, ['customer_id' => $customer->id]);
    $secondTransaction = smsAlertTransaction($business->id, ['order_id' => $second->id]);

    app(OrderAlertService::class)->orderPaid($secondTransaction);

    // Only the first order's owner text: these orders were never settled, so
    // the first-order acknowledgement does not apply. The second sends nothing.
    Http::assertSentCount(1);
    expect(SmsMessage::query()->count())->toBe(1);
});

test('a Termii outage leaves the payment confirmed and records the failure', function () {
    Http::swap(new Factory);
    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message' => 'provider unavailable'], 500),
    ]);

    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => '08030000001',
    ]);
    $store = smsAlertStore($owner, $business->id);
    $customer = smsAlertCustomer($business->id);
    $order = smsAlertOrder($store, ['customer_id' => $customer->id]);
    $transaction = smsAlertTransaction($business->id, ['order_id' => $order->id]);

    smsAlertConfirm($this, $owner, $transaction);

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::CONFIRMED)
        ->and((float) $order->fresh()->amount_paid)->toBe(1000.0)
        ->and($order->fresh()->status->value)->toBe('accepted');

    $sms = SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_PAID_OWNER)->sole();

    expect($sms->status)->toBe(SmsMessage::STATUS_FAILED)
        ->and($sms->error)->not->toBeNull()
        ->and($sms->sent_at)->toBeNull();
});

test('an order paid outside the management endpoint is still texted to the owner', function () {
    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'phone' => '08030000001',
    ]);
    $store = smsAlertStore($owner, $business->id);
    $customer = smsAlertCustomer($business->id);
    $order = smsAlertOrder($store, [
        'customer_id' => $customer->id,
        'amount_paid' => 1000,
        'status' => 'accepted',
    ]);
    $transaction = smsAlertTransaction($business->id, [
        'order_id' => $order->id,
        'status' => TransactionStatus::CONFIRMED,
    ]);

    // What the storefront verify and webhook paths call after their own
    // settlement transaction closes.
    app(OrderAlertService::class)->orderPaid($transaction);

    expect(SmsMessage::query()->where('type', SmsMessage::TYPE_ORDER_PAID_OWNER)->count())->toBe(1);
});
