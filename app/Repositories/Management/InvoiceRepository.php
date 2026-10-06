<?php

namespace App\Repositories\Management;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-21 — reads for the invoices module.
 *
 * Tenant scoping, the legacy stats row, the list filters/search, the
 * create/edit form pickers, the row lock a payment workflow needs and the
 * per-action eager loads live here.
 *
 * Reads and query building only: the invoice workflows' DB::transaction calls
 * and writes belong to InvoiceService, and nothing in this layer aborts an
 * HTTP request.
 */
final class InvoiceRepository
{
    /**
     * Invoices the user may see: business-scoped, plus the store restriction
     * for store-assigned staff (invoices are business-level, so a restricted
     * member only sees the ones raised against their stores).
     */
    public function scopedQuery(User $user): Builder
    {
        $query = Invoice::query()->where('business_id', $user->business_id);

        if ($user->isRestrictedStaff()) {
            $query->whereIn('store_id', $user->accessibleStoreIds());
        }

        return $query;
    }

    /**
     * The legacy card row was All / Draft / Sent / Overdue / Revenue — no
     * Paid count — and counted only fully paid invoices as revenue.
     *
     * @return array<string, mixed>
     */
    public function stats(User $user): array
    {
        $base = $this->scopedQuery($user);

        $counts = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = ['all' => (int) $counts->sum()];

        foreach (InvoiceStatus::cases() as $status) {
            $stats[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        $stats['revenue'] = (float) (clone $base)->where('status', InvoiceStatus::PAID->value)->sum('total');

        return $stats;
    }

    /**
     * The list: stats filters, search, eager loads and legacy ordering.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return $this->scopedQuery($user)
            ->with(['customer:id,first_name,last_name,email,phone', 'store:id,name,store_id'])
            ->withCount('items')
            ->when($filters['q'] ?? null, fn ($query, $term) => $this->applySearch($query, $term))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', $storeId))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * The create/edit form's customer picker, business-scoped.
     *
     * @return Collection<int, Customer>
     */
    public function customerOptions(User $user): Collection
    {
        return Customer::where('business_id', $user->business_id)
            ->orderBy('first_name')
            ->limit(500)
            ->get();
    }

    /**
     * Inactive stores stay in the list (the resource carries a status flag) so
     * editing a draft that points at one never loses its selection.
     *
     * @return Collection<int, Store>
     */
    public function storeOptions(User $user): Collection
    {
        return $user->accessibleStores()->orderBy('name')->get();
    }

    /**
     * The invoice a payment workflow locks before mutating it. The caller owns
     * the surrounding DB::transaction; findOrFail keeps the 404 for a stale id.
     */
    public function findForUpdate(int $id): Invoice
    {
        return Invoice::query()->lockForUpdate()->findOrFail($id);
    }

    /**
     * The document read model every show/action response renders.
     */
    public function loadDetail(Invoice $invoice): Invoice
    {
        return $invoice->loadMissing(['items', 'customer', 'store', 'transactions']);
    }

    /**
     * The print artefact's relation set.
     */
    public function loadDocument(Invoice $invoice): Invoice
    {
        return $invoice->load(['items', 'store', 'customer']);
    }

    /**
     * The relation set the payment mail renders.
     */
    public function loadForMail(Invoice $invoice): Invoice
    {
        return $invoice->loadMissing(['items', 'store', 'customer']);
    }

    /**
     * An existing customer with this email, for the "save as customer"
     * reuse-instead-of-duplicate behaviour.
     */
    public function findCustomerByEmail(int $businessId, string $email): ?Customer
    {
        return Customer::where('business_id', $businessId)
            ->where('email', $email)
            ->first();
    }

    private function applySearch(Builder $query, string $term): void
    {
        $query->where(function ($search) use ($term) {
            $search->where('invoice_number', 'like', "%{$term}%")
                ->orWhere('recipient_name', 'like', "%{$term}%")
                ->orWhere('recipient_email', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($customer) => $customer->whereRaw(
                    "CONCAT(first_name, ' ', last_name) like ?",
                    ["%{$term}%"],
                ));
        });
    }
}
