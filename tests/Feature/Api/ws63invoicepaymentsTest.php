<?php

use App\Enums\InvoiceStatus;
use App\Jobs\SettlePayouts;
use App\Mail\InvoiceMail;
use App\Mail\InvoicePaymentReceiptMail;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentPricing;
use App\Support\InvoicePaymentUrl;
use App\Support\Money\ExchangeRateService;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| WS-63 — paying an invoice from the storefront.
|
| The invoice used to be the last payment surface still charging by hand through
| Paystack and crediting `stores.balance` directly. It now goes through the same
| resolver, commission, custody wallet and payout hand-off as checkout, while
| keeping the two facts that make an invoice an invoice: its currency is the
| business's, and a business-level invoice may be paid through any of the
| business's storefronts.
|
| The assertions below are about the money and the doors, not about the Blade
| page that used to render this. That page remains only as the fallback for a
| business with no store at all, and the redirect that sends everyone else to
| the storefront is pinned here.
*/

function ws63Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws63Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ws63-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        // An invoice must stay payable while the shop window is closed, so this
        // is true only where a test is not *about* the closed shop.
        'has_website' => true,
        'payment_mode' => 'manual',
    ], $attributes));
}

function ws63Invoice(User $owner, ?Store $store, array $attributes = [], array $items = []): Invoice
{
    $invoice = Invoice::create(array_merge([
        'business_id' => $owner->business_id,
        'user_id' => $owner->id,
        'store_id' => $store?->id,
        'recipient_name' => 'Ada Client',
        'recipient_email' => 'ada@example.com',
        'status' => InvoiceStatus::SENT,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(14)->toDateString(),
        'subtotal' => 1000,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 1000,
        'payment_token' => Str::random(32),
    ], $attributes));

    if ($items === []) {
        $items = [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => $invoice->total, 'amount' => $invoice->total]];
    }

    foreach ($items as $index => $item) {
        $invoice->items()->create(array_merge(['sort_order' => $index], $item));
    }

    return $invoice;
}

function ws63OwnKeys(Business $business, array $config = []): void
{
    $method = PaymentMethod::where('code', 'paystack')->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => true,
            'config' => json_encode(CredentialCipher::encrypt(
                array_merge(['public_key' => 'pk_live_ws63', 'secret_key' => 'sk_live_ws63_1234567890'], $config),
                PaymentGatewayRegistry::secretKeysFor('paystack'),
            )),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function ws63Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function ws63PlatformPaystack(): void
{
    config([
        'services.paystack.secret_key' => 'sk_live_platform_ws63',
        'services.paystack.public_key' => 'pk_live_platform_ws63',
    ]);

    ws63Settings([
        'system_payment_processor' => 'paystack',
        'platform_commission_percent' => 2.5,
        'platform_commission_max_kobo' => 0,
    ]);
}

function ws63Custody(Business $business): Business
{
    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();

    return $business->fresh();
}

/**
 * The provider's two reads: the single verify, then the list the reference is
 * looked up in. Both report success for whichever reference was asked about.
 */
function ws63PaystackFake(int $amountMinor = 100000, int $feesMinor = 1500): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($amountMinor, $feesMinor) {
        if (str_contains($request->url(), 'transaction/verify')) {
            return Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => $amountMinor,
                    'currency' => 'NGN',
                    'fees' => $feesMinor,
                    'id' => 63001,
                ],
            ]);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response([
            'status' => true,
            'data' => [[
                'status' => 'success',
                'reference' => $query['reference'] ?? '',
                'amount' => $amountMinor,
                'currency' => 'NGN',
                'fees' => $feesMinor,
                'id' => 63001,
            ]],
        ]);
    });
}

function ws63PaystackInitializeFake(): void
{
    Http::swap(new Factory);

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/ws63',
                'reference' => 'ws63_provider_ref',
                'access_code' => 'ws63_access',
            ],
        ]),
    ]);
}

function ws63BankAccount(Business $business, Store $store): StoreBank
{
    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'TEST LTD',
        'is_primary' => true,
        'is_verified' => true,
    ]);

    $store->assignedBanks()->attach($bank->id, ['is_active' => true]);

    return $bank;
}

function ws63AssignOffline(Business $business, Store $store): void
{
    $method = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

    DB::table('store_payment_method')->updateOrInsert(
        ['store_id' => $store->id, 'payment_method_id' => $method->id],
        ['business_id' => $business->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    );
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
    defaultPlanIncludingEveryModule();
});

/* ---------------------------------------------------------------- the document */

test('an invoice renders its document, payment methods and bank accounts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63OwnKeys($business);
    ws63BankAccount($business, $store);

    $invoice = ws63Invoice($owner, $store, [
        'subtotal' => 200,
        'tax_rate' => 7.5,
        'tax_amount' => 15,
        'discount_type' => 'fixed',
        'discount_value' => 10,
        'total' => 205,
    ]);

    $response = $this->getJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}");

    $response->assertOk()
        ->assertJsonPath('data.invoice.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.invoice.status', 'sent')
        ->assertJsonPath('data.invoice.currency', 'NGN')
        ->assertJsonPath('data.invoice.subtotal', 200)
        ->assertJsonPath('data.invoice.tax_amount', 15)
        ->assertJsonPath('data.invoice.discount_amount', 10)
        ->assertJsonPath('data.invoice.total', 205)
        ->assertJsonPath('data.invoice.remaining', 205)
        ->assertJsonPath('data.invoice.recipient_name', 'Ada Client')
        ->assertJsonPath('data.invoice.store.slug', $store->slug)
        ->assertJsonPath('data.payable', true)
        ->assertJsonPath('data.unavailable_reason', null)
        ->assertJsonCount(1, 'data.invoice.items')
        ->assertJsonCount(1, 'data.payment_methods')
        ->assertJsonCount(1, 'data.bank_accounts');

    expect($response->json('data.payment_methods.0.code'))->toBe('paystack')
        ->and($response->json('data.bank_accounts.0.account_number'))->toBe('0123456789');
});

test('a draft, an unknown token, another business and another store all 404', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws63Store($owner);
    $sibling = ws63Store($owner, ['slug' => 'ws63-sibling']);
    $otherStore = ws63Store($otherOwner);
    ws63OwnKeys($business);
    ws63OwnKeys($otherBusiness);

    $draft = ws63Invoice($owner, $store, ['status' => InvoiceStatus::DRAFT]);
    $sent = ws63Invoice($owner, $store);
    $elsewhere = ws63Invoice($owner, $sibling);
    $foreign = ws63Invoice($otherOwner, $otherStore);

    $this->getJson("/api/v1/storefront/{$store->slug}/invoices/{$draft->payment_token}")->assertNotFound();
    $this->getJson("/api/v1/storefront/{$store->slug}/invoices/not-a-token")->assertNotFound();
    $this->getJson("/api/v1/storefront/{$store->slug}/invoices/{$elsewhere->payment_token}")->assertNotFound();
    $this->getJson("/api/v1/storefront/{$store->slug}/invoices/{$foreign->payment_token}")->assertNotFound();
    $this->getJson("/api/v1/storefront/{$store->slug}/invoices/{$sent->payment_token}")->assertOk();
});

test('an invoice stays payable while the shop window is switched off', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner, ['has_website' => false]);
    ws63OwnKeys($business);

    $invoice = ws63Invoice($owner, $store);

    $this->getJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}")
        ->assertOk()
        ->assertJsonPath('data.payable', true);
});

test('a business-level invoice is served by any store of that business and pins the one that charged it', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63OwnKeys($business);
    ws63PaystackInitializeFake();

    $invoice = ws63Invoice($owner, null);
    expect($invoice->store_id)->toBeNull();

    $this->getJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}")->assertOk();

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize", [
        'amount' => 1000,
    ])->assertOk();

    expect((int) $invoice->fresh()->store_id)->toBe((int) $store->id);
});

/* -------------------------------------------------------------- initialize */

test('initialize writes a pending invoice transaction on the store connection', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63OwnKeys($business);
    ws63PaystackInitializeFake();

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);

    $response = $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize", [
        'amount' => 400,
        'callback_url' => 'https://shop.test/invoice/token',
    ])->assertOk();

    $reference = $response->json('data.reference');
    expect($reference)->toStartWith('inv_')
        ->and($response->json('data.mode'))->toBe('redirect')
        ->and($response->json('data.authorization_url'))->toBe('https://checkout.paystack.com/ws63');

    $transaction = Transaction::firstWhere('reference', $reference);

    expect($transaction)->not->toBeNull()
        ->and((int) $transaction->invoice_id)->toBe((int) $invoice->id)
        ->and($transaction->order_id)->toBeNull()
        ->and($transaction->currency)->toBe('NGN')
        ->and((float) $transaction->amount)->toBe(400.0)
        ->and($transaction->platform_fee_kobo)->toBeNull()
        ->and($transaction->metadata['invoice_number'])->toBe($invoice->invoice_number)
        ->and($transaction->metadata['is_partial'])->toBeTrue()
        ->and($transaction->metadata['payment_mode'])->toBe('own_keys')
        // The provider's own id is recorded, because verification is asked
        // about it rather than about the reference we issued.
        ->and($transaction->metadata['provider_reference'])->toBe('ws63_provider_ref');
});

test('initialize refuses amounts above the balance and invoices that are closed', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63OwnKeys($business);
    ws63PaystackInitializeFake();

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);
    $paid = ws63Invoice($owner, $store, [
        'status' => InvoiceStatus::PAID,
        'total' => 500,
        'amount_paid' => 500,
    ]);
    $void = ws63Invoice($owner, $store, ['status' => InvoiceStatus::VOID]);

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize", [
        'amount' => 1000.01,
    ])->assertStatus(422);

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$paid->payment_token}/payments/paystack/initialize")
        ->assertStatus(409);

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$void->payment_token}/payments/paystack/initialize")
        ->assertStatus(409);
});

test('under enforcement an invoice charges on the platform keys and records the commission', function () {
    ws63PlatformPaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63Custody($business);
    ws63OwnKeys($business, ['secret_key' => 'sk_live_business_ws63']);
    ws63PaystackInitializeFake();

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);

    $response = $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize", [
        'amount' => 1000,
    ])->assertOk();

    $transaction = Transaction::firstWhere('reference', $response->json('data.reference'));

    expect($transaction->metadata['payment_mode'])->toBe('platform_managed')
        ->and((int) $transaction->platform_fee_kobo)->toBe(2500);

    // And the charge itself carried the platform's key, not the business's.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'transaction/initialize')
        && $request->hasHeader('Authorization', 'Bearer sk_live_platform_ws63'));
});

/* ------------------------------------------------------------- verification */

test('verifying an own-keys invoice payment advances it and credits the store', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner, ['balance' => 0]);
    ws63OwnKeys($business);
    ws63PaystackInitializeFake();

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);

    $initialized = $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize", [
        'amount' => 400,
    ])->assertOk();

    $reference = $initialized->json('data.reference');
    ws63PaystackFake(40000, 600);

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/verify", [
        'reference' => $reference,
    ])->assertOk()
        ->assertJsonPath('data.fully_paid', false)
        ->assertJsonPath('data.invoice.status', 'partial')
        ->assertJsonPath('data.invoice.amount_paid', 400);

    $transaction = Transaction::firstWhere('reference', $reference);

    expect($transaction->status->value)->toBe('confirmed')
        ->and($transaction->provider_fee_bearer->value)->toBe('business')
        ->and((int) $transaction->fee_kobo)->toBe(600)
        ->and((int) $transaction->net_kobo)->toBe(39400)
        ->and((int) $store->fresh()->balance)->toBe(40000);

    Mail::assertQueued(InvoicePaymentReceiptMail::class, 1);

    // A second arrival — a refresh, or the webhook behind it — is a no-op.
    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/verify", [
        'reference' => $reference,
    ])->assertOk();

    expect((float) $invoice->fresh()->amount_paid)->toBe(400.0)
        ->and((int) $store->fresh()->balance)->toBe(40000);

    Mail::assertQueued(InvoicePaymentReceiptMail::class, 1);
});

test('a full payment marks the invoice paid and records paid_at', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63OwnKeys($business);
    ws63PaystackInitializeFake();

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);

    $reference = $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize", [
        'amount' => 1000,
    ])->json('data.reference');

    ws63PaystackFake();

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/verify", [
        'reference' => $reference,
    ])->assertOk()->assertJsonPath('data.fully_paid', true);

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->paid_at)->not->toBeNull()
        ->and((float) $invoice->amount_paid)->toBe(1000.0)
        ->and($invoice->remainingBalance())->toBe(0.0);
});

test('a managed invoice payment credits the wallet, not the store balance, and dispatches the payout', function () {
    Queue::fake();
    Mail::fake();

    ws63PlatformPaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner, ['balance' => 0]);
    ws63Custody($business);
    ws63PaystackInitializeFake();

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);

    $reference = $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize", [
        'amount' => 1000,
    ])->json('data.reference');

    ws63PaystackFake(100000, 1500);

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/verify", [
        'reference' => $reference,
    ])->assertOk();

    $transaction = Transaction::firstWhere('reference', $reference)->fresh();
    $business->refresh();
    $store->refresh();

    expect($transaction->provider_fee_bearer->value)->toBe('platform')
        // The provider's fee is the platform's cost, so the business's net is
        // the gross less our commission only.
        ->and((int) $transaction->net_kobo)->toBe(97500)
        ->and((int) $store->balance)->toBe(0)
        ->and((int) $business->pending_balance + (int) $store->pending_balance)->toBe(97500);

    Queue::assertPushed(SettlePayouts::class);
});

test('a reference from another invoice cannot be verified here', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63OwnKeys($business);
    ws63PaystackInitializeFake();

    $mine = ws63Invoice($owner, $store);
    $other = ws63Invoice($owner, $store);

    $reference = $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$other->payment_token}/payments/paystack/initialize", [
        'amount' => 1000,
    ])->json('data.reference');

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$mine->payment_token}/payments/paystack/verify", [
        'reference' => $reference,
    ])->assertNotFound();

    expect((float) $mine->fresh()->amount_paid)->toBe(0.0);
});

/* ------------------------------------------------------------------ webhook */

test('a platform webhook settles an invoice payment exactly once', function () {
    Queue::fake();
    Mail::fake();

    ws63PlatformPaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner, ['balance' => 0]);
    ws63Custody($business);

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);
    $method = PaymentMethod::where('code', 'paystack')->firstOrFail();

    $transaction = Transaction::create([
        'business_id' => $business->id,
        'invoice_id' => $invoice->id,
        'payment_method_id' => $method->id,
        'reference' => 'ws63-webhook-ref',
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => 'pending',
        'platform_fee_kobo' => 2500,
        'metadata' => [
            'invoice_id' => $invoice->id,
            'payment_mode' => 'platform_managed',
            'platform_fee_kobo' => 2500,
        ],
    ]);

    ws63PaystackFake(100000, 1500);

    $body = json_encode([
        'event' => 'charge.success',
        'data' => ['reference' => $transaction->reference, 'id' => 63001, 'amount' => 100000, 'currency' => 'NGN'],
    ]);

    $signature = hash_hmac('sha512', $body, 'sk_live_platform_ws63');

    $send = fn () => $this->call('POST', '/webhooks/connect/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $send()->assertJsonPath('status', 'success');

    expect((float) $invoice->fresh()->amount_paid)->toBe(1000.0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($transaction->fresh()->status->value)->toBe('confirmed');

    // A provider redelivery settles nothing a second time, but it still hands
    // the sale to the payout job — that is the net for a settlement dispatch
    // that never made it onto the queue the first time.
    $send()->assertJsonPath('status', 'already processed');

    expect((float) $invoice->fresh()->amount_paid)->toBe(1000.0);
    Mail::assertQueued(InvoicePaymentReceiptMail::class, 1);
    Queue::assertPushed(SettlePayouts::class, 2);
});

/* ------------------------------------------------------------- bank transfer */

test('a bank slip creates a pending invoice transaction and management confirm advances the invoice', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner, ['balance' => 0]);
    ws63AssignOffline($business, $store);
    $bank = ws63BankAccount($business, $store);

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);

    $response = $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/bank-transfer", [
        'amount' => 400,
        'store_bank_id' => $bank->id,
    ])->assertCreated();

    $reference = $response->json('data.transaction.reference');
    $transaction = Transaction::firstWhere('reference', $reference);

    expect($transaction->status->value)->toBe('pending')
        ->and((int) $transaction->invoice_id)->toBe((int) $invoice->id)
        ->and((float) $invoice->fresh()->amount_paid)->toBe(0.0)
        ->and((int) $store->fresh()->balance)->toBe(0);

    $this->withToken(ws63Token($owner))
        ->postJson("/api/v1/management/transactions/{$reference}/confirm")
        ->assertOk();

    expect((float) $invoice->fresh()->amount_paid)->toBe(400.0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PARTIAL)
        ->and((int) $store->fresh()->balance)->toBe(40000);
});

test('bank transfer is refused under a system processor', function () {
    ws63PlatformPaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63Custody($business);
    ws63AssignOffline($business, $store);
    $bank = ws63BankAccount($business, $store);

    $invoice = ws63Invoice($owner, $store);

    $this->postJson("/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/bank-transfer", [
        'amount' => 1000,
        'store_bank_id' => $bank->id,
    ])->assertStatus(422);
});

/* --------------------------------------------------------------------- URLs */

test('the payment link is the storefront route everywhere, and the legacy page redirects to it', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    $invoice = ws63Invoice($owner, $store, ['status' => InvoiceStatus::DRAFT]);

    $response = $this->withToken(ws63Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/send")
        ->assertOk();

    $invoice->refresh();
    $expected = InvoicePaymentUrl::for($invoice);

    expect($expected)->toContain('/invoice/'.$invoice->payment_token)
        ->and($expected)->toContain($store->slug)
        ->and($response->json('data.invoice.payment_url'))->toBe($expected);

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->paymentUrl === $expected);

    // A temporary redirect, so a moved storefront is not cached forever by the
    // customer's browser.
    $this->get('/pay/invoice/'.$invoice->payment_token)
        ->assertStatus(302)
        ->assertRedirect($expected);
});

test('an invoice with no store still falls back to the legacy page', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $invoice = ws63Invoice($owner, null);

    expect(InvoicePaymentUrl::storeFor($invoice))->toBeNull()
        ->and(InvoicePaymentUrl::for($invoice))->toContain('/pay/invoice/'.$invoice->payment_token);
});

/* -------------------------------------------------------------------- parity */

test('checkout and invoices price and net the same sale identically', function () {
    Queue::fake();

    ws63PlatformPaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws63Store($owner);
    ws63Custody($business);
    ws63PaystackInitializeFake();

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'WS63-'.fake()->unique()->numerify('######'),
        'subtotal' => 1000,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    $invoice = ws63Invoice($owner, $store, ['total' => 1000]);

    $orderReference = $this->postJson(
        "/api/v1/storefront/{$store->slug}/payments/paystack/initialize",
        ['order_number' => $order->order_number, 'amount' => 1000],
        storefrontCustomerHeaders($store),
    )->assertOk()->json('data.reference');

    $invoiceReference = $this->postJson(
        "/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/initialize",
        ['amount' => 1000],
    )->assertOk()->json('data.reference');

    ws63PaystackFake(100000, 1500);

    $this->postJson(
        "/api/v1/storefront/{$store->slug}/payments/paystack/verify",
        ['reference' => $orderReference],
        storefrontCustomerHeaders($store),
    )->assertOk();

    $this->postJson(
        "/api/v1/storefront/{$store->slug}/invoices/{$invoice->payment_token}/payments/paystack/verify",
        ['reference' => $invoiceReference],
    )->assertOk();

    $orderTransaction = Transaction::firstWhere('reference', $orderReference)->fresh();
    $invoiceTransaction = Transaction::firstWhere('reference', $invoiceReference)->fresh();

    expect((int) $invoiceTransaction->platform_fee_kobo)->toBe((int) $orderTransaction->platform_fee_kobo)
        ->and((int) $invoiceTransaction->fee_kobo)->toBe((int) $orderTransaction->fee_kobo)
        ->and((int) $invoiceTransaction->net_kobo)->toBe((int) $orderTransaction->net_kobo)
        ->and($invoiceTransaction->provider_fee_bearer)->toBe($orderTransaction->provider_fee_bearer)
        // Sanity on the figure itself: 2.5% of ₦1,000, provider fee on us.
        ->and((int) $invoiceTransaction->platform_fee_kobo)->toBe(2500)
        ->and((int) $invoiceTransaction->net_kobo)->toBe(97500);
});

test('the shared pricing block records a conversion and stays absent without one', function () {
    // The one helper both surfaces call. Pinning it here is what keeps the two
    // checkout and invoice assertions above from being the only statement of
    // the rule — and a converted charge is the case the settlement floor reads.
    $pricing = app(PaymentPricing::class);

    // Same currency: no block, so the row is byte-identical to a payment that
    // never involved conversion, and the settlement floor never engages.
    expect($pricing->block('NGN', 100000, 100000, 'NGN'))->toBe([])
        ->and($pricing->metadata([]))->toBe([]);

    $rates = app(ExchangeRateService::class);
    $block = $pricing->block('NGN', 100000, 7500, 'USD');

    expect($block['store_amount_minor'])->toBe(100000)
        ->and($block['store_currency'])->toBe('NGN')
        ->and($block['charge_amount_minor'])->toBe(7500)
        ->and($block['charge_currency'])->toBe('USD')
        ->and($block['rate'])->toBe($rates->divisorFor('NGN', 'USD'))
        ->and($block)->toHaveKey('rated_at')
        ->and($pricing->metadata($block))->toBe(['pricing' => $block]);
});
