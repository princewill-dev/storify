<?php

use App\Models\ActivityLog;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;
use Database\Seeders\SpatiePermissionSeeder;

/*
|--------------------------------------------------------------------------
| WS-13 — Platform accounting (admin console)
|--------------------------------------------------------------------------
| Covers the platform-books dashboard (five totals, cash & clearing, recent
| entries, first-visit bootstrapping), the grouped chart of accounts, the
| journal list with its three-way search and filters, the CSV export, the
| entry detail with posting/reversal metadata, the three statements, the
| settings payload, mapping saves (with the unknown-key and cross-tenant
| refusals), period close/reopen, year close with retained earnings, and the
| audience/permission/platform-role refusals.
*/

function ad13AdminToken(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad13SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad13PlatformAdmin(): User
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

/**
 * A platform admin whose role deliberately lacks `admin.accounting`.
 */
function ad13SupportAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole('Support Admin');

    return $user;
}

/**
 * Post a balanced entry into the platform books (`business_id = null`).
 *
 * @param  array<int, array<string, mixed>>  $lines
 * @param  array<string, mixed>  $options
 */
function ad13Post(array $lines, array $options = []): JournalEntry
{
    $entry = app(LedgerPostingService::class)->post(null, $lines, $options);

    expect($entry)->not->toBeNull();

    return $entry;
}

function ad13PlatformAccount(string $code): LedgerAccount
{
    return LedgerAccount::query()->whereNull('business_id')->where('code', $code)->firstOrFail();
}

test('the platform books dashboard reports totals, cash balances and recent entries from the platform ledger only', function () {
    $admin = ad13SuperAdmin();

    // A tenant's books exist and carry entries — none of it may appear on the
    // platform dashboard.
    [$owner, $business] = createBusinessOwner();
    app(LedgerPostingService::class)->post($business->id, [
        ['account_key' => 'bank', 'debit' => 777000],
        ['account_key' => 'sales_income', 'credit' => 777000],
    ], ['memo' => 'Tenant sale']);

    ad13Post([
        ['account_key' => 'bank', 'debit' => 250000],
        ['account_key' => 'sales_income', 'credit' => 250000],
    ], ['memo' => 'Subscription payment']);

    $response = $this->getJson('/api/v1/admin/accounting', [
        'Authorization' => 'Bearer '.ad13AdminToken($admin),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.totals.assets', 250000)
        ->assertJsonPath('data.totals.liabilities', 0)
        ->assertJsonPath('data.totals.revenue', 250000)
        ->assertJsonPath('data.totals.expenses', 0)
        ->assertJsonPath('data.totals.net_profit', 250000);

    $cash = collect($response->json('data.cash_balances'))->keyBy('code');
    expect($cash)->toHaveCount(3)
        ->and($cash['1020']['balance_kobo'])->toBe(250000)
        ->and($cash['1010']['balance_kobo'])->toBe(0);

    $recent = collect($response->json('data.recent_entries'));
    expect($recent)->toHaveCount(1)
        ->and($recent->first()['memo'])->toBe('Subscription payment')
        ->and($recent->first()['lines_count'])->toBe(2)
        ->and($recent->first()['total_debits'])->toBe(250000);
});

test('opening the platform books bootstraps the chart and current fiscal year', function () {
    $admin = ad13SuperAdmin();

    expect(LedgerAccount::query()->whereNull('business_id')->count())->toBe(0)
        ->and(FiscalYear::query()->whereNull('business_id')->count())->toBe(0);

    $this->getJson('/api/v1/admin/accounting', [
        'Authorization' => 'Bearer '.ad13AdminToken($admin),
    ])->assertOk();

    expect(LedgerAccount::query()->whereNull('business_id')->count())->toBe(25)
        ->and(LedgerMapping::query()->whereNull('business_id')->count())->toBe(20)
        ->and(FiscalPeriod::query()->whereNull('business_id')->count())->toBe(12);

    $year = FiscalYear::query()->whereNull('business_id')->where('name', (string) now()->year)->first();
    expect($year)->not->toBeNull()->and($year->status)->toBe('open');
});

test('the chart of accounts is grouped by type with counts and computed balances', function () {
    $admin = ad13SuperAdmin();

    ad13Post([
        ['account_key' => 'bank', 'debit' => 100000],
        ['account_key' => 'sales_income', 'credit' => 100000],
    ]);

    $response = $this->getJson('/api/v1/admin/accounting/accounts', [
        'Authorization' => 'Bearer '.ad13AdminToken($admin),
    ]);

    $response->assertOk()->assertJsonPath('data.total_accounts', 25);

    $groups = collect($response->json('data.groups'))->keyBy('type');

    expect($groups->keys()->all())->toBe(['asset', 'liability', 'equity', 'income', 'expense'])
        ->and($groups['asset']['count'])->toBe(6)
        ->and($groups['income']['count'])->toBe(5)
        ->and($groups['expense']['count'])->toBe(8)
        // Bank (1020) is the only account with movement; income sits normal
        // on the credit side.
        ->and($groups['asset']['balance_kobo'])->toBe(100000)
        ->and($groups['income']['balance_kobo'])->toBe(100000);

    $bank = collect($groups['asset']['accounts'])->firstWhere('code', '1020');
    expect($bank['balance_kobo'])->toBe(100000)->and($bank['is_system'])->toBeTrue();

    // The q filter narrows the groups to matching accounts.
    $filtered = $this->getJson('/api/v1/admin/accounting/accounts?q=1020', [
        'Authorization' => 'Bearer '.ad13AdminToken($admin),
    ]);

    $filtered->assertOk()->assertJsonPath('data.total_accounts', 1);
    expect($filtered->json('data.groups'))->toHaveCount(1)
        ->and($filtered->json('data.groups.0.accounts.0.code'))->toBe('1020');
});

test('the journal lists only platform entries and searches number, reference and memo', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    $subscription = ad13Post([
        ['account_key' => 'bank', 'debit' => 100000],
        ['account_key' => 'sales_income', 'credit' => 100000],
    ], ['memo' => 'Subscription revenue October', 'reference' => 'SUB-OCT']);

    $gateway = ad13Post([
        ['account_key' => 'gateway_fees', 'debit' => 5000],
        ['account_key' => 'bank', 'credit' => 5000],
    ], ['memo' => 'Gateway fees', 'reference' => 'PLATFORM-FEE', 'date' => '2024-03-05']);

    // A tenant entry that must never surface here.
    [$owner, $business] = createBusinessOwner();
    app(LedgerPostingService::class)->post($business->id, [
        ['account_key' => 'bank', 'debit' => 999999],
        ['account_key' => 'sales_income', 'credit' => 999999],
    ], ['memo' => 'Tenant entry']);

    $numbers = function (string $query = '') use ($token) {
        $response = $this->getJson('/api/v1/admin/accounting/journal'.($query ? '?'.$query : ''), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $response->assertOk();

        return collect($response->json('data.entries'))->pluck('entry_number')->all();
    };

    expect($numbers())->toBe([$subscription->entry_number, $gateway->entry_number]);

    // Default page size is the legacy 20.
    $list = $this->getJson('/api/v1/admin/accounting/journal', ['Authorization' => 'Bearer '.$token]);
    $list->assertOk()
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.totals.debits', 105000)
        ->assertJsonPath('meta.totals.credits', 105000);

    expect($numbers('q=SUB-OCT'))->toBe([$subscription->entry_number]);
    expect($numbers('q='.$subscription->entry_number))->toBe([$subscription->entry_number]);
    expect($numbers('q=Gateway'))->toBe([$gateway->entry_number]);
    expect($numbers('status=posted'))->toHaveCount(2);
    expect($numbers('status=void'))->toBe([]);
    expect($numbers('from=2024-01-01&to=2024-12-31'))->toBe([$gateway->entry_number]);
    expect($numbers('to=2024-12-31'))->toBe([$gateway->entry_number]);
});

test('the journal paginates, validates every filter, and exports the filtered rows as CSV', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    $first = ad13Post([
        ['account_key' => 'bank', 'debit' => 1000],
        ['account_key' => 'sales_income', 'credit' => 1000],
    ], ['memo' => 'Export me', 'reference' => 'CSV-ONE']);

    ad13Post([
        ['account_key' => 'bank', 'debit' => 2000],
        ['account_key' => 'sales_income', 'credit' => 2000],
    ], ['memo' => 'Skip me', 'reference' => 'CSV-TWO']);

    $this->getJson('/api/v1/admin/accounting/journal?per_page=1&page=2', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonCount(1, 'data.entries');

    $invalid = [
        'per_page=0' => 'per_page',
        'per_page=500' => 'per_page',
        'status=bogus' => 'status',
        'from=not-a-date' => 'from',
        'from=2025-05-01&to=2025-01-01' => 'to',
    ];

    foreach ($invalid as $query => $field) {
        $this->getJson('/api/v1/admin/accounting/journal?'.$query, ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    $export = $this->get('/api/v1/admin/accounting/journal/export?q=CSV-ONE', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $export->assertOk();
    expect($export->headers->get('content-type'))->toContain('text/csv');

    $csv = $export->streamedContent();
    expect($csv)->toContain('Entry,Date,Reference,Memo,Status,Lines,Debits (NGN),Credits (NGN)')
        ->toContain($first->entry_number)
        ->toContain('CSV-ONE')
        ->not->toContain('CSV-TWO');
});

test('the entry detail returns lines, totals, period, poster and reversal links and 404s tenant entries', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    $entry = ad13Post([
        ['account_key' => 'bank', 'debit' => 30000],
        ['account_key' => 'sales_income', 'credit' => 30000],
    ], ['memo' => 'Detail entry', 'reference' => 'DETAIL-1', 'user_id' => $admin->id]);

    // A reversal that voids nothing here but exercises the banner pair.
    $reversal = JournalEntry::create([
        'business_id' => null,
        'entry_number' => 'JE-REV-TEST',
        'entry_date' => now()->toDateString(),
        'memo' => 'Reversal of detail entry',
        'status' => 'posted',
        'reversal_of_id' => $entry->id,
    ]);

    $response = $this->getJson('/api/v1/admin/accounting/journal/'.$entry->id, [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.entry.entry_number', $entry->entry_number)
        ->assertJsonPath('data.entry.reference', 'DETAIL-1')
        ->assertJsonPath('data.entry.fiscal_period.name', now()->format('Y-m'))
        ->assertJsonPath('data.entry.posted_by.name', $admin->name)
        ->assertJsonPath('data.entry.total_debits', 30000)
        ->assertJsonPath('data.entry.total_credits', 30000)
        ->assertJsonPath('data.entry.balanced', true)
        ->assertJsonPath('data.entry.reversal.reversed_by.entry_number', 'JE-REV-TEST');

    $lines = collect($response->json('data.entry.lines'))->keyBy('account_code');
    expect($lines)->toHaveCount(2)
        ->and($lines['1020']['debit_kobo'])->toBe(30000)
        ->and($lines['4010']['credit_kobo'])->toBe(30000)
        ->and($lines['4010']['account_name'])->toBe('Sales Revenue');

    $reversalResponse = $this->getJson('/api/v1/admin/accounting/journal/'.$reversal->id, [
        'Authorization' => 'Bearer '.$token,
    ]);

    $reversalResponse->assertOk()
        ->assertJsonPath('data.entry.reversal.is_reversal', true)
        ->assertJsonPath('data.entry.reversal.reversal_of.entry_number', $entry->entry_number);

    // A tenant's entry is invisible from the platform console.
    [$owner, $business] = createBusinessOwner();
    $tenantEntry = app(LedgerPostingService::class)->post($business->id, [
        ['account_key' => 'bank', 'debit' => 100],
        ['account_key' => 'sales_income', 'credit' => 100],
    ]);

    $this->getJson('/api/v1/admin/accounting/journal/'.$tenantEntry->id, [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404);

    $this->getJson('/api/v1/admin/accounting/journal/99999999', [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404);
});

test('the profit and loss report totals platform income and expenses and accepts the legacy alias', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    ad13Post([
        ['account_key' => 'bank', 'debit' => 200000],
        ['account_key' => 'sales_income', 'credit' => 200000],
    ], ['memo' => 'Subscriptions']);

    ad13Post([
        ['account_key' => 'default_expense', 'debit' => 50000],
        ['account_key' => 'bank', 'credit' => 50000],
    ], ['memo' => 'Hosting']);

    $response = $this->getJson('/api/v1/admin/accounting/reports?report=pnl', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.report', 'pnl')
        ->assertJsonPath('data.statement.total_income', 200000)
        ->assertJsonPath('data.statement.total_expenses', 50000)
        ->assertJsonPath('data.statement.net_profit', 150000);

    $income = collect($response->json('data.statement.income'))->keyBy('code');
    $expenses = collect($response->json('data.statement.expenses'))->keyBy('code');

    expect($income['4010']['amount'])->toBe(200000)
        ->and($income['4010']['name'])->toBe('Sales Revenue')
        ->and($expenses['5900']['amount'])->toBe(50000);

    // The legacy dashed alias still resolves.
    $this->getJson('/api/v1/admin/accounting/reports?report=profit-and-loss', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->assertJsonPath('data.report', 'pnl');
});

test('the balance sheet and trial balance tie to the ledger and surface their balanced flags', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    ad13Post([
        ['account_key' => 'bank', 'debit' => 200000],
        ['account_key' => 'sales_income', 'credit' => 200000],
    ]);

    ad13Post([
        ['account_key' => 'default_expense', 'debit' => 50000],
        ['account_key' => 'bank', 'credit' => 50000],
    ]);

    $this->getJson('/api/v1/admin/accounting/reports?report=balance_sheet', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('data.report', 'balance_sheet')
        ->assertJsonPath('data.statement.total_assets', 150000)
        ->assertJsonPath('data.statement.total_liabilities', 0)
        ->assertJsonPath('data.statement.total_equity', 150000)
        ->assertJsonPath('data.statement.net_profit', 150000)
        ->assertJsonPath('data.statement.balanced', true);

    $this->getJson('/api/v1/admin/accounting/reports?report=trial_balance', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('data.report', 'trial_balance')
        ->assertJsonPath('data.statement.total_debit', 250000)
        ->assertJsonPath('data.statement.total_credit', 250000)
        ->assertJsonPath('data.statement.balanced', true);

    // As-of dates window the balance sheet; before any entry the books are
    // empty and still balance.
    $this->getJson('/api/v1/admin/accounting/reports?report=balance-sheet&as_of=2000-01-01', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('data.statement.total_assets', 0)
        ->assertJsonPath('data.statement.balanced', true);
});

test('the report and journal filters reject unknown reports and malformed dates', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    $this->getJson('/api/v1/admin/accounting/reports?report=bogus', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('report');

    $this->getJson('/api/v1/admin/accounting/reports?from=2025-05-01&to=2025-01-01', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/admin/accounting/reports?as_of=not-a-date', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('as_of');
});

test('the settings screen exposes the platform chart, the 20 mappings and the fiscal calendar without tenant data', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    // A business with its own full chart must not add a single row here.
    [$owner, $business] = createBusinessOwner();
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    $response = $this->getJson('/api/v1/admin/accounting/settings', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk();

    expect($response->json('data.accounts'))->toHaveCount(25)
        ->and($response->json('data.mappings'))->toHaveCount(20)
        ->and($response->json('data.periods'))->toHaveCount(12)
        ->and($response->json('data.fiscal_years'))->toHaveCount(1);

    $mappings = collect($response->json('data.mappings'))->keyBy('key');
    expect($mappings['sales_income']['label'])->toBe('Subscription Revenue')
        ->and($mappings['sales_income']['default_code'])->toBe('4010')
        ->and($mappings['sales_income']['account_code'])->toBe('4010')
        ->and($mappings['cash']['default_code'])->toBe('1010');

    expect($response->json('data.fiscal_years.0.name'))->toBe((string) now()->year)
        ->and($response->json('data.fiscal_years.0.status'))->toBe('open')
        ->and($response->json('data.fiscal_years.0.can_close'))->toBeTrue();
});

test('mapping saves accept platform accounts only and audit the change', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    app(LedgerSetupService::class)->ensureForBusiness(null);

    $serviceCharge = ad13PlatformAccount('4020');

    $this->putJson('/api/v1/admin/accounting/settings/mappings', [
        'mappings' => ['sales_income' => $serviceCharge->id],
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('message', 'Platform account mappings updated.');

    $mapping = LedgerMapping::query()->whereNull('business_id')->where('key', 'sales_income')->first();
    expect($mapping)->not->toBeNull()->and((int) $mapping->ledger_account_id)->toBe($serviceCharge->id);

    $log = ActivityLog::query()->where('action', 'accounting_mappings_updated')->first();
    expect($log)->not->toBeNull()->and($log->user_id)->toBe($admin->id);

    // A typo'd key is refused instead of writing a junk row.
    $this->putJson('/api/v1/admin/accounting/settings/mappings', [
        'mappings' => ['subscription_revenue' => $serviceCharge->id],
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mappings');

    // An account belonging to a tenant's chart is not a platform account.
    [$owner, $business] = createBusinessOwner();
    app(LedgerSetupService::class)->ensureForBusiness($business->id);
    $tenantAccount = LedgerAccount::query()->where('business_id', $business->id)->where('code', '4010')->firstOrFail();

    $this->putJson('/api/v1/admin/accounting/settings/mappings', [
        'mappings' => ['sales_income' => $tenantAccount->id],
    ], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mappings.sales_income');

    $this->putJson('/api/v1/admin/accounting/settings/mappings', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(422)->assertJsonValidationErrors('mappings');
});

test('closing and reopening fiscal periods follows the platform-only guard', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);

    app(LedgerSetupService::class)->ensureForBusiness(null);

    $period = FiscalPeriod::query()
        ->whereNull('business_id')
        ->where('name', now()->format('Y-m'))
        ->firstOrFail();

    $this->postJson('/api/v1/admin/accounting/settings/periods/'.$period->id.'/close', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->assertJsonPath('data.period.status', 'closed');

    expect($period->fresh()->status)->toBe('closed')
        ->and($period->fresh()->closed_by)->toBe($admin->id);

    // Closing twice is a clean 422, not the legacy silent no-op.
    $this->postJson('/api/v1/admin/accounting/settings/periods/'.$period->id.'/close', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(422);

    // A closed period rejects new postings — the guard the acceptance criteria
    // call out is the posting engine's, and it fires from here.
    expect(fn () => app(LedgerPostingService::class)->post(null, [
        ['account_key' => 'bank', 'debit' => 100],
        ['account_key' => 'sales_income', 'credit' => 100],
    ], ['date' => now()->toDateString()]))->toThrow(RuntimeException::class);

    $this->postJson('/api/v1/admin/accounting/settings/periods/'.$period->id.'/reopen', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->assertJsonPath('data.period.status', 'open');

    $this->postJson('/api/v1/admin/accounting/settings/periods/'.$period->id.'/reopen', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(422);

    expect(ActivityLog::query()->where('action', 'accounting_period_closed')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'accounting_period_reopened')->exists())->toBeTrue();

    // A tenant's period 404s — the platform console never touches it.
    [$owner, $business] = createBusinessOwner();
    app(LedgerSetupService::class)->ensureForBusiness($business->id);
    $tenantPeriod = FiscalPeriod::query()->where('business_id', $business->id)->firstOrFail();

    $this->postJson('/api/v1/admin/accounting/settings/periods/'.$tenantPeriod->id.'/close', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404);

    expect($tenantPeriod->fresh()->status)->toBe('open');

    $this->postJson('/api/v1/admin/accounting/settings/periods/99999999/close', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404);
});

test('closing a fiscal year posts the net result to retained earnings and locks the calendar', function () {
    $admin = ad13SuperAdmin();
    $token = ad13AdminToken($admin);
    $year = now()->year;

    ad13Post([
        ['account_key' => 'bank', 'debit' => 300000],
        ['account_key' => 'sales_income', 'credit' => 300000],
    ], ['memo' => 'Year revenue']);

    ad13Post([
        ['account_key' => 'default_expense', 'debit' => 100000],
        ['account_key' => 'bank', 'credit' => 100000],
    ], ['memo' => 'Year expense']);

    $response = $this->postJson('/api/v1/admin/accounting/settings/years/'.$year.'/close', [], [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.fiscal_year.status', 'closed')
        ->assertJsonPath('message', "Fiscal year {$year} closed — net result transferred to retained earnings.");

    $closing = JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'year_close:'.$year)
        ->first();

    expect($closing)->not->toBeNull()
        ->and($closing->memo)->toContain('Year-end close');

    $retained = ad13PlatformAccount('3020');
    $credit = (int) JournalLine::query()
        ->where('journal_entry_id', $closing->id)
        ->where('ledger_account_id', $retained->id)
        ->sum('credit_kobo');

    expect($credit)->toBe(200000);

    // Every period of the closed year is locked. (The closing entry itself is
    // dated the first day of the following year, so the next year's periods
    // are intentionally left open.)
    $fiscalYearId = FiscalYear::query()->whereNull('business_id')->where('name', (string) $year)->value('id');
    expect(FiscalPeriod::query()->where('fiscal_year_id', $fiscalYearId)->where('status', 'open')->count())->toBe(0);

    // A second close refuses; unknown and malformed years 404.
    $this->postJson('/api/v1/admin/accounting/settings/years/'.$year.'/close', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(422);

    $this->postJson('/api/v1/admin/accounting/settings/years/1999/close', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404);

    $this->postJson('/api/v1/admin/accounting/settings/years/not-a-year/close', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404);
});

test('platform accounting refuses non-platform, unpermitted, wrong-audience and guest callers', function () {
    // A business-scoped account whose in-business "Super Admin" role bundles
    // the admin.* permission names — including admin.accounting — still
    // cannot touch the platform books.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.accounting'))->toBeTrue();

    $this->getJson('/api/v1/admin/accounting', [
        'Authorization' => 'Bearer '.ad13AdminToken($owner),
    ])->assertStatus(403);

    $this->getJson('/api/v1/admin/accounting/settings', [
        'Authorization' => 'Bearer '.ad13AdminToken($owner),
    ])->assertStatus(403);

    // A platform admin whose role lacks admin.accounting is stopped by the gate.
    $supportAdmin = ad13SupportAdmin();

    $this->getJson('/api/v1/admin/accounting', [
        'Authorization' => 'Bearer '.ad13AdminToken($supportAdmin),
    ])->assertStatus(403);

    // A management-audience token never reaches an admin route.
    $managementToken = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/accounting', ['Authorization' => 'Bearer '.$managementToken])
        ->assertStatus(403);

    // Guests are unauthenticated.
    $this->getJson('/api/v1/admin/accounting')->assertStatus(401);
    $this->getJson('/api/v1/admin/accounting/journal')->assertStatus(401);
    $this->postJson('/api/v1/admin/accounting/settings/years/2026/close')->assertStatus(401);

    // A platform admin with the seeded role passes.
    $platformAdmin = ad13PlatformAdmin();

    $this->getJson('/api/v1/admin/accounting', [
        'Authorization' => 'Bearer '.ad13AdminToken($platformAdmin),
    ])->assertOk();
});
