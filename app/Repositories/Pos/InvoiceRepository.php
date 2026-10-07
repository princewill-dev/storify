<?php

namespace App\Repositories\Pos;

use App\Models\Invoice;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads for the POS invoices module (legacy `Pos\InvoiceController`).
 *
 * Every invoice the POS may touch is reached through the requesting store:
 * the store-scoped predicate is the tenancy boundary here (a wrong clause
 * would leak another store's invoices), it is shared by the list, the detail
 * and the three action lookups, and the list adds filters/search/eager loads
 * — that is what earns this layer its place.
 *
 * Reads and query composition only: the create/payment/send workflows and
 * their DB::transaction calls live in App\Services\Pos\InvoiceService, and
 * nothing in this layer aborts (findOrFail keeps the legacy 404 for a stale
 * id).
 */
final class InvoiceRepository
{
    /**
     * The store's invoices: the status tab, the number/recipient search, the
     * eager loads, `latest()` ordering and the 20-per-page legacy page size.
     *
     * The filter values arrive already resolved by the controller with the
     * same filled()/trim() reading the inline query used — a `status` that is
     * not an InvoiceStatus value arrives as null (ignored, not rejected) —
     * so this method only composes the query.
     *
     * @param  array{status?: string|null, q?: string|null}  $filters
     */
    public function paginateForStore(Store $store, array $filters): LengthAwarePaginator
    {
        return Invoice::query()
            ->where('store_id', $store->id)
            ->with(['customer', 'items'])
            ->when(($filters['status'] ?? null) !== null, fn ($query, $status) => $query->where('status', $status))
            ->when(($filters['q'] ?? null) !== null, function ($query, $q) {
                $query->where(function (Builder $x) use ($q) {
                    $x->where('invoice_number', 'like', "%{$q}%")
                        ->orWhere('recipient_name', 'like', "%{$q}%");
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * The store-scoped invoice the detail and the three actions resolve.
     *
     * An id that does not exist, or exists on another store, both 404 here —
     * the tenant boundary and the not-found answer are the same predicate,
     * exactly as before.
     *
     * @param  array<int, string>  $relations
     */
    public function findForStore(Store $store, string|int $invoiceId, array $relations = []): Invoice
    {
        return Invoice::query()
            ->where('store_id', $store->id)
            ->with($relations)
            ->findOrFail($invoiceId);
    }

    /**
     * The post-payment re-read the action payload renders: `refresh()` picks
     * up the amount_paid/status written inside the service transaction, and
     * the two relations are the ones the response maps.
     */
    public function refreshDetail(Invoice $invoice): Invoice
    {
        return $invoice->refresh()->load(['items', 'transactions']);
    }
}
