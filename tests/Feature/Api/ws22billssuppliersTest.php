<?php

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\LedgerSetupService;

/*
|--------------------------------------------------------------------------
| WS-22 — Accounting: Suppliers & Bills
|--------------------------------------------------------------------------
*/

function ws22Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/** Bootstrap the default chart of accounts + fiscal periods for a business. */
function ws22EnsureBooks(int $businessId): void
{
    app(LedgerSetupService::class)->ensureForBusiness($businessId);
}

function ws22Supplier(int $businessId, array $attributes = []): Supplier
{
    return Supplier::create(array_merge([
        'business_id' => $businessId,
        'name' => 'Acme Supplies',
        'is_active' => true,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ws22Bill(int $businessId, int $supplierId, array $attributes = []): Bill
{
    $total = (int) ($attributes['total_kobo'] ?? 100000);

    return Bill::create(array_merge([
        'business_id' => $businessId,
        'supplier_id' => $supplierId,
        'bill_number' => 'BILL-'.strtoupper(Str::random(6)),
        'issue_date' => now()->toDateString(),
        'subtotal_kobo' => $total,
        'tax_kobo' => 0,
        'total_kobo' => $total,
        'amount_paid_kobo' => 0,
        'status' => Bill::STATUS_OPEN,
    ], $attributes));
}

/**
 * A product with optional stock on hand, which is what weighted-average
 * costing mixes a receipt against.
 *
 * @param  array<string, mixed>  $attributes
 */
function ws22Product(int $businessId, User $owner, array $attributes = [], int $onHand = 0): Product
{
    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $businessId,
        'name' => 'WS22 Store',
        'slug' => 'ws22-'.strtolower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ]);

    $product = Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $businessId,
        'name' => 'Widget',
        'amount' => 5000,
        // The Product model refuses a store-assigned product with a
        // non-positive quantity (saving validation), so the base row needs
        // positive stock. On-hand for costing still comes from StockLocation.
        'quantity' => 10,
        'status' => 'active',
    ], $attributes));

    if ($onHand > 0) {
        $warehouse = Warehouse::create([
            'user_id' => $owner->id,
            'business_id' => $businessId,
            'name' => 'WS22 Depot',
            'status' => 'active',
        ]);

        StockLocation::create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'locationable_type' => Warehouse::class,
            'locationable_id' => $warehouse->id,
            'quantity' => $onHand,
            'min_quantity' => 0,
        ]);
    }

    return $product;
}

// ---------------------------------------------------------------------------
// Suppliers
// ---------------------------------------------------------------------------

test('the supplier list searches name, email and phone and reports outstanding payables', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $acme = ws22Supplier($business->id, ['name' => 'Acme Supplies', 'email' => 'buy@acme.test']);
    ws22Supplier($business->id, ['name' => 'Zeta Traders', 'phone' => '08000000000']);

    // Non-void totals minus payments; the void bill is excluded entirely.
    ws22Bill($business->id, $acme->id, ['total_kobo' => 100000]);
    ws22Bill($business->id, $acme->id, ['total_kobo' => 50000, 'amount_paid_kobo' => 50000, 'status' => Bill::STATUS_PAID]);
    ws22Bill($business->id, $acme->id, ['total_kobo' => 70000, 'status' => Bill::STATUS_VOID]);

    // Another business's supplier with the same name must never appear.
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22Supplier($otherBusiness->id, ['name' => 'Acme Supplies']);

    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/suppliers';

    $response = $this->withToken($token)->getJson($url)->assertOk();
    expect($response->json('data.suppliers'))->toHaveCount(2);

    $row = collect($response->json('data.suppliers'))->firstWhere('name', 'Acme Supplies');
    expect($row['bills_count'])->toBe(3)
        ->and($row['bills_total_kobo'])->toBe(150000)
        ->and($row['bills_paid_kobo'])->toBe(50000)
        ->and($row['outstanding_kobo'])->toBe(100000);

    $this->withToken($token)->getJson($url.'?q=zeta')->assertOk()->assertJsonCount(1, 'data.suppliers')
        ->assertJsonPath('data.suppliers.0.name', 'Zeta Traders');
    $this->withToken($token)->getJson($url.'?q=acme.test')->assertOk()->assertJsonCount(1, 'data.suppliers');
    $this->withToken($token)->getJson($url.'?q=08000000000')->assertOk()->assertJsonCount(1, 'data.suppliers');
    $this->withToken($token)->getJson($url.'?q=nothing-matches')->assertOk()->assertJsonCount(0, 'data.suppliers');

    // Paginated like the legacy 20/page list.
    $paged = $this->withToken($token)->getJson($url.'?per_page=1')->assertOk();
    expect($paged->json('meta.total'))->toBe(2)
        ->and($paged->json('meta.last_page'))->toBe(2)
        ->and($paged->json('data.suppliers'))->toHaveCount(1);
});

test('a supplier can be created and edited with validation', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/suppliers';

    $this->withToken($token)->postJson($url, ['email' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->withToken($token)->postJson($url, ['name' => 'Bad Email', 'email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $created = $this->withToken($token)->postJson($url, [
        'name' => 'Acme Supplies',
        'email' => 'buy@acme.test',
        'phone' => '08012345678',
        'address' => '1 Industrial Way',
        'notes' => 'Net 30',
    ])->assertCreated();

    $created->assertJsonPath('data.supplier.name', 'Acme Supplies')
        ->assertJsonPath('data.supplier.outstanding_kobo', 0);

    expect($created->json('data.supplier.is_active'))->toBeTrue();

    $id = $created->json('data.supplier.id');

    $this->withToken($token)->putJson($url.'/'.$id, [
        'name' => 'Acme Supplies Ltd',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.supplier.name', 'Acme Supplies Ltd')
        ->assertJsonPath('data.supplier.is_active', false);

    // Unknown ids are a 404, other businesses a 403 (below).
    $this->withToken($token)->putJson($url.'/999999', ['name' => 'Ghost'])->assertStatus(404);
});

test('suppliers with bills cannot be deleted and other businesses are unreachable', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/suppliers';

    $withBills = ws22Supplier($business->id, ['name' => 'Has Bills']);
    ws22Bill($business->id, $withBills->id);
    $clean = ws22Supplier($business->id, ['name' => 'No Bills']);

    $this->withToken($token)->deleteJson($url.'/'.$withBills->id)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Suppliers with bills cannot be deleted.');

    $this->withToken($token)->deleteJson($url.'/'.$clean->id)->assertOk();
    expect(Supplier::find($clean->id))->toBeNull();

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $theirs = ws22Supplier($otherBusiness->id, ['name' => 'Theirs']);

    $this->withToken($token)->getJson($url.'/'.$theirs->id)->assertStatus(403);
    $this->withToken($token)->putJson($url.'/'.$theirs->id, ['name' => 'Hijacked'])->assertStatus(403);
    $this->withToken($token)->deleteJson($url.'/'.$theirs->id)->assertStatus(403);

    expect(Supplier::find($theirs->id)->name)->toBe('Theirs');
});

test('the supplier detail returns the contact card, bills and payment history', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id, [
        'name' => 'Acme Supplies',
        'email' => 'buy@acme.test',
        'phone' => '08012345678',
        'address' => '1 Industrial Way',
        'notes' => 'Net 30',
    ]);

    $bill = ws22Bill($business->id, $supplier->id, [
        'total_kobo' => 50000,
        'amount_paid_kobo' => 20000,
        'status' => Bill::STATUS_PARTIAL,
    ]);

    BillPayment::create([
        'business_id' => $business->id,
        'bill_id' => $bill->id,
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount_kobo' => 20000,
        'method' => 'bank_transfer',
        'reference' => 'TRF-1',
    ]);

    $this->withToken(ws22Token($owner))
        ->getJson('/api/v1/management/accounting/suppliers/'.$supplier->id)
        ->assertOk()
        ->assertJsonPath('data.supplier.name', 'Acme Supplies')
        ->assertJsonPath('data.supplier.email', 'buy@acme.test')
        ->assertJsonPath('data.supplier.totals.bills_count', 1)
        ->assertJsonPath('data.supplier.totals.total_billed_kobo', 50000)
        ->assertJsonPath('data.supplier.totals.total_paid_kobo', 20000)
        ->assertJsonPath('data.supplier.totals.outstanding_kobo', 30000)
        ->assertJsonPath('data.supplier.bills.0.bill_number', $bill->bill_number)
        ->assertJsonPath('data.supplier.bills.0.balance_kobo', 30000)
        ->assertJsonPath('data.supplier.payments.0.reference', 'TRF-1')
        ->assertJsonPath('data.supplier.payments.0.method_label', 'Bank transfer');
});

// ---------------------------------------------------------------------------
// Bills — list
// ---------------------------------------------------------------------------

test('the bill list returns the legacy stats block and applies q, supplier and status filters', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $acme = ws22Supplier($business->id, ['name' => 'Acme Supplies']);
    $zeta = ws22Supplier($business->id, ['name' => 'Zeta Traders']);

    ws22Bill($business->id, $acme->id, ['bill_number' => 'BILL-OPEN', 'total_kobo' => 100000]);
    ws22Bill($business->id, $acme->id, [
        'bill_number' => 'BILL-PART',
        'total_kobo' => 50000,
        'amount_paid_kobo' => 20000,
        'status' => Bill::STATUS_PARTIAL,
    ]);
    ws22Bill($business->id, $zeta->id, [
        'bill_number' => 'BILL-PAID',
        'total_kobo' => 40000,
        'amount_paid_kobo' => 40000,
        'status' => Bill::STATUS_PAID,
    ]);
    ws22Bill($business->id, $zeta->id, [
        'bill_number' => 'BILL-LATE',
        'total_kobo' => 30000,
        'due_date' => now()->subDay()->toDateString(),
    ]);
    ws22Bill($business->id, $zeta->id, ['bill_number' => 'BILL-VOID', 'total_kobo' => 90000, 'status' => Bill::STATUS_VOID]);

    // Another business's bills must never leak in or skew the stats.
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22Bill($otherBusiness->id, ws22Supplier($otherBusiness->id)->id, ['total_kobo' => 999999]);

    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/bills';

    $response = $this->withToken($token)->getJson($url)->assertOk();

    expect($response->json('data.meta.total'))->toBe(5)
        // Outstanding (open + partial balances) = 100000 + 30000 + 30000.
        ->and($response->json('data.stats.open_kobo'))->toBe(160000)
        ->and($response->json('data.stats.paid_kobo'))->toBe(40000)
        ->and($response->json('data.stats.overdue'))->toBe(1);

    $rows = collect($response->json('data.data'))->keyBy('bill_number');
    expect($rows['BILL-LATE']['is_overdue'])->toBeTrue()
        ->and($rows['BILL-OPEN']['is_overdue'])->toBeFalse()
        ->and($rows['BILL-OPEN']['supplier'])->toBe('Acme Supplies')
        ->and($rows['BILL-OPEN']['balance_kobo'])->toBe(100000)
        ->and($rows['BILL-OPEN']['items_count'])->toBe(0);

    // The supplier dropdown options ride with the list.
    expect(collect($response->json('data.suppliers'))->pluck('name')->all())->toBe(['Acme Supplies', 'Zeta Traders']);

    $this->withToken($token)->getJson($url.'?status=paid')->assertOk()->assertJsonPath('data.meta.total', 1);
    $this->withToken($token)->getJson($url.'?supplier='.$zeta->id)->assertOk()->assertJsonPath('data.meta.total', 3);
    $this->withToken($token)->getJson($url.'?q=BILL-PART')->assertOk()->assertJsonPath('data.meta.total', 1);
    $this->withToken($token)->getJson($url.'?q=Zeta')->assertOk()->assertJsonPath('data.meta.total', 3);
    $this->withToken($token)->getJson($url.'?q=nothing-matches')->assertOk()->assertJsonPath('data.meta.total', 0);

    // Verify (mgmt-accounting.verify.md): the legacy UI never rendered date
    // filters, so from/to are inert on this endpoint.
    $this->withToken($token)->getJson($url.'?from='.now()->addDay()->toDateString())->assertOk()
        ->assertJsonPath('data.meta.total', 5);
});

// ---------------------------------------------------------------------------
// Bills — create
// ---------------------------------------------------------------------------

test('a bill is created with kobo-exact totals and a balanced AP posting', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id, ['name' => 'Acme Supplies']);

    $response = $this->withToken(ws22Token($owner))
        ->postJson('/api/v1/management/accounting/bills', [
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax' => '10.50',
            'notes' => 'October restock',
            'items' => [
                ['description' => 'Cleaning service', 'quantity' => '2.5', 'unit_cost' => '10.00'],
                ['description' => 'Packaging', 'quantity' => '1.005', 'unit_cost' => '2.00'],
            ],
        ])
        ->assertCreated();

    // 2.5 × ₦10.00 = ₦25.00 (2500 kobo); 1.005 × ₦2.00 = ₦2.01 (201 kobo).
    $response
        ->assertJsonPath('data.bill.subtotal_kobo', 2701)
        ->assertJsonPath('data.bill.tax_kobo', 1050)
        ->assertJsonPath('data.bill.total_kobo', 3751)
        ->assertJsonPath('data.bill.balance_kobo', 3751)
        ->assertJsonPath('data.bill.status', 'open')
        ->assertJsonPath('data.bill.notes', 'October restock')
        ->assertJsonPath('data.bill.items.1.quantity', '1.005');

    expect($response->json('data.bill.bill_number'))->toStartWith('BILL-');

    $bill = Bill::query()->whereKey($response->json('data.bill.id'))->firstOrFail();
    expect($bill->items)->toHaveCount(2);

    $entry = JournalEntry::query()->whereKey($bill->journal_entry_id)->firstOrFail();
    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->totalDebits())->toBe(3751)
        ->and($entry->totalCredits())->toBe(3751);

    // One credit line (Accounts Payable) and two debits (expense + input VAT).
    expect($entry->lines->where('credit_kobo', '>', 0))->toHaveCount(1)
        ->and((int) $entry->lines->sum('debit_kobo'))->toBe(3751)
        ->and($entry->lines->where('tax_kobo', 1050))->toHaveCount(1);
});

test('bill numbers are auto-generated when omitted and duplicates are a field error', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id);
    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/bills';

    $payload = [
        'supplier_id' => $supplier->id,
        'issue_date' => now()->toDateString(),
        'items' => [['description' => 'Stock', 'quantity' => '1', 'unit_cost' => '100.00']],
    ];

    $auto = $this->withToken($token)->postJson($url, $payload)->assertCreated();
    expect($auto->json('data.bill.bill_number'))->toStartWith('BILL-');

    $named = $this->withToken($token)->postJson($url, [...$payload, 'bill_number' => 'INV-001'])->assertCreated();
    $named->assertJsonPath('data.bill.bill_number', 'INV-001');

    // Legacy let this reach the unique index and 500'd; it is a 422 now.
    $this->withToken($token)->postJson($url, [...$payload, 'bill_number' => 'INV-001'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('bill_number');

    // Uniqueness is per business: another business may reuse the number.
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($otherBusiness->id);

    // Token switch: within one test the Sanctum guard caches the first user
    // it resolved, so the guard must be forgotten or this request would still
    // authenticate as the first owner.
    app('auth')->forgetGuards();

    $this->withToken(ws22Token($otherOwner))
        ->postJson($url, [
            'supplier_id' => ws22Supplier($otherBusiness->id)->id,
            'bill_number' => 'INV-001',
            'issue_date' => now()->toDateString(),
            'items' => [['description' => 'Stock', 'quantity' => '1', 'unit_cost' => '5.00']],
        ])
        ->assertCreated();
});

test('bill creation validates line items and refuses other businesses suppliers and products', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/bills';

    $this->withToken($token)->postJson($url, [
        'issue_date' => now()->toDateString(),
        'items' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['supplier_id', 'items']);

    // A supplier id from another business fails the scoped exists rule.
    $this->withToken($token)->postJson($url, [
        'supplier_id' => ws22Supplier($otherBusiness->id)->id,
        'issue_date' => now()->toDateString(),
        'items' => [['description' => 'X', 'quantity' => '1', 'unit_cost' => '10']],
    ])->assertStatus(422)->assertJsonValidationErrors('supplier_id');

    $mine = ws22Supplier($business->id);

    // Zero quantity, empty description and a due date before issue.
    $this->withToken($token)->postJson($url, [
        'supplier_id' => $mine->id,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->subDay()->toDateString(),
        'items' => [['description' => '', 'quantity' => '0', 'unit_cost' => '10']],
    ])->assertStatus(422)->assertJsonValidationErrors([
        'due_date',
        'items.0.description',
        'items.0.quantity',
    ]);

    // A product from another business fails the scoped exists rule too.
    $otherProduct = ws22Product($otherBusiness->id, $otherOwner);

    $this->withToken($token)->postJson($url, [
        'supplier_id' => $mine->id,
        'issue_date' => now()->toDateString(),
        'items' => [['description' => 'X', 'quantity' => '1', 'unit_cost' => '10', 'product_id' => $otherProduct->id]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
});

test('product-linked lines update the weighted-average inventory cost', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id);
    $product = ws22Product($business->id, $owner, [
        'average_cost_kobo' => 2500,
        'cost_price' => '25.00',
    ], onHand: 4);

    $this->withToken(ws22Token($owner))
        ->postJson('/api/v1/management/accounting/bills', [
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'items' => [[
                'description' => 'Widget restock',
                'quantity' => '4',
                'unit_cost' => '30.00',
                'product_id' => $product->id,
            ]],
        ])
        ->assertCreated();

    // Existing 4 × ₦25 + received 4 × ₦30 = ₦220 over 8 units → ₦27.50.
    $product->refresh();
    expect($product->average_cost_kobo)->toBe(2750)
        ->and((float) $product->cost_price)->toBe(30.0);
});

test('the bill form options expose every picker including the product list', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $active = ws22Supplier($business->id, ['name' => 'Active Supplier', 'is_active' => true]);
    ws22Supplier($business->id, ['name' => 'Inactive Supplier', 'is_active' => false]);
    $product = ws22Product($business->id, $owner, ['name' => 'Widget', 'product_code' => 'WGT-1']);

    $response = $this->withToken(ws22Token($owner))
        ->getJson('/api/v1/management/accounting/bill-options')
        ->assertOk();

    expect(collect($response->json('data.suppliers'))->pluck('name')->all())->toBe(['Active Supplier'])
        ->and($response->json('data.products.0.name'))->toBe('Widget')
        ->and($response->json('data.products.0.product_code'))->toBe('WGT-1')
        ->and($response->json('data.expense_accounts'))->not->toBeEmpty()
        ->and($response->json('data.payment_methods.0.value'))->toBe('bank_transfer')
        ->and(collect($response->json('data.payment_methods'))->pluck('value')->all())
        ->toBe(['bank_transfer', 'cash', 'cheque', 'card', 'other']);

    expect($response->json('data.suppliers.0.id'))->toBe($active->id)
        ->and($response->json('data.products.0.id'))->toBe($product->id);
});

// ---------------------------------------------------------------------------
// Bills — detail, payments, void
// ---------------------------------------------------------------------------

test('the bill detail carries items, payments and the journal trace, and refuses other businesses', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id);

    $created = $this->withToken(ws22Token($owner))
        ->postJson('/api/v1/management/accounting/bills', [
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'items' => [['description' => 'Stock', 'quantity' => '2', 'unit_cost' => '50.00']],
        ])
        ->assertCreated();

    $billId = $created->json('data.bill.id');

    $detail = $this->withToken(ws22Token($owner))
        ->getJson('/api/v1/management/accounting/bills/'.$billId)
        ->assertOk()
        ->assertJsonPath('data.bill.items.0.description', 'Stock')
        ->assertJsonPath('data.bill.items.0.unit_cost_kobo', 5000)
        ->assertJsonPath('data.bill.payments', [])
        ->assertJsonPath('data.bill.journal_entry.status', 'posted');

    expect($detail->json('data.bill.payment_accounts'))->not->toBeEmpty()
        ->and($detail->json('data.bill.journal_entry.entry_number'))->not->toBeNull();

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $otherSupplier = ws22Supplier($otherBusiness->id);
    $theirs = ws22Bill($otherBusiness->id, $otherSupplier->id);

    $token = ws22Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/accounting/bills/'.$theirs->id)->assertStatus(403);
    $this->withToken($token)->postJson('/api/v1/management/accounting/bills/'.$theirs->id.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '1.00',
        'method' => 'cash',
    ])->assertStatus(403);
    $this->withToken($token)->postJson('/api/v1/management/accounting/bills/'.$theirs->id.'/void')->assertStatus(403);

    expect($theirs->fresh()->status)->toBe(Bill::STATUS_OPEN);
});

test('payments move a bill open to partial to paid and post balanced entries', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id);
    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/bills';

    $created = $this->withToken($token)->postJson($url, [
        'supplier_id' => $supplier->id,
        'issue_date' => now()->toDateString(),
        'items' => [['description' => 'Stock', 'quantity' => '1', 'unit_cost' => '100.00']],
    ])->assertCreated();

    $billId = $created->json('data.bill.id');

    $partial = $this->withToken($token)->postJson($url.'/'.$billId.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '40.00',
        'method' => 'bank_transfer',
        'reference' => 'TRF-1',
    ])->assertCreated();

    $partial->assertJsonPath('data.bill.status', 'partial')
        ->assertJsonPath('data.bill.paid_kobo', 4000)
        ->assertJsonPath('data.bill.balance_kobo', 6000);

    expect($partial->json('data.bill.payments'))->toHaveCount(1)
        ->and($partial->json('data.bill.payments.0.method_label'))->toBe('Bank transfer');

    $payment = BillPayment::query()->firstOrFail();
    $entry = JournalEntry::query()->whereKey($payment->journal_entry_id)->firstOrFail();
    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebits())->toBe(4000)
        ->and($entry->totalCredits())->toBe(4000);

    // More than the remaining balance is refused up front…
    $this->withToken($token)->postJson($url.'/'.$billId.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '60.01',
        'method' => 'cash',
    ])->assertStatus(422)->assertJsonValidationErrors('amount');

    // …and zero is too small.
    $this->withToken($token)->postJson($url.'/'.$billId.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '0',
        'method' => 'cash',
    ])->assertStatus(422)->assertJsonValidationErrors('amount');

    $paid = $this->withToken($token)->postJson($url.'/'.$billId.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '60.00',
        'method' => 'cash',
    ])->assertCreated();

    $paid->assertJsonPath('data.bill.status', 'paid')
        ->assertJsonPath('data.bill.balance_kobo', 0)
        ->assertJsonPath('data.bill.paid_kobo', 10000);

    expect($paid->json('data.bill.payments'))->toHaveCount(2);

    // A settled bill cannot take more money.
    $this->withToken($token)->postJson($url.'/'.$billId.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '1.00',
        'method' => 'cash',
    ])->assertStatus(422)->assertJsonPath('message', 'This bill cannot accept payments.');
});

test('voiding refuses bills with payments and otherwise reverses the AP posting', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id);
    $token = ws22Token($owner);
    $url = '/api/v1/management/accounting/bills';

    $payload = [
        'supplier_id' => $supplier->id,
        'issue_date' => now()->toDateString(),
        'items' => [['description' => 'Stock', 'quantity' => '1', 'unit_cost' => '100.00']],
    ];

    // With a payment: refused.
    $paidBill = $this->withToken($token)->postJson($url, $payload)->assertCreated();
    $paidBillId = $paidBill->json('data.bill.id');

    $this->withToken($token)->postJson($url.'/'.$paidBillId.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '10.00',
        'method' => 'cash',
    ])->assertCreated();

    $this->withToken($token)->postJson($url.'/'.$paidBillId.'/void')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Bills with payments cannot be voided.');

    expect(Bill::find($paidBillId)->status)->toBe(Bill::STATUS_PARTIAL);

    // Without payments: voided, original entry void, contra posted.
    $clean = $this->withToken($token)->postJson($url, $payload)->assertCreated();
    $cleanId = $clean->json('data.bill.id');

    $original = JournalEntry::query()->whereKey(Bill::find($cleanId)->journal_entry_id)->firstOrFail();

    $voided = $this->withToken($token)->postJson($url.'/'.$cleanId.'/void')->assertOk();
    $voided->assertJsonPath('data.bill.status', 'void');

    $original->refresh();
    expect($original->status)->toBe(JournalEntry::STATUS_VOID);

    $contra = JournalEntry::query()->where('reversal_of_id', $original->id)->firstOrFail();
    expect($contra->isBalanced())->toBeTrue()
        ->and($contra->totalDebits())->toBe(10000);

    $voided->assertJsonPath('data.bill.reversal_entry.entry_number', $contra->entry_number);

    // The voided bill drops out of the outstanding stat; only the partial
    // bill's ₦90 balance remains (the other ₦100 bill was settled).
    $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.stats.open_kobo', 9000);

    // And voiding twice is refused.
    $this->withToken($token)->postJson($url.'/'.$cleanId.'/void')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This bill is already void.');

    // A void bill cannot accept payments either.
    $this->withToken($token)->postJson($url.'/'.$cleanId.'/payments', [
        'payment_date' => now()->toDateString(),
        'amount' => '1.00',
        'method' => 'cash',
    ])->assertStatus(422)->assertJsonPath('message', 'This bill cannot accept payments.');
});

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------

test('supplier and bill routes require the legacy accounting permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    $supplier = ws22Supplier($business->id);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Manager');

    $staffToken = ws22Token($staff);

    $this->withToken($staffToken)->getJson('/api/v1/management/accounting/suppliers')->assertStatus(403);
    $this->withToken($staffToken)->getJson('/api/v1/management/accounting/suppliers/'.$supplier->id)->assertStatus(403);
    $this->withToken($staffToken)->postJson('/api/v1/management/accounting/suppliers', ['name' => 'X'])->assertStatus(403);
    $this->withToken($staffToken)->getJson('/api/v1/management/accounting/bills')->assertStatus(403);
    $this->withToken($staffToken)->getJson('/api/v1/management/accounting/bill-options')->assertStatus(403);
    $this->withToken($staffToken)->postJson('/api/v1/management/accounting/bills', [
        'supplier_id' => $supplier->id,
        'issue_date' => now()->toDateString(),
        'items' => [['description' => 'X', 'quantity' => '1', 'unit_cost' => '1']],
    ])->assertStatus(403);

    // The Accountant role holds the legacy supplier/bill permissions.
    $accountant = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $accountant->assignRole('Accountant');

    $accountantToken = ws22Token($accountant);

    // Token switch: the Sanctum guard caches the first user resolved in the
    // test, so without this the accountant's requests would still be
    // authenticated as the permission-less Store Manager.
    app('auth')->forgetGuards();

    $this->withToken($accountantToken)->getJson('/api/v1/management/accounting/suppliers')->assertOk();
    $this->withToken($accountantToken)->getJson('/api/v1/management/accounting/bills')->assertOk();
    $this->withToken($accountantToken)->getJson('/api/v1/management/accounting/bill-options')->assertOk();
    $this->withToken($accountantToken)->postJson('/api/v1/management/accounting/suppliers', ['name' => 'From Accountant'])
        ->assertCreated();
    $this->withToken($accountantToken)->postJson('/api/v1/management/accounting/bills', [
        'supplier_id' => $supplier->id,
        'issue_date' => now()->toDateString(),
        'items' => [['description' => 'Stock', 'quantity' => '1', 'unit_cost' => '10.00']],
    ])->assertCreated();
});

test('the bill list and supplier list exclude other businesses rows', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($business->id);

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws22EnsureBooks($otherBusiness->id);

    $mine = ws22Supplier($business->id, ['name' => 'Mine']);
    ws22Supplier($otherBusiness->id, ['name' => 'Theirs']);
    ws22Bill($business->id, $mine->id, ['bill_number' => 'MINE-1']);
    ws22Bill($otherBusiness->id, ws22Supplier($otherBusiness->id, ['name' => 'Their Supplier'])->id, ['bill_number' => 'THEIRS-1']);

    $token = ws22Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/accounting/suppliers')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.suppliers.0.name', 'Mine');

    $this->withToken($token)->getJson('/api/v1/management/accounting/bills')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.data.0.bill_number', 'MINE-1')
        ->assertJsonPath('data.stats.open_kobo', 100000);
});
