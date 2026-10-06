<?php

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
 * Whole-naira money assertions below use ints, not floats: PHP's json_encode
 * drops the zero fraction (205.0 encodes as `205`) and assertJsonPath compares
 * strictly, so the API's float naira values arrive as JSON integers.
 */

function ws21Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws21Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'main-store-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws21Invoice(User $owner, array $attributes = [], array $items = []): Invoice
{
    $invoice = Invoice::create(array_merge([
        'business_id' => $owner->business_id,
        'user_id' => $owner->id,
        'recipient_name' => 'Ada Client',
        'recipient_email' => 'ada@example.com',
        'status' => InvoiceStatus::DRAFT,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(14)->toDateString(),
        'subtotal' => 1000,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 1000,
    ], $attributes));

    if ($items === []) {
        $items = [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]];
    }

    foreach ($items as $index => $item) {
        $invoice->items()->create(array_merge(['sort_order' => $index], $item));
    }

    return $invoice;
}

test('the invoice list returns the legacy stats row and filters by status and search', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws21Invoice($owner, ['status' => InvoiceStatus::DRAFT]);
    ws21Invoice($owner, ['status' => InvoiceStatus::SENT, 'recipient_name' => 'Bola Buyer']);
    ws21Invoice($owner, [
        'status' => InvoiceStatus::PAID,
        'recipient_name' => 'Chidi Payer',
        'total' => 500,
        'amount_paid' => 500,
    ]);

    $response = $this->withToken(ws21Token($owner))->getJson('/api/v1/management/invoices');

    $response->assertOk()
        ->assertJsonPath('data.stats.all', 3)
        ->assertJsonPath('data.stats.draft', 1)
        ->assertJsonPath('data.stats.sent', 1)
        ->assertJsonPath('data.stats.paid', 1)
        ->assertJsonPath('data.stats.overdue', 0)
        ->assertJsonPath('data.stats.revenue', 500)
        ->assertJsonCount(3, 'data.invoices');

    // Status tab.
    $this->withToken(ws21Token($owner))
        ->getJson('/api/v1/management/invoices?status=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.status', 'draft');

    // Free-text search covers recipient name (legacy also searched number,
    // email and customer full name).
    $this->withToken(ws21Token($owner))
        ->getJson('/api/v1/management/invoices?q=Bola')
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.recipient_name', 'Bola Buyer');
});

test('the invoice list is scoped to the authenticated business', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws21Invoice($owner, ['recipient_name' => 'Mine']);
    ws21Invoice($otherOwner, ['recipient_name' => 'Theirs']);

    $response = $this->withToken(ws21Token($owner))->getJson('/api/v1/management/invoices');

    $response->assertOk()->assertJsonCount(1, 'data.invoices');
    expect($response->json('data.invoices.0.recipient_name'))->toBe('Mine');
});

test('invoice totals are recomputed server side and client totals are ignored', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $response = $this->withToken(ws21Token($owner))->postJson('/api/v1/management/invoices', [
        'recipient_name' => 'Ada Client',
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-15',
        'tax_rate' => 7.5,
        'discount_type' => 'fixed',
        'discount_value' => 10,
        'subtotal' => 999999,
        'total' => 1,
        'items' => [
            ['description' => 'Design work', 'quantity' => 2, 'unit_price' => 100],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.invoice.status', 'draft')
        ->assertJsonPath('data.invoice.subtotal', 200)
        ->assertJsonPath('data.invoice.tax_amount', 15)
        ->assertJsonPath('data.invoice.total', 205)
        ->assertJsonPath('data.invoice.items.0.amount', 200);
});

test('a percentage discount is computed from the subtotal and clamped at zero', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $percent = $this->withToken(ws21Token($owner))->postJson('/api/v1/management/invoices', [
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-15',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'items' => [['description' => 'Retainer', 'quantity' => 4, 'unit_price' => 25]],
    ]);

    $percent->assertCreated()
        ->assertJsonPath('data.invoice.total', 90)
        ->assertJsonPath('data.invoice.discount_amount', 10);

    $clamped = $this->withToken(ws21Token($owner))->postJson('/api/v1/management/invoices', [
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-15',
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'items' => [['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 100]],
    ]);

    $clamped->assertCreated()->assertJsonPath('data.invoice.total', 0);
});

test('an invoice rejects a store or customer from another business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirStore = ws21Store($otherOwner);
    $theirCustomer = Customer::create([
        'business_id' => $otherBusiness->id,
        'first_name' => 'Theirs',
        'last_name' => 'Client',
        'email' => 'theirs@example.com',
        // `phone` and `password` are NOT NULL on `customers`.
        'phone' => '08030000001',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);

    $payload = [
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-15',
        'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]],
    ];

    $this->withToken(ws21Token($owner))
        ->postJson('/api/v1/management/invoices', [...$payload, 'store_id' => $theirStore->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');

    $this->withToken(ws21Token($owner))
        ->postJson('/api/v1/management/invoices', [...$payload, 'customer_id' => $theirCustomer->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer_id');

    // The business's own store still works.
    $ownStore = ws21Store($owner);

    $this->withToken(ws21Token($owner))
        ->postJson('/api/v1/management/invoices', [...$payload, 'store_id' => $ownStore->id])
        ->assertCreated();

    expect($business->id)->not->toBe($otherBusiness->id);
});

test('due date cannot precede the issue date', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws21Token($owner))
        ->postJson('/api/v1/management/invoices', [
            'issue_date' => '2026-10-15',
            'due_date' => '2026-10-01',
            'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('due_date');
});

test('at least one line item is required', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws21Token($owner))
        ->postJson('/api/v1/management/invoices', [
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'items' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');
});

test('save as customer creates and links the recipient', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $response = $this->withToken(ws21Token($owner))->postJson('/api/v1/management/invoices', [
        'recipient_name' => 'Grace Okafor',
        'recipient_email' => 'grace@example.com',
        'recipient_phone' => '08030000000',
        'save_customer' => true,
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-15',
        'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]],
    ]);

    $response->assertCreated()->assertJsonPath('data.invoice.customer.email', 'grace@example.com');

    expect(Customer::where('business_id', $business->id)->where('email', 'grace@example.com')->count())->toBe(1);
});

test('finalizing on create marks the invoice sent and queues the mail without ledger posting', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws21Store($owner);

    $response = $this->withToken(ws21Token($owner))->postJson('/api/v1/management/invoices', [
        'store_id' => $store->id,
        'recipient_name' => 'Ada Client',
        'recipient_email' => 'ada@example.com',
        'finalize' => true,
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-15',
        'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 1000]],
    ]);

    $response->assertCreated()->assertJsonPath('data.invoice.status', 'sent');

    $invoice = Invoice::firstWhere('business_id', $business->id);

    expect($invoice->payment_token)->toHaveLength(32)
        ->and($invoice->sent_at)->not->toBeNull();

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo('ada@example.com')
        && str_contains((string) $mail->paymentUrl, $invoice->payment_token));

    // The verify pass asked for the legacy asymmetry: "Save & Send" mails but
    // does not post the ledger; the explicit send endpoint does.
    expect(JournalEntry::where('business_id', $business->id)->count())->toBe(0);
});

test('a sent invoice cannot be edited or deleted', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $invoice = ws21Invoice($owner, ['status' => InvoiceStatus::SENT]);

    $this->withToken(ws21Token($owner))
        ->putJson("/api/v1/management/invoices/{$invoice->invoice_number}", [
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'items' => [['description' => 'Changed', 'quantity' => 1, 'unit_price' => 50]],
        ])
        ->assertStatus(403);

    $this->withToken(ws21Token($owner))
        ->deleteJson("/api/v1/management/invoices/{$invoice->invoice_number}")
        ->assertStatus(403);

    expect($invoice->fresh())->not->toBeNull();
});

test('a draft invoice can be updated and its items replaced', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $invoice = ws21Invoice($owner);

    $this->withToken(ws21Token($owner))
        ->putJson("/api/v1/management/invoices/{$invoice->invoice_number}", [
            'recipient_name' => 'Updated Client',
            'issue_date' => '2026-10-02',
            'due_date' => '2026-10-20',
            'items' => [
                ['description' => 'New line A', 'quantity' => 2, 'unit_price' => 50],
                ['description' => 'New line B', 'quantity' => 1, 'unit_price' => 25],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.invoice.total', 125)
        ->assertJsonPath('data.invoice.recipient_name', 'Updated Client')
        ->assertJsonCount(2, 'data.invoice.items');
});

test('sending an invoice issues a payment token, mails the link and posts the ledger', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws21Store($owner);
    $invoice = ws21Invoice($owner, ['store_id' => $store->id, 'status' => InvoiceStatus::DRAFT]);

    $response = $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/send");

    $response->assertOk()
        ->assertJsonPath('data.invoice.status', 'sent');

    $invoice->refresh();

    expect($invoice->payment_token)->toHaveLength(32)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($response->json('data.invoice.payment_url'))->toContain($invoice->payment_token);

    Mail::assertQueued(InvoiceMail::class);

    // Verify-pass correction: send() posts the invoice to the ledger.
    expect(JournalEntry::where('business_id', $business->id)
        ->where('reference', $invoice->invoice_number)
        ->exists())->toBeTrue();
});

test('a paid or void invoice cannot be sent', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $paid = ws21Invoice($owner, ['status' => InvoiceStatus::PAID, 'amount_paid' => 1000, 'paid_at' => now()]);
    $void = ws21Invoice($owner, ['status' => InvoiceStatus::VOID, 'voided_at' => now()]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$paid->invoice_number}/send")
        ->assertStatus(422);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$void->invoice_number}/send")
        ->assertStatus(422);
});

test('marking fully paid creates a confirmed payment and credits the store balance', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws21Store($owner);
    $invoice = ws21Invoice($owner, ['store_id' => $store->id, 'status' => InvoiceStatus::SENT, 'total' => 1000]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/mark-paid")
        ->assertOk()
        ->assertJsonPath('data.invoice.status', 'paid')
        ->assertJsonPath('data.invoice.remaining', 0);

    $invoice->refresh();

    expect((float) $invoice->amount_paid)->toBe(1000.0)
        ->and($invoice->paid_at)->not->toBeNull();

    $transaction = Transaction::where('invoice_id', $invoice->id)->firstOrFail();

    expect($transaction->reference)->toStartWith('PMT-')
        ->and($transaction->status->value)->toBe('confirmed')
        ->and($transaction->metadata['source'])->toBe('mark_paid')
        ->and((int) $store->fresh()->balance)->toBe(100000)
        ->and((int) $transaction->store_balance_before)->toBe(0)
        ->and((int) $transaction->store_balance_after)->toBe(100000);

    // Ledger: invoice posted + payment received.
    expect(JournalEntry::where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(2);
});

test('marking paid refuses an invoice that is already settled', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $invoice = ws21Invoice($owner, ['status' => InvoiceStatus::PAID, 'amount_paid' => 1000, 'paid_at' => now()]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/mark-paid")
        ->assertStatus(422);
});

test('recording a partial payment requires the correct password', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek(), 'password' => bcrypt('secret-pass')]);
    $store = ws21Store($owner);
    $invoice = ws21Invoice($owner, ['store_id' => $store->id, 'status' => InvoiceStatus::SENT, 'total' => 1000]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/record-payment", [
            'amount' => 400,
            'payment_method' => 'bank_transfer',
            'password' => 'wrong-pass',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/record-payment", [
            'amount' => 400,
            'payment_method' => 'bank_transfer',
            'password' => 'secret-pass',
            'note' => 'First instalment',
        ])
        ->assertOk()
        ->assertJsonPath('data.invoice.status', 'partial')
        ->assertJsonPath('data.invoice.amount_paid', 400)
        ->assertJsonPath('data.invoice.remaining', 600);

    expect((int) $store->fresh()->balance)->toBe(40000);
});

test('recording payment refuses more than the remaining balance with the legacy message', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek(), 'password' => bcrypt('secret-pass')]);
    $invoice = ws21Invoice($owner, ['status' => InvoiceStatus::SENT, 'total' => 1000]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/record-payment", [
            'amount' => 1500,
            'payment_method' => 'check',
            'password' => 'secret-pass',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.amount.0', 'Amount cannot exceed the remaining balance of ₦1,000.00.');
});

test('recording a payment that settles the invoice marks it paid', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek(), 'password' => bcrypt('secret-pass')]);
    $invoice = ws21Invoice($owner, ['status' => InvoiceStatus::PARTIAL, 'amount_paid' => 600, 'total' => 1000]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$invoice->invoice_number}/record-payment", [
            'amount' => 400,
            'payment_method' => 'gateway',
            'password' => 'secret-pass',
        ])
        ->assertOk()
        ->assertJsonPath('data.invoice.status', 'paid');

    expect((float) $invoice->fresh()->amount_paid)->toBe(1000.0);
});

test('voiding stamps voided_at and a paid invoice cannot be voided', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $sent = ws21Invoice($owner, ['status' => InvoiceStatus::SENT]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$sent->invoice_number}/void")
        ->assertOk()
        ->assertJsonPath('data.invoice.status', 'void');

    expect($sent->fresh()->voided_at)->not->toBeNull();

    $paid = ws21Invoice($owner, ['status' => InvoiceStatus::PAID, 'amount_paid' => 1000, 'paid_at' => now()]);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$paid->invoice_number}/void")
        ->assertStatus(422);
});

test('a draft invoice can be deleted but the number disappears only for the business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $invoice = ws21Invoice($owner, ['status' => InvoiceStatus::DRAFT]);

    $this->withToken(ws21Token($owner))
        ->deleteJson("/api/v1/management/invoices/{$invoice->invoice_number}")
        ->assertOk();

    expect(Invoice::where('business_id', $business->id)->count())->toBe(0)
        ->and(Invoice::withTrashed()->where('id', $invoice->id)->exists())->toBeTrue();
});

test('the invoice pdf downloads the print artefact', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws21Store($owner);
    $invoice = ws21Invoice($owner, ['store_id' => $store->id, 'status' => InvoiceStatus::SENT]);

    $response = $this->withToken(ws21Token($owner))
        ->get("/api/v1/management/invoices/{$invoice->invoice_number}/pdf");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('another business cannot read, edit, send or settle an invoice', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek(), 'password' => bcrypt('secret-pass')]);

    $theirs = ws21Invoice($otherOwner, ['status' => InvoiceStatus::SENT]);

    $this->withToken(ws21Token($owner))
        ->getJson("/api/v1/management/invoices/{$theirs->invoice_number}")
        ->assertStatus(403);

    $this->withToken(ws21Token($owner))
        ->putJson("/api/v1/management/invoices/{$theirs->invoice_number}", [
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'items' => [['description' => 'Hijack', 'quantity' => 1, 'unit_price' => 1]],
        ])
        ->assertStatus(403);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$theirs->invoice_number}/send")
        ->assertStatus(403);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$theirs->invoice_number}/mark-paid")
        ->assertStatus(403);

    $this->withToken(ws21Token($owner))
        ->postJson("/api/v1/management/invoices/{$theirs->invoice_number}/record-payment", [
            'amount' => 10,
            'payment_method' => 'check',
            'password' => 'secret-pass',
        ])
        ->assertStatus(403);

    $this->withToken(ws21Token($owner))
        ->getJson("/api/v1/management/invoices/{$theirs->invoice_number}/pdf")
        ->assertStatus(403);
});

test('the form options endpoint returns the business customers and stores only', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws21Store($owner);

    Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Mine',
        'last_name' => 'Client',
        'email' => 'mine@example.com',
        // `phone` and `password` are NOT NULL on `customers`.
        'phone' => '08030000002',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);

    Customer::create([
        'business_id' => $otherBusiness->id,
        'first_name' => 'Theirs',
        'last_name' => 'Client',
        'email' => 'theirs@example.com',
        'phone' => '08030000003',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);

    $response = $this->withToken(ws21Token($owner))->getJson('/api/v1/management/invoices/form-options');

    $response->assertOk()->assertJsonCount(1, 'data.customers')->assertJsonCount(1, 'data.stores');

    expect($response->json('data.customers.0.email'))->toBe('mine@example.com');
    expect($otherOwner->business_id)->not->toBe($business->id);
});

test('staff without invoice permissions are refused', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    $this->withToken(ws21Token($staff))
        ->getJson('/api/v1/management/invoices')
        ->assertStatus(403);

    expect($staff->business_id)->toBe($business->id);
});
