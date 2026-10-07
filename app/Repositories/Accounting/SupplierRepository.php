<?php

namespace App\Repositories\Accounting;

use App\Models\Bill;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WS-22 — data access for suppliers (payables contacts).
 *
 * Everything the SupplierController used to build inline as queries lives
 * here: the filtered list with the Outstanding aggregates and the detail
 * eager loads.
 *
 * Transaction boundaries and audit logging live in SupplierService and HTTP
 * responses in the controller — nothing in here calls DB::transaction() or
 * abort().
 */
final class SupplierRepository
{
    /**
     * @param  array<string, mixed>  $filters  validated IndexSupplierRequest data
     */
    public function paginateForBusiness(int $businessId, array $filters): LengthAwarePaginator
    {
        return Supplier::query()
            ->where('business_id', $businessId)
            // Legacy definition of Outstanding: non-void bill totals minus
            // what has already been paid on them, floored at zero.
            ->withCount('bills')
            ->withSum(['bills as bills_total_kobo' => fn ($q) => $q->where('status', '!=', Bill::STATUS_VOID)], 'total_kobo')
            ->withSum(['bills as bills_paid_kobo' => fn ($q) => $q->where('status', '!=', Bill::STATUS_VOID)], 'amount_paid_kobo')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * Eager-load everything the detail payload renders in one pass, keeping
     * the newest-first ordering the detail screen shows for both lists.
     */
    public function loadForDetail(Supplier $supplier): Supplier
    {
        $supplier->load([
            'bills' => fn ($q) => $q->orderByDesc('issue_date')->orderByDesc('id'),
            'billPayments' => fn ($q) => $q->orderByDesc('payment_date')->orderByDesc('id'),
        ]);

        return $supplier;
    }
}
