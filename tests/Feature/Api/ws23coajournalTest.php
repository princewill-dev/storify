<?php

use App\Enums\TransactionStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-23 — Chart of Accounts & Journal Workflows
|--------------------------------------------------------------------------
*/

function ws23Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/** Bootstrap the default chart of accounts + fiscal periods for a business. */
function ws23EnsureBooks(int $businessId): void
{
    app(LedgerSetupService::class)->ensureForBusiness($businessId);
}

function ws23Account(int $businessId, string $code): LedgerAccount
{
    return LedgerAccount::query()
        ->where('business_id', $businessId)
        ->where('code', $code)
        ->firstOrFail();
}

/** Post a balanced entry through the real posting service. */
function ws23PostEntry(int $businessId, array $options = []): JournalEntry
{
    return app(LedgerPostingService::class)->post($businessId, [
        ['account_key' => 'cash', 'debit' => 150000],
        ['account_key' => 'sales_income', 'credit' => 150000],
    ], $options);
}

test('the accounts list returns sign-corrected balances, parent links and hides other businesses', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    ws23PostEntry($business->id, ['reference' => 'WS23-BAL']);

    // A parent account and a child pointing at it.
    $parent = LedgerAccount::create([
        'business_id' => $business->id,
        'code' => '5800',
        'name' => 'Repairs & Maintenance',
        'type' => LedgerAccount::TYPE_EXPENSE,
        'currency' => 'NGN',
        'is_system' => false,
        'is_active' => true,
    ]);

    LedgerAccount::create([
        'business_id' => $business->id,
        'code' => '5810',
        'name' => 'Vehicle Repairs',
        'type' => LedgerAccount::TYPE_EXPENSE,
        'parent_id' => $parent->id,
        'subtype' => 'repairs',
        'currency' => 'NGN',
        'is_system' => false,
        'is_active' => true,
    ]);

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($otherBusiness->id);

    $response = $this->withToken(ws23Token($owner))->getJson('/api/v1/management/accounting/accounts');

    $response->assertOk();
    $accounts = collect($response->json('data.accounts'))->keyBy('code');

    // Asset (debit-normal): debit - credit. Income (credit-normal): credit - debit.
    expect($accounts['1010']['balance_kobo'])->toBe(150000)
        ->and($accounts['4010']['balance_kobo'])->toBe(150000)
        ->and($accounts['5810']['parent']['code'])->toBe('5800')
        ->and($accounts['5800']['parent'])->toBeNull()
        ->and($accounts['1010']['is_system'])->toBeTrue();

    // The other business's seeded chart never leaks in: the payload holds
    // exactly this business's own rows.
    expect($accounts)->toHaveCount(LedgerAccount::where('business_id', $business->id)->count());
});

test('accounts can be created and updated with business-scoped validation', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $token = ws23Token($owner);
    $base = '/api/v1/management/accounting/accounts';

    $created = $this->withToken($token)->postJson($base, [
        'code' => '5999',
        'name' => 'Shop Cleaning',
        'type' => 'expense',
        'subtype' => 'cleaning',
        'description' => 'Weekly cleaners',
        'is_active' => true,
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.account.code', '5999')
        ->assertJsonPath('data.account.is_system', false);

    $id = $created->json('data.account.id');

    $this->withToken($token)->postJson($base, [
        'code' => '5999',
        'name' => 'Duplicate code',
        'type' => 'expense',
    ])->assertStatus(422)->assertJsonValidationErrors('code');

    $this->withToken($token)->postJson($base, [
        'code' => '5998',
        'name' => 'Bad type',
        'type' => 'revenue',
    ])->assertStatus(422)->assertJsonValidationErrors('type');

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($otherBusiness->id);

    $this->withToken($token)->postJson($base, [
        'code' => '5997',
        'name' => 'Foreign parent',
        'type' => 'expense',
        'parent_id' => ws23Account($otherBusiness->id, '5200')->id,
    ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

    $this->withToken($token)->putJson($base.'/'.$id, [
        'code' => '5999',
        'name' => 'Shop Cleaning & Sundries',
        'type' => 'expense',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.account.name', 'Shop Cleaning & Sundries')
        ->assertJsonPath('data.account.is_active', false);
});

test('system accounts cannot be deactivated through either the toggle or the update route', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $token = ws23Token($owner);
    $system = ws23Account($business->id, '1010');

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/accounts/{$system->id}/toggle")
        ->assertStatus(422)
        ->assertJsonPath('message', 'System accounts cannot be deactivated.');

    $this->withToken($token)->putJson("/api/v1/management/accounting/accounts/{$system->id}", [
        'code' => '1010',
        'name' => 'Cash on Hand',
        'type' => 'asset',
        'is_active' => false,
    ])->assertStatus(422)->assertJsonPath('message', 'System accounts cannot be deactivated.');

    expect($system->fresh()->is_active)->toBeTrue();

    $custom = LedgerAccount::create([
        'business_id' => $business->id,
        'code' => '5990',
        'name' => 'Owner Drawings',
        'type' => LedgerAccount::TYPE_EQUITY,
        'currency' => 'NGN',
        'is_system' => false,
        'is_active' => true,
    ]);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/accounts/{$custom->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.account.is_active', false);

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/accounts/{$custom->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.account.is_active', true);
});

test('an account cannot be parented to itself or to its own descendant', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $token = ws23Token($owner);
    $base = '/api/v1/management/accounting/accounts';

    $parentId = $this->withToken($token)->postJson($base, [
        'code' => '5700',
        'name' => 'Travel',
        'type' => 'expense',
    ])->json('data.account.id');

    $childId = $this->withToken($token)->postJson($base, [
        'code' => '5710',
        'name' => 'Local Travel',
        'type' => 'expense',
        'parent_id' => $parentId,
    ])->json('data.account.id');

    $this->withToken($token)->putJson("{$base}/{$parentId}", [
        'code' => '5700',
        'name' => 'Travel',
        'type' => 'expense',
        'parent_id' => $parentId,
    ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

    $this->withToken($token)->putJson("{$base}/{$parentId}", [
        'code' => '5700',
        'name' => 'Travel',
        'type' => 'expense',
        'parent_id' => $childId,
    ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
});

test('the journal list searches entry number, reference and memo and totals the filtered rows', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    ws23PostEntry($business->id, ['reference' => 'WS23-REF-ALPHA', 'memo' => 'September rent adjustment']);
    ws23PostEntry($business->id, ['reference' => 'WS23-REF-BETA', 'memo' => 'October float top-up']);

    $token = ws23Token($owner);
    $base = '/api/v1/management/accounting/journal';

    // Legacy searched entry_number only even though its placeholder promised
    // reference and memo too.
    $this->withToken($token)->getJson($base.'?q=WS23-REF-BETA')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'WS23-REF-BETA')
        ->assertJsonPath('data.0.total_credits', 150000);

    $this->withToken($token)->getJson($base.'?q='.urlencode('October float'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken($token)->getJson($base.'?q=WS23-REF-ALPHA')
        ->assertOk()
        ->assertJsonPath('meta.totals.debits', 150000)
        ->assertJsonPath('meta.totals.credits', 150000);

    $this->withToken($token)->getJson($base.'?status=posted')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($token)->getJson($base.'?status=draft')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($token)->getJson($base.'?from='.now()->toDateString().'&to='.now()->toDateString())
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($token)->getJson($base.'?from='.now()->addDay()->toDateString())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a balanced manual journal entry posts and stamps the fiscal period and poster', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $cash = ws23Account($business->id, '1010');
    $rent = ws23Account($business->id, '5200');

    $response = $this->withToken(ws23Token($owner))->postJson('/api/v1/management/accounting/journal', [
        'entry_date' => now()->toDateString(),
        'reference' => 'WS23-MANUAL-1',
        'memo' => 'Rent paid from petty cash',
        'lines' => [
            ['ledger_account_id' => $rent->id, 'description' => 'October rent', 'debit_kobo' => 750000],
            ['ledger_account_id' => $cash->id, 'description' => 'Paid out', 'credit_kobo' => 750000],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.entry.status', 'posted')
        ->assertJsonPath('data.entry.reference', 'WS23-MANUAL-1')
        ->assertJsonPath('data.entry.posted_by', $owner->name)
        ->assertJsonPath('data.entry.is_balanced', true)
        ->assertJsonPath('data.entry.total_debits', 750000)
        ->assertJsonPath('data.entry.total_credits', 750000);

    expect($response->json('data.entry.fiscal_period'))->toBe(now()->format('Y-m'));

    // The cash balance on the accounts list reflects the new posting.
    $accounts = collect($this->withToken(ws23Token($owner))
        ->getJson('/api/v1/management/accounting/accounts')
        ->json('data.accounts'))->keyBy('code');

    expect($accounts['1010']['balance_kobo'])->toBe(-750000)
        ->and($accounts['5200']['balance_kobo'])->toBe(750000);
});

test('posting refuses unbalanced, single-line, cross-business and debit-and-credit lines', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $cash = ws23Account($business->id, '1010');
    $rent = ws23Account($business->id, '5200');

    $token = ws23Token($owner);
    $base = '/api/v1/management/accounting/journal';

    $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $rent->id, 'debit_kobo' => 750000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 500000],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines');

    $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $rent->id, 'debit_kobo' => 750000],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines');

    $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $cash->id, 'debit_kobo' => 300000, 'credit_kobo' => 300000],
            ['ledger_account_id' => $rent->id, 'credit_kobo' => 300000],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.credit_kobo');

    $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => 999999, 'debit_kobo' => 1000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 1000],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines');

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($otherBusiness->id);

    $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => ws23Account($otherBusiness->id, '5200')->id, 'debit_kobo' => 1000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 1000],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines');

    // A line with no amount at all is dropped before validation.
    $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $rent->id],
            ['ledger_account_id' => $cash->id],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines');
});

test('a manual entry saves as a draft, posts later and deletes only while a draft', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $cash = ws23Account($business->id, '1010');
    $rent = ws23Account($business->id, '5200');

    $token = ws23Token($owner);
    $base = '/api/v1/management/accounting/journal';

    // An unbalanced draft is allowed — posting is what requires balance.
    $draft = $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'memo' => 'Half-finished entry',
        'save_as_draft' => true,
        'lines' => [
            ['ledger_account_id' => $rent->id, 'debit_kobo' => 100000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 60000],
        ],
    ]);

    $draft->assertCreated()->assertJsonPath('data.entry.status', 'draft');

    $draftId = $draft->json('data.entry.id');

    $this->withToken($token)
        ->getJson($base.'?status=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // An unbalanced draft refuses to post.
    $this->withToken($token)->postJson("{$base}/{$draftId}/post")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Debits and credits must match before posting.');

    $posted = $this->withToken($token)->postJson($base, [
        'entry_date' => now()->toDateString(),
        'memo' => 'Balanced draft',
        'save_as_draft' => true,
        'lines' => [
            ['ledger_account_id' => $rent->id, 'debit_kobo' => 200000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 200000],
        ],
    ])->json('data.entry.id');

    $this->withToken($token)->postJson("{$base}/{$posted}/post")
        ->assertOk()
        ->assertJsonPath('data.entry.status', 'posted')
        ->assertJsonPath('data.entry.is_balanced', true);

    expect(JournalEntry::find($posted)->posted_at)->not->toBeNull();

    // Posted entries cannot be deleted.
    $this->withToken($token)->deleteJson("{$base}/{$posted}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only draft entries can be deleted. Use reversal for posted entries.');

    $this->withToken($token)->deleteJson("{$base}/{$draftId}")->assertOk();

    expect(JournalEntry::find($draftId))->toBeNull();
});

test('reversing a posted entry voids the original and links the contra entry', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $original = ws23PostEntry($business->id, [
        'reference' => 'WS23-REV-1',
        'memo' => 'To be reversed',
    ]);

    $token = ws23Token($owner);
    $base = '/api/v1/management/accounting/journal';

    $response = $this->withToken($token)->postJson("{$base}/{$original->id}/reverse");

    $response->assertCreated()
        ->assertJsonPath('data.entry.reversal_of.id', $original->id)
        ->assertJsonPath('data.reversed.status', 'void')
        ->assertJsonPath('data.reversed.reversed_by.id', $response->json('data.entry.id'));

    // The reversal mirrors the original legs (debit becomes credit).
    $reversalId = $response->json('data.entry.id');
    $reversalLines = collect(JournalEntry::find($reversalId)->lines);

    expect((int) $reversalLines->sum('debit_kobo'))->toBe(150000)
        ->and((int) $reversalLines->sum('credit_kobo'))->toBe(150000);

    // The void original drops out of posted-only reports; only the contra counts.
    $totals = $this->withToken($token)->getJson($base.'?status=posted')->json('meta.totals');
    expect($totals['debits'])->toBe(150000)
        ->and($totals['credits'])->toBe(150000);

    // Reversing twice is refused — the original is void now.
    $this->withToken($token)->postJson("{$base}/{$original->id}/reverse")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only posted entries can be reversed.');

    // Another business cannot reverse it.
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // Every request in a test shares one application instance and the sanctum
    // RequestGuard caches the first user it resolves — without forgetting the
    // guards here this request would still be authenticated as the owner above
    // (and would reach the controller's status check instead of the 403).
    $this->app['auth']->forgetGuards();

    $this->withToken(ws23Token($otherOwner))
        ->postJson("{$base}/{$original->id}/reverse")
        ->assertStatus(403);
});

test('journal entries and accounts belonging to another business are not reachable', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($otherBusiness->id);

    $theirEntry = ws23PostEntry($otherBusiness->id);
    $theirAccount = ws23Account($otherBusiness->id, '5200');

    $token = ws23Token($owner);

    $this->withToken($token)->getJson("/api/v1/management/accounting/journal/{$theirEntry->id}")->assertStatus(403);
    $this->withToken($token)->deleteJson("/api/v1/management/accounting/journal/{$theirEntry->id}")->assertStatus(403);
    $this->withToken($token)->postJson("/api/v1/management/accounting/journal/{$theirEntry->id}/post")->assertStatus(403);
    $this->withToken($token)->postJson("/api/v1/management/accounting/journal/{$theirEntry->id}/reverse")->assertStatus(403);

    $this->withToken($token)->putJson("/api/v1/management/accounting/accounts/{$theirAccount->id}", [
        'code' => '5200',
        'name' => 'Hijack',
        'type' => 'expense',
    ])->assertStatus(403);

    $this->withToken($token)->postJson("/api/v1/management/accounting/accounts/{$theirAccount->id}/toggle")->assertStatus(403);

    // Their entry also stays out of this business's list.
    $this->withToken($token)->getJson('/api/v1/management/accounting/journal?q='.$theirEntry->entry_number)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the accounting dashboard returns cash balances, recent entries and the unposted warning', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $entry = ws23PostEntry($business->id, ['reference' => 'WS23-DASH']);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS23 Store',
        'slug' => 'ws23-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ]);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'WS23-ORD-1',
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'pending',
    ]);

    $unposted = Transaction::create([
        'reference' => 'WS23-TXN-UNPOSTED',
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'order_id' => $order->id,
        'business_id' => $business->id,
        'paid_at' => now(),
    ]);

    $response = $this->withToken(ws23Token($owner))->getJson('/api/v1/management/accounting/dashboard');

    $response->assertOk()
        ->assertJsonPath('data.assets', 150000)
        ->assertJsonPath('data.unposted_payments.count', 1)
        ->assertJsonStructure([
            'data' => [
                'assets', 'liabilities', 'equity', 'income_ytd', 'expenses_ytd', 'net_profit_ytd',
                'cash_balances', 'recent_entries', 'unposted_payments' => ['count', 'hint'],
            ],
        ]);

    $cash = collect($response->json('data.cash_balances'))->firstWhere('code', '1010');
    expect($cash['balance_kobo'])->toBe(150000)
        ->and($response->json('data.recent_entries.0.entry_number'))->toBe($entry->entry_number);

    // Posting the matching journal entry clears the warning — the backfill works.
    app(LedgerPostingService::class)->post($business->id, [
        ['account_key' => 'cash', 'debit' => 100000],
        ['account_key' => 'accounts_receivable', 'credit' => 100000],
    ], ['idempotency_key' => 'payment:txn:'.$unposted->id, 'reference' => $unposted->reference]);

    $this->withToken(ws23Token($owner))
        ->getJson('/api/v1/management/accounting/dashboard')
        ->assertOk()
        ->assertJsonPath('data.unposted_payments.count', 0);

    // Another business's unposted payment never shows up.
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $otherStore = Store::create([
        'user_id' => $otherOwner->id,
        'business_id' => $otherBusiness->id,
        'name' => 'WS23 Other Store',
        'slug' => 'ws23-other-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ]);
    $otherOrder = Order::create([
        'business_id' => $otherBusiness->id,
        'store_id' => $otherStore->id,
        'source' => 'checkout',
        'order_number' => 'WS23-ORD-2',
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 1000,
        'status' => 'pending',
    ]);
    Transaction::create([
        'reference' => 'WS23-TXN-OTHER',
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'order_id' => $otherOrder->id,
        'business_id' => $otherBusiness->id,
        'paid_at' => now(),
    ]);

    $this->withToken(ws23Token($owner))
        ->getJson('/api/v1/management/accounting/dashboard')
        ->assertOk()
        ->assertJsonPath('data.unposted_payments.count', 0);
});

test('the monthly series is the balance sheet and P&L run month by month', function () {
    // Pinned to the middle of a month so "the month in progress" is genuinely
    // in progress. Every expectation below is then exact rather than
    // conditional on the day the suite happens to run.
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $post = function (array $lines, Carbon $date, string $reference) use ($business) {
        return app(LedgerPostingService::class)->post($business->id, $lines, [
            'date' => $date->toDateString(),
            'reference' => $reference,
        ]);
    };

    // Opening balance: a year back, before the fetched range even starts, so
    // the running totals have to carry it forward rather than begin at zero.
    $post([
        ['account_key' => 'cash', 'debit' => 400000],
        ['account_key' => 'sales_income', 'credit' => 400000],
    ], now()->startOfMonth()->subMonths(12), 'WS23-OPENING');

    // The last month of the comparison window behind the charted one.
    $post([
        ['account_key' => 'cash', 'debit' => 300000],
        ['account_key' => 'sales_income', 'credit' => 300000],
    ], now()->startOfMonth()->subMonths(6), 'WS23-PREVIOUS');

    // Two months inside the charted window: May..Oct for a 6-month range.
    $post([
        ['account_key' => 'bank', 'debit' => 250000],
        ['account_key' => 'sales_income', 'credit' => 250000],
    ], now()->startOfMonth()->subMonths(4), 'WS23-MID');

    $post([
        ['account_key' => 'default_expense', 'debit' => 90000],
        ['account_key' => 'cash', 'credit' => 90000],
    ], now()->startOfMonth()->subMonths(3), 'WS23-EXPENSE');

    // The month in progress: 2026-10.
    $post([
        ['account_key' => 'cash', 'debit' => 120000],
        ['account_key' => 'sales_income', 'credit' => 120000],
    ], now(), 'WS23-CURRENT');

    $response = $this->withToken(ws23Token($owner))
        ->getJson('/api/v1/management/accounting/dashboard?months=6');

    $response->assertOk();

    $reports = app(LedgerReportService::class);
    $series = $response->json('data.series');
    $last = $series[count($series) - 1];

    // Six months back from October is May, so the chart opens on the 700,000
    // that the opening and the previous-window postings had already built up.
    expect($series)->toHaveCount(6)
        ->and(collect($series)->pluck('month')->all())
        ->toBe(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'])
        ->and($series[0]['assets'])->toBe(700000)
        ->and($series[0]['cash'])->toBe(700000);

    // The whole point of the series: the same arithmetic as balanceSheet(),
    // accumulated month by month. If the incremental copy ever drifts from the
    // reports, this is the assertion that fails.
    $sheet = $reports->balanceSheet($business->id, now()->toDateString());

    expect($sheet['total_assets'])->toBe(980000)
        ->and($last['assets'])->toBe($sheet['total_assets'])
        ->and($last['liabilities'])->toBe($sheet['total_liabilities'])
        ->and($last['equity'])->toBe($sheet['total_equity'])
        ->and($response->json('data.totals.assets'))->toBe($sheet['total_assets'])
        ->and($response->json('data.totals.liabilities'))->toBe($sheet['total_liabilities'])
        ->and($response->json('data.totals.equity'))->toBe($sheet['total_equity'])
        // All five postings landed in cash or bank.
        ->and($last['cash'])->toBe(980000)
        ->and($response->json('data.totals.cash'))->toBe(980000);

    $from = Carbon::parse($response->json('data.period.from'));

    expect($response->json('data.period.partial'))->toBeTrue()
        ->and($response->json('data.period.comparison_months'))->toBe(5)
        ->and($from->toDateString())->toBe('2026-05-01');

    // The month in progress is charted but held out of the compared flows — a
    // month fifteen days old must not be read against whole ones. So the flow
    // totals are a P&L over the five complete months, and October's 120,000
    // appears only in the point that carries the partial flag.
    $pl = $reports->profitAndLoss($business->id, '2026-05-01', '2026-09-30');

    expect($response->json('data.totals.income'))->toBe($pl['total_income'])
        ->and($response->json('data.totals.expenses'))->toBe($pl['total_expenses'])
        ->and($response->json('data.totals.net_profit'))->toBe($pl['net_profit'])
        ->and($response->json('data.totals.income'))->toBe(250000)
        ->and($response->json('data.totals.expenses'))->toBe(90000)
        ->and($response->json('data.totals.net_profit'))->toBe(160000)
        ->and($last['partial'])->toBeTrue()
        ->and($last['income'])->toBe(120000)
        ->and($series[0]['partial'])->toBeFalse();

    // The previous period is the equal-length window immediately behind this
    // one, and its stock half is the balance sheet as of the day before it.
    $previousEnd = $from->copy()->subDay();
    $previousStart = $previousEnd->copy()->subMonths(4)->startOfMonth();
    $previousPl = $reports->profitAndLoss($business->id, $previousStart->toDateString(), $previousEnd->toDateString());
    $previousSheet = $reports->balanceSheet($business->id, $previousEnd->toDateString());

    expect($previousEnd->toDateString())->toBe('2026-04-30')
        ->and($previousStart->toDateString())->toBe('2025-12-01')
        ->and($response->json('data.previous.income'))->toBe($previousPl['total_income'])
        ->and($response->json('data.previous.expenses'))->toBe($previousPl['total_expenses'])
        ->and($response->json('data.previous.net_profit'))->toBe($previousPl['net_profit'])
        ->and($response->json('data.previous.assets'))->toBe($previousSheet['total_assets'])
        ->and($response->json('data.previous.equity'))->toBe($previousSheet['total_equity'])
        // The April sale is the comparison window's, not this one's.
        ->and($response->json('data.previous.income'))->toBe(300000)
        ->and($response->json('data.previous.assets'))->toBe(700000)
        ->and($response->json('data.previous.net_profit'))->toBe(300000);

    // The year to date still reads straight from the P&L, as it always did.
    $ytd = $reports->profitAndLoss($business->id, '2026-01-01', '2026-10-15');

    expect($response->json('data.income_ytd'))->toBe($ytd['total_income'])
        ->and($response->json('data.net_profit_ytd'))->toBe($ytd['net_profit']);
});

test('the range picker drives the series width and the comparison window', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);
    ws23PostEntry($business->id, ['reference' => 'WS23-RANGE']);

    $token = ws23Token($owner);

    $three = $this->withToken($token)
        ->getJson('/api/v1/management/accounting/dashboard?months=3')
        ->assertOk();

    expect($three->json('data.series'))->toHaveCount(3)
        ->and($three->json('data.period.months'))->toBe(3)
        ->and(Carbon::parse($three->json('data.period.from'))->toDateString())
        ->toBe(now()->startOfMonth()->subMonths(2)->toDateString());

    // Out-of-range widths are clamped rather than rejected: the picker only
    // ever sends the presets, so anything else is a hand-made request.
    $none = $this->withToken($token)
        ->getJson('/api/v1/management/accounting/dashboard?months=0')
        ->assertOk();

    expect($none->json('data.period.months'))->toBe(1)
        ->and($none->json('data.series'))->toHaveCount(1);

    $huge = $this->withToken($token)
        ->getJson('/api/v1/management/accounting/dashboard?months=99')
        ->assertOk();

    expect($huge->json('data.period.months'))->toBe(24)
        ->and($huge->json('data.series'))->toHaveCount(24);

    // No parameter at all is the six-month default the page opens on.
    $default = $this->withToken($token)
        ->getJson('/api/v1/management/accounting/dashboard')
        ->assertOk();

    expect($default->json('data.period.months'))->toBe(6);

    // Every point is the shape the charts bind to, including months with no
    // activity — a gap would read as missing data rather than a quiet month.
    expect($default->json('data.series.0'))->toHaveKeys([
        'month', 'partial', 'income', 'expenses', 'net_profit', 'assets', 'liabilities', 'equity', 'cash',
    ])
        ->and($default->json('data.series.0.month'))->toMatch('/^\d{4}-\d{2}$/');
});

test('a single-month window reports the month in progress instead of emptying itself', function () {
    // January under a year-to-date range: one month, and it is not over.
    $this->travelTo(Carbon::parse('2026-01-15 12:00:00'));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $post = function (array $lines, Carbon $date, string $reference) use ($business) {
        return app(LedgerPostingService::class)->post($business->id, $lines, [
            'date' => $date->toDateString(),
            'reference' => $reference,
        ]);
    };

    // Last month, and this one.
    $post([
        ['account_key' => 'cash', 'debit' => 300000],
        ['account_key' => 'sales_income', 'credit' => 300000],
    ], now()->startOfMonth()->subMonth(), 'WS23-DEC');

    $post([
        ['account_key' => 'cash', 'debit' => 80000],
        ['account_key' => 'sales_income', 'credit' => 80000],
    ], now(), 'WS23-JAN');

    $response = $this->withToken(ws23Token($owner))
        ->getJson('/api/v1/management/accounting/dashboard?months=1');

    $response->assertOk();

    $series = $response->json('data.series');

    // The point still says the month is unfinished, so the chart can label it…
    expect($series)->toHaveCount(1)
        ->and($series[0]['partial'])->toBeTrue()
        ->and($series[0]['income'])->toBe(80000)
        // …but the flows do not exclude it, because a window that excluded its
        // only month would report a business with takings this month as having
        // earned nothing. It is January against December instead.
        ->and($response->json('data.period.partial'))->toBeFalse()
        ->and($response->json('data.period.comparison_months'))->toBe(1)
        ->and($response->json('data.totals.income'))->toBe(80000)
        ->and($response->json('data.totals.assets'))->toBe(380000)
        ->and($response->json('data.previous.income'))->toBe(300000)
        ->and($response->json('data.previous.assets'))->toBe(300000);

    // And it still agrees with the reports it mirrors.
    $reports = app(LedgerReportService::class);

    expect($response->json('data.totals.assets'))
        ->toBe($reports->balanceSheet($business->id, now()->toDateString())['total_assets'])
        ->and($response->json('data.totals.income'))
        ->toBe($reports->profitAndLoss($business->id, '2026-01-01', '2026-01-15')['total_income']);
});

test('the report panels are served only to users who may see reports', function () {
    // Mid-month, so the fixture's same-day posting lands in the partial month
    // and the assertions about it are exact.
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);
    ws23PostEntry($business->id, ['reference' => 'WS23-GATE']);

    // One expense in the window. The document alone writes nothing to the
    // ledger — posting it does — so the balance-sheet figures below are
    // unaffected, and the breakdown has a category to key by.
    Expense::create([
        'business_id' => $business->id,
        'ledger_account_id' => ws23Account($business->id, '5200')->id,
        'expense_category_id' => ExpenseCategory::query()
            ->where('business_id', $business->id)
            ->where('name', 'Rent')
            ->firstOrFail()
            ->id,
        'expense_date' => now()->toDateString(),
        'amount_kobo' => 500000,
        'tax_kobo' => 0,
        'total_kobo' => 500000,
        'currency' => 'NGN',
        'payment_method' => 'cash',
        'status' => Expense::STATUS_PAID,
    ]);

    $ownerView = $this->withToken(ws23Token($owner))
        ->getJson('/api/v1/management/accounting/dashboard')
        ->assertOk();

    // The owner's role carries "accounting reports", so the panels are there.
    $ownerView->assertJsonStructure([
        'data' => [
            'receivables' => ['buckets', 'grand_total', 'as_of'],
            'payables' => ['buckets', 'grand_total', 'as_of'],
            'expense_categories' => ['categories', 'grand_total', 'count'],
            'health' => ['trial_debit', 'trial_credit', 'trial_balanced', 'unposted_transactions', 'ar_difference'],
        ],
    ]);

    // The keys the overview indexes by, pinned: it walks the aging buckets in
    // a fixed order and reads the expense split as an object keyed by category
    // name, so a renamed bucket or a list-shaped breakdown would render as
    // empty panels rather than as an error.
    expect(array_keys($ownerView->json('data.receivables.buckets')))
        ->toBe(['current', '1_30', '31_60', '61_90', 'over_90'])
        ->and(array_keys($ownerView->json('data.payables.buckets')))
        ->toBe(['current', '1_30', '31_60', '61_90', 'over_90'])
        ->and(array_keys($ownerView->json('data.expense_categories.categories')))->toBe(['Rent'])
        ->and($ownerView->json('data.expense_categories.categories.Rent.total'))->toBe(500000)
        ->and($ownerView->json('data.expense_categories.grand_total'))->toBe(500000)
        ->and($ownerView->json('data.expense_categories.count'))->toBe(1)
        ->and($ownerView->json('data.health.trial_balanced'))->toBeTrue();

    // No seeded role holds "accounting view" without "accounting reports", so
    // the gate is exercised with a direct grant.
    $viewer = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $viewer->givePermissionTo('accounting view');

    $viewerView = $this->withToken(ws23Token($viewer))
        ->getJson('/api/v1/management/accounting/dashboard')
        ->assertOk();

    // Absent, not empty — the SPA renders whichever slices arrive.
    $viewerView->assertJsonMissingPath('data.receivables')
        ->assertJsonMissingPath('data.payables')
        ->assertJsonMissingPath('data.expense_categories')
        ->assertJsonMissingPath('data.health');

    // The core figures still render for them, and they are kobo like the rest.
    // The one posting in this fixture is dated today, which is inside the
    // partial month: the stock figure counts it, the compared flows do not,
    // and it shows up in the charted point instead.
    expect($viewerView->json('data.assets'))->toBe(150000)
        ->and($viewerView->json('data.totals.assets'))->toBe(150000)
        ->and($viewerView->json('data.totals.income'))->toBe(0)
        ->and($viewerView->json('data.series.5.income'))->toBe(150000)
        ->and($viewerView->json('data.series'))->toHaveCount(6);

    // A second business's ledger never reaches this one's series.
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($otherBusiness->id);

    $otherEntry = app(LedgerPostingService::class)->post($otherBusiness->id, [
        ['account_key' => 'cash', 'debit' => 9900000],
        ['account_key' => 'sales_income', 'credit' => 9900000],
    ], ['reference' => 'WS23-OTHER-BIG']);

    $unchanged = $this->withToken(ws23Token($owner))
        ->getJson('/api/v1/management/accounting/dashboard')
        ->assertOk();

    $ownSheet = app(LedgerReportService::class)->balanceSheet($business->id, now()->toDateString());
    $unchangedSeries = $unchanged->json('data.series');

    expect($unchanged->json('data.totals.assets'))->toBe(150000)
        ->and($unchanged->json('data.totals.income'))->toBe(0)
        // Their 9,900,000 posting moves neither our totals nor any of our
        // month points — every series row is scoped to this business.
        ->and($unchangedSeries[count($unchangedSeries) - 1]['assets'])->toBe($ownSheet['total_assets'])
        ->and($unchangedSeries[count($unchangedSeries) - 1]['income'])->toBe(150000)
        ->and($otherEntry->business_id)->toBe($otherBusiness->id);

    $this->app['auth']->forgetGuards();
});

test('posting into a closed fiscal period is refused with the period name', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    $period = FiscalPeriod::query()
        ->where('business_id', $business->id)
        ->where('name', now()->format('Y-m'))
        ->firstOrFail();

    $period->update(['status' => 'closed']);

    $cash = ws23Account($business->id, '1010');
    $rent = ws23Account($business->id, '5200');

    $response = $this->withToken(ws23Token($owner))->postJson('/api/v1/management/accounting/journal', [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $rent->id, 'debit_kobo' => 1000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 1000],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('closed')
        ->toContain(now()->format('Y-m'));
});

test('journal writes require the accounting journal permission', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23EnsureBooks($business->id);

    // Manager has "accounting view" and "accounting expenses" but not the
    // journal or accounts abilities.
    $manager = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $manager->assignRole('Manager');

    $token = ws23Token($manager);
    $cash = ws23Account($business->id, '1010');

    $this->withToken($token)->getJson('/api/v1/management/accounting/journal')->assertOk();

    $this->withToken($token)->getJson('/api/v1/management/accounting/accounts')->assertOk();

    $this->withToken($token)->postJson('/api/v1/management/accounting/journal', [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $cash->id, 'debit_kobo' => 1000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 1000],
        ],
    ])->assertStatus(403);

    $this->withToken($token)->postJson('/api/v1/management/accounting/accounts', [
        'code' => '5980',
        'name' => 'Nope',
        'type' => 'expense',
    ])->assertStatus(403);

    // The owner's role still covers the writes.
    //
    // The manager's request above is the first authentication in this test, so
    // the sanctum RequestGuard cached the manager as the resolved user; the
    // guard has to be forgotten or the owner's request would inherit the
    // manager's (insufficient) permissions and 403 here.
    $this->app['auth']->forgetGuards();

    $this->withToken(ws23Token($owner))->postJson('/api/v1/management/accounting/accounts', [
        'code' => '5981',
        'name' => 'Owner account',
        'type' => 'expense',
    ])->assertCreated();
});
