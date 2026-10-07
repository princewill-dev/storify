<?php

namespace App\Repositories\Accounting;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WS-16 — data access for expenses.
 *
 * Everything the ExpenseController used to build inline as queries lives here:
 * the filtered list, the month/year stat aggregates, the form-picker
 * projections, the detail eager loads and the journal lookups the void and
 * detail payloads need.
 *
 * Transaction boundaries and the receipt file handling live in ExpenseService
 * and HTTP responses in the controller — nothing in here calls
 * DB::transaction() or abort().
 *
 * The picker lists are returned as plain id/name projections: they are read
 * models consumed verbatim by the form, with no per-row behaviour to add.
 */
final class ExpenseRepository
{
    /**
     * @param  array<string, mixed>  $filters  validated IndexExpenseRequest data
     */
    public function paginateForBusiness(int $businessId, array $filters): LengthAwarePaginator
    {
        // `category` is the legacy query key, `category_id` the newer one; both
        // are a strict expense_category_id match (the summary's category_label
        // fallback to the ledger account is display-only).
        $categoryId = $filters['category_id'] ?? $filters['category'] ?? null;

        return Expense::query()
            ->where('business_id', $businessId)
            ->with(['category:id,name', 'supplier:id,name', 'ledgerAccount:id,code,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($categoryId, fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * The legacy month/year cards ignore the list filters and only exclude
     * void rows; the Records card is the filtered total, which needs the
     * paginator and stays in the controller.
     *
     * @return array{month_kobo: int, year_kobo: int}
     */
    public function statsForBusiness(int $businessId): array
    {
        return [
            'month_kobo' => (int) Expense::query()
                ->where('business_id', $businessId)
                ->where('status', '!=', Expense::STATUS_VOID)
                ->whereMonth('expense_date', now()->month)
                ->whereYear('expense_date', now()->year)
                ->sum('total_kobo'),
            'year_kobo' => (int) Expense::query()
                ->where('business_id', $businessId)
                ->where('status', '!=', Expense::STATUS_VOID)
                ->whereYear('expense_date', now()->year)
                ->sum('total_kobo'),
        ];
    }

    /**
     * Active expense categories, shared by the list `meta` and the form picker.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function categoryOptions(int $businessId): array
    {
        return ExpenseCategory::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ExpenseCategory $category) => ['id' => $category->id, 'name' => $category->name])
            ->all();
    }

    /**
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function expenseAccountOptions(int $businessId): array
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->ofType(LedgerAccount::TYPE_EXPENSE)
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])->all();
    }

    /**
     * @return array<int, array{id: int, code: string, name: string, subtype: string|null}>
     */
    public function paymentAccountOptions(int $businessId): array
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->active()
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype'])
            ->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype,
            ])->all();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function supplierOptions(int $businessId): array
    {
        return Supplier::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])
            ->all();
    }

    /**
     * Eager-load everything the detail payload renders in one pass.
     */
    public function loadForDetail(Expense $expense): Expense
    {
        $expense->loadMissing(['category:id,name', 'supplier:id,name', 'ledgerAccount:id,code,name', 'paymentAccount:id,code,name', 'journalEntry.lines.account']);

        return $expense;
    }

    /**
     * The entry that reversed this expense's original posting, if any —
     * scoped to the expense's business so a corrupt cross-business row cannot
     * surface here.
     */
    public function reversalEntryFor(Expense $expense, JournalEntry $entry): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('business_id', $expense->business_id)
            ->where('reversal_of_id', $entry->id)
            ->first(['id', 'entry_number', 'entry_date', 'status']);
    }

    /**
     * The expense's original journal entry, scoped to its business.
     */
    public function journalEntryFor(Expense $expense): ?JournalEntry
    {
        if (! $expense->journal_entry_id) {
            return null;
        }

        return JournalEntry::query()
            ->where('business_id', $expense->business_id)
            ->whereKey($expense->journal_entry_id)
            ->first();
    }
}
