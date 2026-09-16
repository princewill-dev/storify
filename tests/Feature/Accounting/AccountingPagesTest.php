<?php

use App\Models\Bill;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Services\Accounting\LedgerSetupService;

use function Pest\Laravel\actingAs;

function accountingOwner(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    return [$owner, $business];
}

test('all accounting pages render for a business owner', function () {
    [$owner, $business] = accountingOwner();

    $supplier = Supplier::create(['business_id' => $business->id, 'name' => 'Test Supplier']);

    $expense = Expense::create([
        'business_id' => $business->id,
        'ledger_account_id' => LedgerAccount::where('business_id', $business->id)->where('subtype', 'default_expense')->value('id'),
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

    actingAs($owner);

    $this->get(route('management.accounting.index'))->assertOk();
    $this->get(route('management.accounting.accounts.index'))->assertOk();
    $this->get(route('management.accounting.accounts.create'))->assertOk();
    $this->get(route('management.accounting.journal.index'))->assertOk();
    $this->get(route('management.accounting.journal.create'))->assertOk();
    $this->get(route('management.accounting.expenses.index'))->assertOk();
    $this->get(route('management.accounting.expenses.create'))->assertOk();
    $this->get(route('management.accounting.expenses.show', $expense))->assertOk();
    $this->get(route('management.accounting.suppliers.index'))->assertOk();
    $this->get(route('management.accounting.suppliers.show', $supplier))->assertOk();
    $this->get(route('management.accounting.bills.index'))->assertOk();
    $this->get(route('management.accounting.bills.create'))->assertOk();
    $this->get(route('management.accounting.bills.show', $bill))->assertOk();
    $this->get(route('management.accounting.settings.index'))->assertOk();
    $this->get(route('management.accounting.reports.index'))->assertOk();
    $this->get(route('management.accounting.reports.trial-balance'))->assertOk();
    $this->get(route('management.accounting.reports.profit-and-loss'))->assertOk();
    $this->get(route('management.accounting.reports.balance-sheet'))->assertOk();
    $this->get(route('management.accounting.reports.general-ledger'))->assertOk();
    $this->get(route('management.accounting.reports.ar-aging'))->assertOk();
    $this->get(route('management.accounting.reports.ap-aging'))->assertOk();
    $this->get(route('management.accounting.reports.vat-summary'))->assertOk();
    $this->get(route('management.accounting.reports.expense-summary'))->assertOk();
    $this->get(route('management.accounting.reports.integrity'))->assertOk();
    $this->get(route('management.accounting.reconciliation.index'))->assertOk();
});

test('an owner can record an expense and it posts to the ledger', function () {
    [$owner, $business] = accountingOwner();

    $accountId = LedgerAccount::where('business_id', $business->id)->where('subtype', 'rent')->value('id');

    actingAs($owner)->post(route('management.accounting.expenses.store'), [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => $accountId,
        'amount' => 25000,
        'tax' => 0,
        'payment_method' => 'cash',
        'description' => 'Office rent for September',
    ])->assertRedirect(route('management.accounting.expenses.index'));

    $expense = Expense::where('business_id', $business->id)->sole();

    expect($expense->total_kobo)->toBe(2500000)
        ->and($expense->journal_entry_id)->not->toBeNull();

    $this->assertDatabaseHas('journal_entries', [
        'id' => $expense->journal_entry_id,
        'business_id' => $business->id,
        'status' => 'posted',
    ]);
});

test('an owner can create a supplier bill and pay it', function () {
    [$owner, $business] = accountingOwner();

    $supplier = Supplier::create(['business_id' => $business->id, 'name' => 'Acme Ltd']);
    $expenseAccountId = LedgerAccount::where('business_id', $business->id)->where('subtype', 'default_expense')->value('id');

    actingAs($owner)->post(route('management.accounting.bills.store'), [
        'supplier_id' => $supplier->id,
        'issue_date' => now()->toDateString(),
        'tax' => 0,
        'items' => [
            ['description' => 'Packaging materials', 'quantity' => 2, 'unit_cost' => 5000, 'expense_account_id' => $expenseAccountId],
        ],
    ])->assertRedirect();

    $bill = Bill::where('business_id', $business->id)->sole();

    expect($bill->total_kobo)->toBe(1000000)
        ->and($bill->journal_entry_id)->not->toBeNull();

    actingAs($owner)->post(route('management.accounting.bills.payments.store', $bill), [
        'payment_date' => now()->toDateString(),
        'amount' => 10000,
        'method' => 'bank_transfer',
    ])->assertSessionHasNoErrors();

    $bill->refresh();

    expect($bill->amount_paid_kobo)->toBe(1000000)
        ->and($bill->status)->toBe('paid');
});

test('a manual journal entry must balance', function () {
    [$owner, $business] = accountingOwner();

    $cash = LedgerAccount::where('business_id', $business->id)->where('subtype', 'cash')->value('id');
    $sales = LedgerAccount::where('business_id', $business->id)->where('subtype', 'sales_income')->value('id');

    actingAs($owner)->post(route('management.accounting.journal.store'), [
        'entry_date' => now()->toDateString(),
        'memo' => 'Unbalanced test',
        'lines' => [
            ['ledger_account_id' => $cash, 'debit' => 100, 'credit' => null],
            ['ledger_account_id' => $sales, 'debit' => null, 'credit' => 200],
        ],
    ])->assertSessionHas('error');

    actingAs($owner)->post(route('management.accounting.journal.store'), [
        'entry_date' => now()->toDateString(),
        'memo' => 'Balanced test',
        'lines' => [
            ['ledger_account_id' => $cash, 'debit' => 150, 'credit' => null],
            ['ledger_account_id' => $sales, 'debit' => null, 'credit' => 150],
        ],
    ])->assertRedirect(route('management.accounting.journal.index'));

    $this->assertDatabaseHas('journal_entries', [
        'business_id' => $business->id,
        'memo' => 'Balanced test',
        'status' => 'posted',
    ]);
});
