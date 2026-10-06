<?php

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\LedgerSetupService;

/*
|--------------------------------------------------------------------------
| Accounting pages — ported from the legacy web suite
|--------------------------------------------------------------------------
|
| tests/Feature/Accounting/AccountingPagesTest.php exercised the Blade
| accounting screens. Most of its behaviour already has API coverage:
|
|   - every screen it rendered → ws16expensesTest (expenses),
|     ws22billssuppliersTest (suppliers/bills), ws23coajournalTest
|     (dashboard/accounts/journal), ws37accountingsettingsTest (settings,
|     reconciliation) and ws24accountingreportsTest (the nine reports),
|     with ManagementModulesApiTest smoke-hitting the report JSON.
|   - "an owner can record an expense and it posts to the ledger" → ws16.
|   - "an owner can create a supplier bill and pay it" → ws22.
|   - "a manual journal entry must balance" → ws23.
|
| The legs those tests leave unasserted are pinned here:
|
|   1. an expense paid in cash with no explicit payment account posts
|      against the mapped cash account (ws16 only pinned the explicit
|      payment_account_id / bank path), at the legacy 25,000-naira
|      (2,500,000 kobo) amount;
|   2. a bill line's explicit expense_account_id is the account debited in
|      the AP posting (the legacy payload sent it; no API test ever did);
|   3. a method-only bill payment (bank_transfer, no payment_account_id)
|      clears accounts payable against the mapped bank account;
|   4. the expense and bill detail endpoints render rows that exist without
|      a journal entry — exactly the rows the legacy pages test wrote
|      straight to the database before rendering their detail screens.
|
| The legacy create screens (accounts/journal/expenses/bills) and the
| reports hub have no API counterpart: the SPA builds those forms from the
| accounts list and the expense-options / bill-options payloads, and there
| is no reports-index endpoint.
|
*/

function accountingPagesToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/** Bootstrap the default chart of accounts + fiscal periods for a business. */
function accountingPagesBooks(int $businessId): void
{
    app(LedgerSetupService::class)->ensureForBusiness($businessId);
}

function accountingPagesAccount(int $businessId, string $code): LedgerAccount
{
    return LedgerAccount::query()
        ->where('business_id', $businessId)
        ->where('code', $code)
        ->firstOrFail();
}

test('recording a cash expense with no payment account posts a balanced entry to cash on hand', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    accountingPagesBooks($business->id);

    $rent = accountingPagesAccount($business->id, '5200');
    $cash = accountingPagesAccount($business->id, '1010');

    // The legacy payload: whole naira, cash, and no payment account/picker.
    $response = $this->withToken(accountingPagesToken($owner))->postJson(
        '/api/v1/management/accounting/expenses',
        [
            'expense_date' => now()->toDateString(),
            'ledger_account_id' => $rent->id,
            'amount' => 25000,
            'tax' => 0,
            'payment_method' => 'cash',
            'description' => 'Office rent for September',
        ],
    );

    $response->assertCreated()
        ->assertJsonPath('data.expense.amount_kobo', 2500000)
        ->assertJsonPath('data.expense.total_kobo', 2500000)
        ->assertJsonPath('data.expense.status', 'paid')
        ->assertJsonPath('data.expense.journal_entry_id', fn ($id) => is_int($id) && $id > 0)
        ->assertJsonMissingPath('data.posting_warning');

    $expense = Expense::query()->where('business_id', $business->id)->sole();

    expect((int) $expense->total_kobo)->toBe(2500000)
        ->and($expense->journal_entry_id)->not->toBeNull();

    $entry = JournalEntry::query()->findOrFail($expense->journal_entry_id);
    $lines = $entry->lines()->get()->keyBy('ledger_account_id');

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($lines)->toHaveCount(2)
        // No payment_account_id was sent, so the credit falls back to the
        // method's mapped account (cash → 1010).
        ->and((int) $lines[$rent->id]->debit_kobo)->toBe(2500000)
        ->and((int) $lines[$cash->id]->credit_kobo)->toBe(2500000);
});

test('a bill line with an explicit expense account debits that account when the bill posts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    accountingPagesBooks($business->id);

    $supplier = Supplier::create(['business_id' => $business->id, 'name' => 'Acme Ltd']);

    // Deliberately not the default_expense (5900) mapping, so the entry can
    // only land here if the explicit per-line account was honoured.
    $rent = accountingPagesAccount($business->id, '5200');
    $defaultExpense = accountingPagesAccount($business->id, '5900');
    $payable = accountingPagesAccount($business->id, '2010');

    $response = $this->withToken(accountingPagesToken($owner))->postJson(
        '/api/v1/management/accounting/bills',
        [
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'tax' => 0,
            'items' => [
                [
                    'description' => 'Packaging materials',
                    'quantity' => 2,
                    'unit_cost' => 5000,
                    'expense_account_id' => $rent->id,
                ],
            ],
        ],
    );

    // The legacy figures: 2 × ₦5,000.00 = ₦10,000.00 (1,000,000 kobo).
    $response->assertCreated()
        ->assertJsonPath('data.bill.subtotal_kobo', 1000000)
        ->assertJsonPath('data.bill.total_kobo', 1000000)
        ->assertJsonPath('data.bill.status', 'open')
        ->assertJsonPath('data.bill.items.0.unit_cost_kobo', 500000)
        ->assertJsonPath('data.bill.items.0.expense_account.code', '5200');

    $bill = Bill::query()->where('business_id', $business->id)->sole();
    $entry = JournalEntry::query()->findOrFail($bill->journal_entry_id);
    $lines = $entry->lines()->get()->keyBy('ledger_account_id');

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and((int) $lines[$rent->id]->debit_kobo)->toBe(1000000)
        ->and((int) $lines[$payable->id]->credit_kobo)->toBe(1000000)
        ->and($lines->has($defaultExpense->id))->toBeFalse();
});

test('paying a bill by bank transfer with no payment account clears payable against the bank account', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    accountingPagesBooks($business->id);

    $supplier = Supplier::create(['business_id' => $business->id, 'name' => 'Acme Ltd']);
    $payable = accountingPagesAccount($business->id, '2010');
    $bank = accountingPagesAccount($business->id, '1020');

    $token = accountingPagesToken($owner);

    $billId = $this->withToken($token)->postJson('/api/v1/management/accounting/bills', [
        'supplier_id' => $supplier->id,
        'issue_date' => now()->toDateString(),
        'tax' => 0,
        'items' => [
            ['description' => 'Packaging materials', 'quantity' => 2, 'unit_cost' => 5000],
        ],
    ])->assertCreated()->json('data.bill.id');

    // The legacy payment: whole naira, method only, no payment account.
    $response = $this->withToken($token)->postJson(
        "/api/v1/management/accounting/bills/{$billId}/payments",
        [
            'payment_date' => now()->toDateString(),
            'amount' => 10000,
            'method' => 'bank_transfer',
        ],
    );

    $response->assertCreated()
        ->assertJsonPath('data.bill.status', 'paid')
        ->assertJsonPath('data.bill.paid_kobo', 1000000)
        ->assertJsonPath('data.bill.balance_kobo', 0);

    $payment = BillPayment::query()->where('business_id', $business->id)->sole();
    $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);
    $lines = $entry->lines()->get()->keyBy('ledger_account_id');

    // No payment_account_id was sent, so the credit falls back to the
    // method's mapped account (bank_transfer → 1020).
    expect((int) $lines[$payable->id]->debit_kobo)->toBe(1000000)
        ->and((int) $lines[$bank->id]->credit_kobo)->toBe(1000000);
});

test('the expense and bill detail endpoints render rows that were never posted to the ledger', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    accountingPagesBooks($business->id);

    $supplier = Supplier::create(['business_id' => $business->id, 'name' => 'Test Supplier']);

    // The legacy fixture: rows written straight to the database with no
    // journal entry behind them.
    $expense = Expense::create([
        'business_id' => $business->id,
        'ledger_account_id' => accountingPagesAccount($business->id, '5900')->id,
        'expense_date' => now()->toDateString(),
        'amount_kobo' => 5000,
        'tax_kobo' => 0,
        'total_kobo' => 5000,
        'status' => 'paid',
    ]);

    $bill = Bill::create([
        'business_id' => $business->id,
        'supplier_id' => $supplier->id,
        'bill_number' => 'BILL-TEST-1',
        'issue_date' => now()->toDateString(),
        'subtotal_kobo' => 10000,
        'tax_kobo' => 0,
        'total_kobo' => 10000,
        'amount_paid_kobo' => 0,
        'status' => 'open',
    ]);

    $token = accountingPagesToken($owner);

    $this->withToken($token)->getJson('/api/v1/management/accounting/expenses/'.$expense->id)
        ->assertOk()
        ->assertJsonPath('data.expense.status', 'paid')
        ->assertJsonPath('data.expense.total_kobo', 5000)
        ->assertJsonPath('data.expense.journal_entry', null)
        ->assertJsonPath('data.expense.reversal_entry', null);

    $this->withToken($token)->getJson('/api/v1/management/accounting/bills/'.$bill->id)
        ->assertOk()
        ->assertJsonPath('data.bill.status', 'open')
        ->assertJsonPath('data.bill.balance_kobo', 10000)
        ->assertJsonPath('data.bill.items', [])
        ->assertJsonPath('data.bill.journal_entry', null)
        ->assertJsonPath('data.bill.reversal_entry', null);
});
