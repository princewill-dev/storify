<?php

use App\Enums\InvoiceStatus;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LedgerAccount;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;

/*
|--------------------------------------------------------------------------
| WS-24 — Accounting report exports & polish
|--------------------------------------------------------------------------
|
| The nine report GETs now serve the legacy `?export=csv` (all) and
| `?export=pdf` (trial balance, P&L, balance sheet) branches on top of the
| unchanged JSON payload. These tests pin the CSV format to the legacy
| headers/rows (accountants' templates must keep opening), the JSON parity
| against the previous payload, the tenant and permission guards, and the
| date-filter validation the legacy lacked.
|
| CSV expectations quote multi-word fields because PHP's fputcsv encloses any
| field containing a space (FPUTCSV_FLD_CHK(' ') in php-src, unchanged for
| years) — the same bytes the legacy streamDownload export produced.
*/

function ws24Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws24EnsureBooks(int $businessId): void
{
    app(LedgerSetupService::class)->ensureForBusiness($businessId);
}

function ws24Account(int $businessId, string $code): LedgerAccount
{
    return LedgerAccount::query()
        ->where('business_id', $businessId)
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 * @param  array<string, mixed>  $options
 */
function ws24Post(int $businessId, array $lines, array $options = []): void
{
    app(LedgerPostingService::class)->post($businessId, $lines, $options);
}

function ws24Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Main Store',
        'slug' => 'main-store-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

test('trial balance csv reproduces the legacy headers, rows and 2dp amounts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);

    ws24Post($business->id, [
        ['account_id' => ws24Account($business->id, '1010')->id, 'debit' => 123456],
        ['account_id' => ws24Account($business->id, '4010')->id, 'credit' => 123456],
    ], ['memo' => 'Opening float', 'date' => '2026-05-01']);

    $response = $this->withToken(ws24Token($owner))->get(
        '/api/v1/management/accounting/reports/trial-balance?export=csv&from=2026-01-01&to=2026-12-31'
    );

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('trial-balance-');

    $csv = $response->streamedContent();

    expect(explode("\n", trim($csv)))->toContain(
        'Code,Account,Debit,Credit',
        '1010,"Cash on Hand",1234.56,0.00',
        '4010,"Sales Revenue",0.00,1234.56',
    );
});

test('report JSON payloads are unchanged and unknown export values fall back to JSON', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);

    ws24Post($business->id, [
        ['account_id' => ws24Account($business->id, '1010')->id, 'debit' => 123456],
        ['account_id' => ws24Account($business->id, '4010')->id, 'credit' => 123456],
    ], ['date' => '2026-05-01']);

    $token = ws24Token($owner);
    $range = 'from=2026-01-01&to=2026-12-31';

    // Trial balance JSON: the shape the SPA has always consumed.
    $this->withToken($token)->getJson('/api/v1/management/accounting/reports/trial-balance?'.$range)
        ->assertOk()
        ->assertJsonPath('data.total_debit', 123456)
        ->assertJsonPath('data.total_credit', 123456)
        ->assertJsonPath('data.lines.0.code', '1010')
        ->assertJsonPath('data.lines.0.name', 'Cash on Hand')
        ->assertJsonPath('data.lines.0.debit', 123456);

    // Profit & loss JSON keeps the service's raw shape.
    $this->withToken($token)->getJson('/api/v1/management/accounting/reports/profit-and-loss?'.$range)
        ->assertOk()
        ->assertJsonPath('data.total_income', 123456)
        ->assertJsonPath('data.total_expenses', 0)
        ->assertJsonPath('data.net_profit', 123456)
        ->assertJsonPath('data.income.0.account.code', '4010');

    // General ledger JSON keeps opening/rows/closing.
    $cash = ws24Account($business->id, '1010');
    $this->withToken($token)->getJson('/api/v1/management/accounting/reports/general-ledger/'.$cash->id.'?'.$range)
        ->assertOk()
        ->assertJsonPath('data.account.code', '1010')
        ->assertJsonPath('data.opening', 0)
        ->assertJsonPath('data.closing', 123456)
        ->assertJsonPath('data.rows.0.entry_number', fn ($number) => is_string($number) && $number !== '')
        ->assertJsonPath('data.rows.0.balance', 123456);

    // An unknown export value must not fall back to a legacy Blade view.
    $fallback = $this->withToken($token)->get('/api/v1/management/accounting/reports/trial-balance?export=xlsx&'.$range);

    $fallback->assertOk();
    expect($fallback->headers->get('content-type'))->toContain('application/json');
});

test('vat summary csv matches the legacy statement rows', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);

    // A sale with output VAT on the payable leg.
    ws24Post($business->id, [
        ['account_id' => ws24Account($business->id, '1100')->id, 'debit' => 110000],
        ['account_id' => ws24Account($business->id, '4010')->id, 'credit' => 100000],
        ['account_id' => ws24Account($business->id, '2100')->id, 'credit' => 10000, 'tax_kobo' => 10000],
    ], ['date' => '2026-05-02']);

    // An expense with input VAT, mirroring LedgerPostingService::postExpense
    // (net expense + VAT payable debit, cash credit for the gross).
    ws24Post($business->id, [
        ['account_id' => ws24Account($business->id, '5200')->id, 'debit' => 25000],
        ['account_id' => ws24Account($business->id, '2100')->id, 'debit' => 2500, 'tax_kobo' => 2500],
        ['account_id' => ws24Account($business->id, '1010')->id, 'credit' => 27500],
    ], ['date' => '2026-05-03']);

    $response = $this->withToken(ws24Token($owner))->get(
        '/api/v1/management/accounting/reports/vat-summary?export=csv&from=2026-01-01&to=2026-12-31'
    );

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('vat-summary-');

    expect(explode("\n", trim($response->streamedContent())))->toBe([
        'Description,Amount',
        '"Output VAT (sales)",100.00',
        '"Input VAT (purchases)",25.00',
        '"Net VAT payable",75.00',
    ]);
});

test('ar and ap aging csv keep the bucket, due and paid columns', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);
    $token = ws24Token($owner);

    Invoice::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'recipient_name' => 'Ada Client',
        'recipient_email' => 'ada@example.com',
        'status' => InvoiceStatus::SENT,
        'issue_date' => now()->subDays(45)->toDateString(),
        'due_date' => now()->subDays(40)->toDateString(),
        'subtotal' => 1000,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 1000,
        'amount_paid' => 250,
    ]);

    Supplier::create(['business_id' => $business->id, 'name' => 'Acme Supplies']);

    Bill::create([
        'business_id' => $business->id,
        'supplier_id' => Supplier::query()->where('business_id', $business->id)->firstOrFail()->id,
        'bill_number' => 'BILL-1',
        'issue_date' => now()->subDays(15)->toDateString(),
        'due_date' => now()->subDays(10)->toDateString(),
        'subtotal_kobo' => 50000,
        'tax_kobo' => 0,
        'total_kobo' => 50000,
        'amount_paid_kobo' => 10000,
        'status' => Bill::STATUS_OPEN,
    ]);

    $ar = $this->withToken($token)->get('/api/v1/management/accounting/reports/ar-aging?export=csv');
    $ar->assertOk();

    $arCsv = $ar->streamedContent();

    expect(explode("\n", trim($arCsv))[0])->toBe('Bucket,Reference,Contact,Total,Paid,Outstanding,"Days Past Due"')
        ->and($arCsv)->toContain('31–60 days')
        ->and($arCsv)->toContain('"Ada Client",1000.00,250.00,750.00,40');

    $ap = $this->withToken($token)->get('/api/v1/management/accounting/reports/ap-aging?export=csv');
    $ap->assertOk();

    $apCsv = $ap->streamedContent();

    expect($apCsv)->toContain('1–30 days')
        ->and($apCsv)->toContain('BILL-1,"Acme Supplies",500.00,100.00,400.00,10');

    // The JSON payload still carries due_date and paid so the SPA columns
    // the audit flagged missing can be rendered without a second endpoint.
    $this->withToken($token)->getJson('/api/v1/management/accounting/reports/ar-aging')
        ->assertOk()
        ->assertJsonPath('data.buckets.31_60.rows.0.due_date', fn ($due) => $due !== null)
        ->assertJsonPath('data.buckets.31_60.rows.0.paid', 25000);
});

test('trial balance, profit and loss and balance sheet export pdf artefacts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);

    ws24Post($business->id, [
        ['account_id' => ws24Account($business->id, '1010')->id, 'debit' => 500000],
        ['account_id' => ws24Account($business->id, '4010')->id, 'credit' => 500000],
    ], ['date' => '2026-05-04']);

    $token = ws24Token($owner);
    $range = 'from=2026-01-01&to=2026-12-31';

    foreach ([
        '/api/v1/management/accounting/reports/trial-balance?export=pdf&'.$range => 'trial-balance-',
        '/api/v1/management/accounting/reports/profit-and-loss?export=pdf&'.$range => 'profit-and-loss-',
        '/api/v1/management/accounting/reports/balance-sheet?export=pdf&as_of=2026-12-31' => 'balance-sheet-',
    ] as $url => $filename) {
        $response = $this->withToken($token)->get($url);

        $response->assertOk();

        expect($response->headers->get('content-type'))->toContain('application/pdf')
            ->and($response->headers->get('content-disposition'))->toContain($filename)
            ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }
});

test('expense summary and integrity csv exports keep the legacy columns', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);
    $token = ws24Token($owner);

    Expense::create([
        'business_id' => $business->id,
        'ledger_account_id' => ws24Account($business->id, '5200')->id,
        'expense_date' => now()->toDateString(),
        'amount_kobo' => 123456,
        'tax_kobo' => 0,
        'total_kobo' => 123456,
        'currency' => 'NGN',
        'payment_method' => 'cash',
        'status' => Expense::STATUS_PAID,
    ]);

    ws24Store($owner);

    $expenses = $this->withToken($token)->get(
        '/api/v1/management/accounting/reports/expense-summary?export=csv&from=2026-01-01&to=2026-12-31'
    );
    $expenses->assertOk();

    expect(explode("\n", trim($expenses->streamedContent())))->toContain(
        'Category,Count,Total',
        'Rent,1,1234.56',
    );

    $integrity = $this->withToken($token)->get('/api/v1/management/accounting/reports/integrity?export=csv');
    $integrity->assertOk();

    $integrityCsv = $integrity->streamedContent();

    expect(explode("\n", trim($integrityCsv))[0])->toBe('Store,Wallet,Ledger,Difference')
        ->and($integrityCsv)->toContain('"Main Store",0.00,0.00,0.00');
});

test('a malformed date filter is a 422, not a legacy 500', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);

    $token = ws24Token($owner);

    $this->withToken($token)
        ->getJson('/api/v1/management/accounting/reports/trial-balance?from=not-a-date')
        ->assertStatus(422);

    $this->withToken($token)
        ->get('/api/v1/management/accounting/reports/profit-and-loss?export=csv&to=oops')
        ->assertStatus(422);
});

test('reports refuse another business ledger account and never leak its data', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($otherBusiness->id);

    ws24Post($business->id, [
        ['account_id' => ws24Account($business->id, '1010')->id, 'debit' => 100000],
        ['account_id' => ws24Account($business->id, '4010')->id, 'credit' => 100000],
    ], ['date' => '2026-05-05']);

    ws24Post($otherBusiness->id, [
        ['account_id' => ws24Account($otherBusiness->id, '1010')->id, 'debit' => 999900],
        ['account_id' => ws24Account($otherBusiness->id, '4010')->id, 'credit' => 999900],
    ], ['date' => '2026-05-05', 'memo' => 'OTHER BUSINESS ENTRY']);

    Invoice::create([
        'business_id' => $otherBusiness->id,
        'user_id' => $otherOwner->id,
        'recipient_name' => 'Other Client',
        'status' => InvoiceStatus::SENT,
        'issue_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->subDays(3)->toDateString(),
        'subtotal' => 900,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 900,
        'amount_paid' => 0,
    ]);

    $token = ws24Token($owner);
    $otherAccount = ws24Account($otherBusiness->id, '1010');
    $range = 'from=2026-01-01&to=2026-12-31';

    $this->withToken($token)
        ->getJson('/api/v1/management/accounting/reports/general-ledger/'.$otherAccount->id.'?'.$range)
        ->assertStatus(403);

    $this->withToken($token)
        ->get('/api/v1/management/accounting/reports/general-ledger/'.$otherAccount->id.'?export=csv&'.$range)
        ->assertStatus(403);

    $csv = $this->withToken($token)
        ->get('/api/v1/management/accounting/reports/trial-balance?export=csv&'.$range)
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('1000.00')
        ->and($csv)->not->toContain('9999.00');

    $ar = $this->withToken($token)
        ->get('/api/v1/management/accounting/reports/ar-aging')
        ->assertOk();

    expect($ar->getContent())->not->toContain('Other Client');
});

test('report endpoints require the legacy accounting reports permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws24EnsureBooks($business->id);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Warehouse Manager');

    $token = ws24Token($staff);
    $range = 'from=2026-01-01&to=2026-12-31';

    $this->withToken($token)
        ->getJson('/api/v1/management/accounting/reports/trial-balance?'.$range)
        ->assertStatus(403);

    $this->withToken($token)
        ->get('/api/v1/management/accounting/reports/trial-balance?export=csv&'.$range)
        ->assertStatus(403);

    // An Auditor role holds "accounting reports" and can read and export.
    $auditor = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $auditor->assignRole('Auditor');

    $this->withToken(ws24Token($auditor))
        ->getJson('/api/v1/management/accounting/reports/trial-balance?'.$range)
        ->assertOk();
});
