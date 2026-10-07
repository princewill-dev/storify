<?php

namespace App\Repositories\Management\Transaction;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query building for the base TransactionController's list.
 *
 * Deliberately separate from App\Repositories\Management\TransactionRepository
 * (the WS-18 slice behind the shared routes, served by TransactionParityController):
 * that one scopes assigned staff to their stores, adds the store/date-range
 * filters and carries its own relation set; this one keeps the base slice's
 * behaviour — business scoping only, the status/reference filters, the three
 * list relations and the inherited newest-first ordering. The two contracts
 * are not interchangeable, so they are not merged.
 *
 * Reads and query composition only — no DB::transaction and no abort() calls
 * in this layer. The list is tenancy-scoped (business); getting it wrong leaks
 * another business's payments, which is why the composition lives in one named
 * place.
 */
class TransactionRepository
{
    /**
     * Relations the list rows render.
     *
     * @var array<int, string>
     */
    private const LIST_RELATIONS = [
        'order:id,order_number,store_id,customer_id',
        'invoice:id,invoice_number,store_id',
        'paymentMethod',
    ];

    /**
     * The transaction list: tenant scoping, the status/reference filters, the
     * list eager loads and the inherited newest-first ordering.
     *
     * The controller reads the request with the same filled()/integer()/
     * string() semantics the inline query used and hands over plain values;
     * the index endpoint has no FormRequest on purpose (this slice never
     * validated those filters, and adding rules would turn inputs it used to
     * answer — an out-of-range per_page reaching the paginator — into 422s).
     *
     * @param  array{status?: string|null, q?: string|null, per_page?: int}  $filters
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return Transaction::query()
            ->where('business_id', $user->business_id)
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['q'] ?? null) !== null, fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%'))
            ->with(self::LIST_RELATIONS)
            ->latest()
            ->paginate($filters['per_page'] ?? 20);
    }
}
