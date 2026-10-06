<?php

namespace App\Repositories\Accounting;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * WS-22 — data access for bills (payables).
 *
 * Everything the BillController used to build inline as queries lives here:
 * the filtered list, the stats aggregates, the form-picker projections, the
 * eager loads for the detail payload, the reversal/void journal lookups, the
 * row lock the payment path depends on, and the row writes.
 *
 * Transaction boundaries live in BillService and HTTP responses in the
 * controller — nothing in here calls DB::transaction() or abort().
 *
 * The picker lists are returned as plain id/name projections: they are read
 * models consumed verbatim by the form, with no per-row behaviour to add.
 */
final class BillRepository
{
    /**
     * @param  array<string, mixed>  $filters  validated IndexBillRequest data
     */
    public function paginateForBusiness(int $businessId, array $filters): LengthAwarePaginator
    {
        return Bill::query()
            ->where('business_id', $businessId)
            ->with('supplier:id,name,email,phone')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('bill_number', 'like', $term)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $term)));
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * @return array{open_kobo: int, paid_kobo: int, overdue: int}
     */
    public function statsForBusiness(int $businessId): array
    {
        return [
            // Outstanding, exactly as the legacy cards defined it: unpaid
            // balances across open + partial bills (overpayments would net
            // out here rather than be floored per bill).
            'open_kobo' => (int) Bill::query()
                ->where('business_id', $businessId)
                ->whereIn('status', [Bill::STATUS_OPEN, Bill::STATUS_PARTIAL])
                ->sum(DB::raw('total_kobo - amount_paid_kobo')),
            'paid_kobo' => (int) Bill::query()
                ->where('business_id', $businessId)
                ->where('status', Bill::STATUS_PAID)
                ->sum('total_kobo'),
            'overdue' => Bill::query()
                ->where('business_id', $businessId)
                ->whereIn('status', [Bill::STATUS_OPEN, Bill::STATUS_PARTIAL])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->count(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function supplierOptions(int $businessId, bool $activeOnly = false): array
    {
        return Supplier::query()
            ->where('business_id', $businessId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])
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
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function paymentAccountOptions(int $businessId): array
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->active()
            ->whereIn('subtype', ['cash', 'bank', 'gateway_clearing'])
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])->all();
    }

    /**
     * @return array<int, array{id: int, name: string, product_code: string|null, average_cost_kobo: int}>
     */
    public function productOptions(int $businessId): array
    {
        return Product::query()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'product_code', 'average_cost_kobo'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'product_code' => $product->product_code,
                'average_cost_kobo' => (int) $product->average_cost_kobo,
            ])->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBill(array $attributes): Bill
    {
        return Bill::create($attributes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createBillItems(Bill $bill, array $items): void
    {
        foreach ($items as $item) {
            $bill->items()->create($item);
        }
    }

    /**
     * Re-read under a row lock: two concurrent payments must not both pass the
     * "no more than the remaining balance" check (the legacy controller
     * validated against a stale in-memory copy).
     */
    public function findBillForUpdate(int $billId): Bill
    {
        return Bill::query()->whereKey($billId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPayment(array $attributes): BillPayment
    {
        return BillPayment::create($attributes);
    }

    public function saveBill(Bill $bill): void
    {
        $bill->save();
    }

    public function setBillJournalEntry(Bill $bill, JournalEntry $entry): void
    {
        $bill->update(['journal_entry_id' => $entry->id]);
    }

    public function setPaymentJournalEntry(BillPayment $payment, JournalEntry $entry): void
    {
        $payment->update(['journal_entry_id' => $entry->id]);
    }

    public function markBillVoid(Bill $bill): void
    {
        $bill->update(['status' => Bill::STATUS_VOID]);
    }

    public function hasPayments(Bill $bill): bool
    {
        return $bill->payments()->exists();
    }

    /**
     * Re-fetch scoped to the bill's business: a product id was validated
     * earlier, but never trust an id off the request without the scope.
     */
    public function findProduct(int $businessId, int $productId): ?Product
    {
        return Product::query()
            ->where('business_id', $businessId)
            ->whereKey($productId)
            ->first();
    }

    /**
     * Eager-load everything the detail payload renders in one pass.
     */
    public function loadForDetail(Bill $bill): Bill
    {
        $bill->loadMissing([
            'supplier:id,name,email,phone,address',
            'items.expenseAccount:id,code,name',
            'items.product:id,name,product_code',
            'payments.paymentAccount:id,code,name',
            'journalEntry.lines.account:id,code,name',
        ]);

        return $bill;
    }

    /**
     * The entry that reversed this bill's original posting, if any, scoped to
     * the bill's business so a corrupt cross-business row cannot surface here.
     */
    public function reversalEntryFor(Bill $bill, JournalEntry $entry): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('business_id', $bill->business_id)
            ->where('reversal_of_id', $entry->id)
            ->first(['id', 'entry_number', 'entry_date', 'status']);
    }

    /**
     * The bill's original journal entry, scoped to its business.
     */
    public function journalEntryFor(Bill $bill): ?JournalEntry
    {
        if (! $bill->journal_entry_id) {
            return null;
        }

        return JournalEntry::query()
            ->where('business_id', $bill->business_id)
            ->whereKey($bill->journal_entry_id)
            ->first();
    }
}
