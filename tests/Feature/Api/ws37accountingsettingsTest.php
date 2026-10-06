<?php

use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Models\StoreBank;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| WS-37 — Accounting Settings, Closing & Reconciliation
|--------------------------------------------------------------------------
*/

function ws37Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/** Bootstrap the default chart of accounts + fiscal periods for a business. */
function ws37EnsureBooks(int $businessId): void
{
    app(LedgerSetupService::class)->ensureForBusiness($businessId);
}

function ws37Account(int $businessId, string $code): LedgerAccount
{
    return LedgerAccount::query()
        ->where('business_id', $businessId)
        ->where('code', $code)
        ->firstOrFail();
}

/** Post a balanced entry through the real posting service. */
function ws37Post(int $businessId, array $lines, array $options = []): JournalEntry
{
    return app(LedgerPostingService::class)->post($businessId, $lines, $options);
}

/** Build a statement import straight in the database for workflow tests. */
function ws37Import(User $owner, LedgerAccount $account, array $attributes = []): BankStatementImport
{
    return BankStatementImport::create(array_merge([
        'business_id' => $owner->business_id,
        'ledger_account_id' => $account->id,
        'status' => 'imported',
        'imported_by' => $owner->id,
    ], $attributes));
}

function ws37Line(BankStatementImport $import, string $date, int $amountKobo, array $attributes = []): BankStatementLine
{
    return BankStatementLine::create(array_merge([
        'bank_statement_import_id' => $import->id,
        'business_id' => $import->business_id,
        'transaction_date' => $date,
        'description' => 'Statement line',
        'amount_kobo' => $amountKobo,
        'status' => 'unmatched',
    ], $attributes));
}

/** A staff user with one of the seeded business roles. */
function ws37Staff(int $businessId, string $role): User
{
    $user = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $businessId,
    ]);

    setPermissionsTeamId($businessId);
    $user->assignRole($role);

    return $user->fresh();
}

/** A fake statement upload. */
function ws37Statement(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

// ---------------------------------------------------------------------------
// Settings — mappings
// ---------------------------------------------------------------------------

test('the settings screen returns the 20 mapping keys, periods and fiscal years', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $response = $this->withToken(ws37Token($owner))->getJson('/api/v1/management/accounting/settings');

    $response->assertOk()
        ->assertJsonCount(20, 'data.mappings')
        ->assertJsonPath('data.mappings.0.key', 'cash')
        ->assertJsonPath('data.mappings.0.default_code', '1010')
        ->assertJsonPath('data.mappings.0.account_code', '1010')
        ->assertJsonPath('data.opening_balances.posted', false)
        ->assertJsonPath('data.fiscal_years.0.name', (string) now()->year)
        ->assertJsonCount(12, 'data.periods');

    expect(collect($response->json('data.mappings'))->pluck('key')->all())
        ->toContain('retained_earnings', 'opening_balance_equity', 'default_expense');
});

test('mappings can be updated and are validated against the template keys', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $token = ws37Token($owner);
    $expense = ws37Account($business->id, '5900');

    $this->withToken($token)->putJson('/api/v1/management/accounting/settings/mappings', [
        'mappings' => ['cash' => $expense->id],
    ])->assertOk();

    expect((int) LedgerMapping::where('business_id', $business->id)->where('key', 'cash')->value('ledger_account_id'))
        ->toBe($expense->id);

    // A typo'd key no longer creates a junk mapping row (legacy wrote any key).
    $this->withToken($token)->putJson('/api/v1/management/accounting/settings/mappings', [
        'mappings' => ['cahs' => $expense->id],
    ])->assertStatus(422)->assertJsonValidationErrors('mappings');

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($otherBusiness->id);

    $this->withToken($token)->putJson('/api/v1/management/accounting/settings/mappings', [
        'mappings' => ['cash' => ws37Account($otherBusiness->id, '1010')->id],
    ])->assertStatus(422)->assertJsonValidationErrors('mappings.cash');
});

// ---------------------------------------------------------------------------
// Settings — opening balances
// ---------------------------------------------------------------------------

test('opening balances post once, balance through opening balance equity, and refuse a second posting', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $token = ws37Token($owner);
    $asOf = now()->startOfMonth()->addDays(2)->toDateString();

    $response = $this->withToken($token)->postJson('/api/v1/management/accounting/settings/opening-balances', [
        'as_of' => $asOf,
        'cash_kobo' => 500000,
        'bank_kobo' => 250000,
        'accounts_payable_kobo' => 200000,
        'owner_equity_kobo' => 100000,
    ]);

    $response->assertCreated()->assertJsonPath('data.opening_balances.posted', true);

    $entry = JournalEntry::query()
        ->where('business_id', $business->id)
        ->where('idempotency_key', 'like', 'opening:%')
        ->firstOrFail();

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->entry_date->toDateString())->toBe($asOf);

    $lines = $entry->lines()->get()->keyBy('ledger_account_id');

    expect((int) $lines[ws37Account($business->id, '1010')->id]->debit_kobo)->toBe(500000)
        ->and((int) $lines[ws37Account($business->id, '1020')->id]->debit_kobo)->toBe(250000)
        ->and((int) $lines[ws37Account($business->id, '2010')->id]->credit_kobo)->toBe(200000)
        ->and((int) $lines[ws37Account($business->id, '3010')->id]->credit_kobo)->toBe(100000)
        // Cash + bank (750,000) less payables + equity (300,000) = 450,000.
        ->and((int) $lines[ws37Account($business->id, '3030')->id]->credit_kobo)->toBe(450000);

    $this->withToken($token)->postJson('/api/v1/management/accounting/settings/opening-balances', [
        'as_of' => $asOf,
        'cash_kobo' => 1,
    ])->assertStatus(422)->assertJsonPath('message', 'Opening balances have already been posted.');

    expect(JournalEntry::query()->where('business_id', $business->id)->where('idempotency_key', 'like', 'opening:%')->count())
        ->toBe(1);
});

test('opening balances require at least one amount and non-negative kobo values', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $token = ws37Token($owner);

    $this->withToken($token)->postJson('/api/v1/management/accounting/settings/opening-balances', [
        'as_of' => now()->toDateString(),
    ])->assertStatus(422)->assertJsonPath('message', 'Enter at least one opening balance.');

    $this->withToken($token)->postJson('/api/v1/management/accounting/settings/opening-balances', [
        'as_of' => now()->toDateString(),
        'cash_kobo' => -5,
    ])->assertStatus(422)->assertJsonValidationErrors('cash_kobo');

    $this->withToken($token)->postJson('/api/v1/management/accounting/settings/opening-balances', [
        'cash_kobo' => 100,
    ])->assertStatus(422)->assertJsonValidationErrors('as_of');
});

// ---------------------------------------------------------------------------
// Settings — fiscal periods
// ---------------------------------------------------------------------------

test('a closed period rejects new ledger entries until it is reopened', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $token = ws37Token($owner);
    $period = FiscalPeriod::query()
        ->where('business_id', $business->id)
        ->where('name', now()->format('Y-m'))
        ->firstOrFail();

    $this->withToken($token)->postJson("/api/v1/management/accounting/settings/periods/{$period->id}/close")
        ->assertOk()
        ->assertJsonPath('data.period.status', 'closed');

    $message = null;

    try {
        ws37Post($business->id, [
            ['account_key' => 'cash', 'debit' => 10000],
            ['account_key' => 'sales_income', 'credit' => 10000],
        ], ['date' => now()->toDateString()]);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBeNull('Posting into a closed period should have thrown.')
        ->and($message)->toContain('closed');

    // Closing twice is refused; reopening restores posting.
    $this->withToken($token)->postJson("/api/v1/management/accounting/settings/periods/{$period->id}/close")
        ->assertStatus(422);

    $this->withToken($token)->postJson("/api/v1/management/accounting/settings/periods/{$period->id}/reopen")
        ->assertOk()
        ->assertJsonPath('data.period.status', 'open');

    $entry = ws37Post($business->id, [
        ['account_key' => 'cash', 'debit' => 10000],
        ['account_key' => 'sales_income', 'credit' => 10000],
    ], ['date' => now()->toDateString()]);

    expect($entry)->not->toBeNull();

    $this->withToken($token)->postJson("/api/v1/management/accounting/settings/periods/{$period->id}/reopen")
        ->assertStatus(422);
});

test('opening balances are refused when the target period is closed', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $token = ws37Token($owner);
    $period = FiscalPeriod::query()
        ->where('business_id', $business->id)
        ->where('name', now()->format('Y-m'))
        ->firstOrFail();

    $this->withToken($token)->postJson("/api/v1/management/accounting/settings/periods/{$period->id}/close")->assertOk();

    $this->withToken($token)->postJson('/api/v1/management/accounting/settings/opening-balances', [
        'as_of' => now()->toDateString(),
        'cash_kobo' => 100000,
    ])->assertStatus(422);
});

test('a fiscal period belonging to another business cannot be closed', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($otherBusiness->id);

    $foreign = FiscalPeriod::query()->where('business_id', $otherBusiness->id)->firstOrFail();

    $this->withToken(ws37Token($owner))
        ->postJson("/api/v1/management/accounting/settings/periods/{$foreign->id}/close")
        ->assertStatus(403);

    expect($foreign->fresh()->status)->toBe('open');
});

// ---------------------------------------------------------------------------
// Settings — year close
// ---------------------------------------------------------------------------

test('closing a year moves the net result to retained earnings and locks the periods', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $year = (int) now()->year;
    $incomeDate = "{$year}-03-15";
    $expenseDate = "{$year}-04-10";

    ws37Post($business->id, [
        ['account_key' => 'cash', 'debit' => 100000],
        ['account_key' => 'sales_income', 'credit' => 100000],
    ], ['date' => $incomeDate]);

    ws37Post($business->id, [
        ['account_key' => 'default_expense', 'debit' => 40000],
        ['account_key' => 'cash', 'credit' => 40000],
    ], ['date' => $expenseDate]);

    $response = $this->withToken(ws37Token($owner))
        ->postJson("/api/v1/management/accounting/settings/years/{$year}/close");

    $response->assertOk()
        ->assertJsonPath('data.fiscal_year.status', 'closed');

    expect($response->json('data.entry.memo'))->toContain("Year-end close {$year}");

    $entry = JournalEntry::query()->where('business_id', $business->id)->where('idempotency_key', "year_close:{$year}")->firstOrFail();
    $lines = $entry->lines()->get()->keyBy('ledger_account_id');

    // Income is zeroed with a debit, expenses with a credit, net 60,000 to
    // retained earnings.
    expect((int) $lines[ws37Account($business->id, '4010')->id]->debit_kobo)->toBe(100000)
        ->and((int) $lines[ws37Account($business->id, '5900')->id]->credit_kobo)->toBe(40000)
        ->and((int) $lines[ws37Account($business->id, '3020')->id]->credit_kobo)->toBe(60000);

    $fiscalYear = FiscalYear::query()->where('business_id', $business->id)->where('name', (string) $year)->firstOrFail();

    // Every period of the closed year is locked (the closing entry itself
    // lands in next year's January, which stays open).
    expect($fiscalYear->status)->toBe('closed')
        ->and(FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', '!=', 'closed')->count())->toBe(0);

    // A year already closed refuses, and an unknown year is a 404.
    $this->withToken(ws37Token($owner))
        ->postJson("/api/v1/management/accounting/settings/years/{$year}/close")
        ->assertStatus(422);

    $this->withToken(ws37Token($owner))
        ->postJson('/api/v1/management/accounting/settings/years/1999/close')
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------

test('accounting settings and year close routes require their legacy permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $period = FiscalPeriod::query()->where('business_id', $business->id)->firstOrFail();

    // The Accountant role holds accounting view but neither settings nor close.
    $accountant = ws37Staff($business->id, 'Accountant');
    $accountantToken = ws37Token($accountant);

    $this->withToken($accountantToken)->getJson('/api/v1/management/accounting/settings')->assertOk();
    $this->withToken($accountantToken)->putJson('/api/v1/management/accounting/settings/mappings', [
        'mappings' => ['cash' => ws37Account($business->id, '1010')->id],
    ])->assertStatus(403);
    $this->withToken($accountantToken)->postJson('/api/v1/management/accounting/settings/opening-balances', [
        'as_of' => now()->toDateString(),
        'cash_kobo' => 100,
    ])->assertStatus(403);
    $this->withToken($accountantToken)->postJson("/api/v1/management/accounting/settings/periods/{$period->id}/close")->assertStatus(403);
    $this->withToken($accountantToken)->postJson('/api/v1/management/accounting/settings/years/'.now()->year.'/close')->assertStatus(403);
    $this->withToken($accountantToken)->getJson('/api/v1/management/accounting/reconciliation')->assertStatus(403);

    // A Cashier has no accounting permissions at all. Sanctum's guard memoises
    // the first user it resolves for the rest of the test, so drop the resolved
    // guards whenever the request carries a different user's token.
    $cashier = ws37Staff($business->id, 'Cashier');
    $this->app['auth']->forgetGuards();
    $this->withToken(ws37Token($cashier))->getJson('/api/v1/management/accounting/settings')->assertStatus(403);

    // The owner can close the year.
    $this->app['auth']->forgetGuards();
    $this->withToken(ws37Token($owner))
        ->postJson('/api/v1/management/accounting/settings/years/'.now()->year.'/close')
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Reconciliation — import
// ---------------------------------------------------------------------------

test('a statement import parses comma and naira tolerant rows into signed kobo', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $account = ws37Account($business->id, '1020');

    $csv = implode("\n", [
        'Date,Description,Reference,Amount',
        '03/12/2026,POS settlement,REF-001,"₦1,250.50"',
        '04/12/2026,Bank charge,REF-002,(250.00)',
        '2026-12-05,Direct transfer,REF-003,-1000',
        '31/12/2026,Month end credit,REF-004,2000',
    ]);

    $response = $this->withToken(ws37Token($owner))->post(
        '/api/v1/management/accounting/reconciliation',
        [
            'ledger_account_id' => $account->id,
            'statement_date' => '2026-12-31',
            'closing_balance_kobo' => 500000,
            'file' => ws37Statement('statement.csv', $csv),
        ],
        ['Accept' => 'application/json'],
    );

    $response->assertCreated()->assertJsonPath('data.import.lines_count', 4);

    $import = BankStatementImport::query()->where('business_id', $business->id)->firstOrFail();

    expect($import->status)->toBe('imported')
        ->and((int) $import->closing_balance_kobo)->toBe(500000);

    $lines = $import->lines()->orderBy('id')->get();

    expect($lines)->toHaveCount(4)
        ->and((int) $lines[0]->amount_kobo)->toBe(125050)
        ->and((int) $lines[1]->amount_kobo)->toBe(-25000)
        ->and((int) $lines[2]->amount_kobo)->toBe(-100000)
        ->and((int) $lines[3]->amount_kobo)->toBe(200000)
        // Day-first: 03/12/2026 is 3 December, not 12 March.
        ->and($lines[0]->transaction_date->toDateString())->toBe('2026-12-03')
        ->and($lines[3]->reference)->toBe('REF-004');

    Storage::disk('public')->assertExists($import->file_path);
});

test('a statement with separate debit and credit columns is understood', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $csv = implode("\n", [
        'Date,Narration,Reference,Debit,Credit',
        '05/07/2026,Transfer in,REF-100,,"5,000.00"',
        '06/07/2026,Transfer out,REF-101,"1,250.00",',
    ]);

    $this->withToken(ws37Token($owner))->post(
        '/api/v1/management/accounting/reconciliation',
        [
            'ledger_account_id' => ws37Account($business->id, '1020')->id,
            'file' => ws37Statement('statement.csv', $csv),
        ],
        ['Accept' => 'application/json'],
    )->assertCreated()->assertJsonPath('data.import.lines_count', 2);

    $amounts = BankStatementLine::query()
        ->where('business_id', $business->id)
        ->orderBy('id')
        ->pluck('amount_kobo')
        ->map(fn ($amount) => (int) $amount)
        ->all();

    expect($amounts)->toBe([500000, -125000]);
});

test('a headerless three column statement still parses', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $csv = '05/07/2026,Card settlement,750.00';

    $this->withToken(ws37Token($owner))->post(
        '/api/v1/management/accounting/reconciliation',
        [
            'ledger_account_id' => ws37Account($business->id, '1020')->id,
            'file' => ws37Statement('statement.csv', $csv),
        ],
        ['Accept' => 'application/json'],
    )->assertCreated()->assertJsonPath('data.import.lines_count', 1);

    expect((int) BankStatementLine::query()->where('business_id', $business->id)->value('amount_kobo'))->toBe(75000);
});

test('an import is refused when the file has no parseable rows or the wrong type', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $account = ws37Account($business->id, '1020');
    $token = ws37Token($owner);

    $this->withToken($token)->post(
        '/api/v1/management/accounting/reconciliation',
        [
            'ledger_account_id' => $account->id,
            'file' => ws37Statement('statement.csv', "not,a,statement\n"),
        ],
        ['Accept' => 'application/json'],
    )->assertStatus(422)->assertJsonValidationErrors('file');

    $this->withToken($token)->post(
        '/api/v1/management/accounting/reconciliation',
        [
            'ledger_account_id' => $account->id,
            'file' => UploadedFile::fake()->create('statement.exe', 10, 'application/x-msdownload'),
        ],
        ['Accept' => 'application/json'],
    )->assertStatus(422)->assertJsonValidationErrors('file');

    expect(BankStatementImport::query()->where('business_id', $business->id)->count())->toBe(0);
});

test('an import only accepts this business bank accounts and store banks', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($otherBusiness->id);

    $token = ws37Token($owner);
    $csv = "Date,Description,Amount\n05/07/2026,Transfer,1000";

    $call = fn (array $payload) => $this->withToken($token)->post(
        '/api/v1/management/accounting/reconciliation',
        [...$payload, 'file' => ws37Statement('statement.csv', $csv)],
        ['Accept' => 'application/json'],
    );

    // Another business's ledger account.
    $call(['ledger_account_id' => ws37Account($otherBusiness->id, '1020')->id])
        ->assertStatus(422)->assertJsonValidationErrors('ledger_account_id');

    // An expense account is not a bank/cash/gateway account.
    $call(['ledger_account_id' => ws37Account($business->id, '5900')->id])
        ->assertStatus(422)->assertJsonValidationErrors('ledger_account_id');

    // Another business's store bank.
    $theirBank = StoreBank::create([
        'business_id' => $otherBusiness->id,
        'bank_name' => 'Their Bank',
        'bank_code' => '999',
        'account_number' => '0000000000',
        'account_name' => 'Them',
    ]);

    $call(['ledger_account_id' => ws37Account($business->id, '1020')->id, 'store_bank_id' => $theirBank->id])
        ->assertStatus(422)->assertJsonValidationErrors('store_bank_id');
});

// ---------------------------------------------------------------------------
// Reconciliation — list and show
// ---------------------------------------------------------------------------

test('the reconciliation list returns only this business imports and completed reconciliations', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($otherBusiness->id);

    $mine = ws37Import($owner, ws37Account($business->id, '1020'));
    ws37Line($mine, '2026-07-01', 100000);
    ws37Line($mine, '2026-07-02', 100000, ['status' => 'matched']);

    $theirs = ws37Import($otherOwner, ws37Account($otherBusiness->id, '1020'));
    ws37Line($theirs, '2026-07-01', 50000);

    BankReconciliation::create([
        'business_id' => $business->id,
        'ledger_account_id' => ws37Account($business->id, '1020')->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'statement_closing_balance_kobo' => 300000,
        'cleared_balance_kobo' => 200000,
        'difference_kobo' => 100000,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $response = $this->withToken(ws37Token($owner))->getJson('/api/v1/management/accounting/reconciliation');

    $response->assertOk()
        ->assertJsonCount(1, 'data.imports')
        ->assertJsonPath('data.imports.0.lines_count', 2)
        ->assertJsonPath('data.imports.0.unmatched_count', 1)
        ->assertJsonPath('data.imports.0.matched_count', 1)
        ->assertJsonCount(1, 'data.reconciliations')
        ->assertJsonPath('data.reconciliations.0.difference_kobo', 100000)
        // Options for the import modal: this business's bank accounts only.
        ->assertJsonPath('data.bank_accounts.0.code', '1010');

    // Pest passes an Expectation wrapper into each()'s callback, so the raw
    // string never reaches in_array() — call the expectation method instead.
    expect(collect($response->json('data.bank_accounts'))->pluck('code')->all())
        ->each(fn ($code) => $code->toBeIn(['1010', '1020', '1030']));
});

test('the reconcile screen returns the ledger closing balance, lines and match candidates', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $account = ws37Account($business->id, '1020');

    ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 250000],
        ['account_key' => 'sales_income', 'credit' => 250000],
    ], ['date' => '2026-07-05']);

    // A credit on the bank account that a statement line can clear against.
    ws37Post($business->id, [
        ['account_key' => 'default_expense', 'debit' => 40000],
        ['account_key' => 'bank', 'credit' => 40000],
    ], ['date' => '2026-07-06']);

    $import = ws37Import($owner, $account, [
        'statement_date' => '2026-07-31',
        'closing_balance_kobo' => 500000,
    ]);
    ws37Line($import, '2026-07-08', 250000);

    $response = $this->withToken(ws37Token($owner))
        ->getJson("/api/v1/management/accounting/reconciliation/{$import->id}");

    $response->assertOk()
        ->assertJsonPath('data.summary.ledger_closing_kobo', 210000)
        ->assertJsonPath('data.summary.statement_closing_kobo', 500000)
        ->assertJsonPath('data.summary.difference_kobo', 290000)
        ->assertJsonPath('data.summary.unmatched_count', 1)
        ->assertJsonCount(2, 'data.candidates');

    // Candidates carry the signed amount so the picker can show direction.
    $amounts = collect($response->json('data.candidates'))->pluck('amount_kobo')->all();
    expect($amounts)->toContain(250000, -40000);
});

// ---------------------------------------------------------------------------
// Reconciliation — matching
// ---------------------------------------------------------------------------

test('auto-match clears exact amounts one-to-one inside a seven day window', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $account = ws37Account($business->id, '1020');

    ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 150000],
        ['account_key' => 'sales_income', 'credit' => 150000],
    ], ['date' => '2026-07-05']);

    ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 200000],
        ['account_key' => 'sales_income', 'credit' => 200000],
    ], ['date' => '2026-07-20']);

    $import = ws37Import($owner, $account);

    $near = ws37Line($import, '2026-07-08', 150000);
    $late = ws37Line($import, '2026-07-30', 200000);
    $wrongAmount = ws37Line($import, '2026-07-06', 175000);

    $this->withToken(ws37Token($owner))
        ->postJson("/api/v1/management/accounting/reconciliation/{$import->id}/auto-match")
        ->assertOk()
        ->assertJsonPath('data.matched', 1);

    $matchedId = $near->fresh()->matched_journal_line_id;

    expect($near->fresh()->status)->toBe('matched')
        ->and($matchedId)->not->toBeNull()
        ->and($late->fresh()->status)->toBe('unmatched')
        ->and($wrongAmount->fresh()->status)->toBe('unmatched')
        // It matched the 5 July posting, the only candidate in the window.
        ->and(JournalLine::find($matchedId)->entry->entry_date->toDateString())->toBe('2026-07-05');

    // Running again matches nothing: the ledger line is claimed.
    $this->withToken(ws37Token($owner))
        ->postJson("/api/v1/management/accounting/reconciliation/{$import->id}/auto-match")
        ->assertOk()
        ->assertJsonPath('data.matched', 0);
});

test('auto-match never reuses a journal line already claimed by another import', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $account = ws37Account($business->id, '1020');

    ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 150000],
        ['account_key' => 'sales_income', 'credit' => 150000],
    ], ['date' => '2026-07-05']);

    $first = ws37Import($owner, $account);
    ws37Line($first, '2026-07-08', 150000);

    $second = ws37Import($owner, $account);
    ws37Line($second, '2026-07-09', 150000);

    $this->withToken(ws37Token($owner))
        ->postJson("/api/v1/management/accounting/reconciliation/{$first->id}/auto-match")
        ->assertOk()->assertJsonPath('data.matched', 1);

    $this->withToken(ws37Token($owner))
        ->postJson("/api/v1/management/accounting/reconciliation/{$second->id}/auto-match")
        ->assertOk()->assertJsonPath('data.matched', 0);
});

test('statement lines can be matched, unmatched and ignored with tenant guards', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $account = ws37Account($business->id, '1020');

    $entry = ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 150000],
        ['account_key' => 'sales_income', 'credit' => 150000],
    ], ['date' => '2026-07-05']);
    $journalLine = $entry->lines()->where('ledger_account_id', $account->id)->firstOrFail();

    $import = ws37Import($owner, $account);
    $line = ws37Line($import, '2026-07-08', 150000);

    $token = ws37Token($owner);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$line->id}/match", [
            'journal_line_id' => $journalLine->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.line.status', 'matched');

    expect((int) $line->fresh()->matched_journal_line_id)->toBe($journalLine->id);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$line->id}/unmatch")
        ->assertOk()
        ->assertJsonPath('data.line.status', 'unmatched');

    expect($line->fresh()->matched_journal_line_id)->toBeNull();

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$line->id}/ignore")
        ->assertOk()
        ->assertJsonPath('data.line.status', 'ignored');

    // A journal line from another business is refused.
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($otherBusiness->id);

    $foreignEntry = ws37Post($otherBusiness->id, [
        ['account_key' => 'bank', 'debit' => 150000],
        ['account_key' => 'sales_income', 'credit' => 150000],
    ], ['date' => '2026-07-05']);
    $foreignLine = $foreignEntry->lines()->where('ledger_account_id', ws37Account($otherBusiness->id, '1020')->id)->firstOrFail();

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$line->id}/match", [
            'journal_line_id' => $foreignLine->id,
        ])
        ->assertStatus(422);

    // A statement line from another business is a 403.
    $foreignImport = ws37Import($otherOwner, ws37Account($otherBusiness->id, '1020'));
    $foreignStatementLine = ws37Line($foreignImport, '2026-07-08', 150000);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$foreignStatementLine->id}/ignore")
        ->assertStatus(403);
});

test('a line can only be matched to a ledger line on its own account and never twice', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $bank = ws37Account($business->id, '1020');

    $entry = ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 150000],
        ['account_key' => 'sales_income', 'credit' => 150000],
    ], ['date' => '2026-07-05']);
    $bankLine = $entry->lines()->where('ledger_account_id', $bank->id)->firstOrFail();

    // A posted line on a different account.
    $expenseEntry = ws37Post($business->id, [
        ['account_key' => 'default_expense', 'debit' => 25000],
        ['account_key' => 'cash', 'credit' => 25000],
    ], ['date' => '2026-07-05']);
    $expenseLine = $expenseEntry->lines()->where('ledger_account_id', ws37Account($business->id, '5900')->id)->firstOrFail();

    $import = ws37Import($owner, $bank);
    $lineA = ws37Line($import, '2026-07-08', 150000);
    $lineB = ws37Line($import, '2026-07-09', 150000);

    $token = ws37Token($owner);

    // Wrong account.
    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$lineA->id}/match", [
            'journal_line_id' => $expenseLine->id,
        ])
        ->assertStatus(422);

    // Correct match, then the same ledger line refused for a second line.
    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$lineA->id}/match", [
            'journal_line_id' => $bankLine->id,
        ])
        ->assertOk();

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$lineB->id}/match", [
            'journal_line_id' => $bankLine->id,
        ])
        ->assertStatus(422);

    expect($lineB->fresh()->status)->toBe('unmatched');
});

// ---------------------------------------------------------------------------
// Reconciliation — completion
// ---------------------------------------------------------------------------

test('completing a reconciliation snapshots the statement closing against the cleared balance', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $account = ws37Account($business->id, '1020');

    ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 250000],
        ['account_key' => 'sales_income', 'credit' => 250000],
    ], ['date' => '2026-07-05']);

    // Posted after the reconciliation window — must not count.
    ws37Post($business->id, [
        ['account_key' => 'bank', 'debit' => 90000],
        ['account_key' => 'sales_income', 'credit' => 90000],
    ], ['date' => '2026-08-05']);

    $import = ws37Import($owner, $account, ['closing_balance_kobo' => 300000]);
    ws37Line($import, '2026-07-08', 250000, ['status' => 'matched']);

    $token = ws37Token($owner);

    $response = $this->withToken($token)->postJson("/api/v1/management/accounting/reconciliation/{$import->id}/complete", [
        'statement_closing_balance_kobo' => 300000,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.reconciliation.statement_closing_balance_kobo', 300000)
        ->assertJsonPath('data.reconciliation.cleared_balance_kobo', 250000)
        ->assertJsonPath('data.reconciliation.difference_kobo', 50000)
        ->assertJsonPath('data.import.status', 'reconciled');

    expect($import->fresh()->status)->toBe('reconciled');

    // Completing again is refused, and the frozen snapshot rejects line edits.
    $this->withToken($token)->postJson("/api/v1/management/accounting/reconciliation/{$import->id}/complete", [
        'statement_closing_balance_kobo' => 300000,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ])->assertStatus(422);

    $line = $import->lines()->firstOrFail();

    $this->withToken($token)->postJson("/api/v1/management/accounting/reconciliation/lines/{$line->id}/ignore")
        ->assertStatus(422);

    $this->withToken($token)->postJson("/api/v1/management/accounting/reconciliation/{$import->id}/auto-match")
        ->assertStatus(422);
});

test('completing a reconciliation validates the period and the closing balance', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    $import = ws37Import($owner, ws37Account($business->id, '1020'));
    ws37Line($import, '2026-07-08', 100000);

    $token = ws37Token($owner);
    $url = "/api/v1/management/accounting/reconciliation/{$import->id}/complete";

    $this->withToken($token)->postJson($url, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ])->assertStatus(422)->assertJsonValidationErrors('statement_closing_balance_kobo');

    $this->withToken($token)->postJson($url, [
        'statement_closing_balance_kobo' => 100000,
        'start_date' => '2026-07-31',
        'end_date' => '2026-07-01',
    ])->assertStatus(422)->assertJsonValidationErrors('end_date');

    $this->withToken($token)->postJson($url, [
        'statement_closing_balance_kobo' => -1,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ])->assertStatus(422)->assertJsonValidationErrors('statement_closing_balance_kobo');
});

test('statement imports, lines and reconciliations from another business are not reachable', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($business->id);

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws37EnsureBooks($otherBusiness->id);

    $foreignImport = ws37Import($otherOwner, ws37Account($otherBusiness->id, '1020'));
    $foreignLine = ws37Line($foreignImport, '2026-07-08', 100000);

    $token = ws37Token($owner);

    $this->withToken($token)
        ->getJson("/api/v1/management/accounting/reconciliation/{$foreignImport->id}")
        ->assertStatus(403);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/{$foreignImport->id}/auto-match")
        ->assertStatus(403);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/{$foreignImport->id}/complete", [
            'statement_closing_balance_kobo' => 100,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertStatus(403);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/reconciliation/lines/{$foreignLine->id}/unmatch")
        ->assertStatus(403);

    // The owner's own list never shows the other business's import.
    $this->withToken($token)->getJson('/api/v1/management/accounting/reconciliation')
        ->assertOk()
        ->assertJsonCount(0, 'data.imports')
        ->assertJsonCount(0, 'data.reconciliations');
});
