<?php

namespace App\Repositories\Admin;

use App\Models\BankAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WS-12 — platform receiving-bank-account reads.
 *
 * This layer builds queries and never opens a transaction and never calls
 * abort(): the row/file writes and their transaction boundary live in
 * BankAccountService, and the controller owns the HTTP shape. A single
 * Eloquent call is deliberately not wrapped (the toggle in the controller
 * stays on the model), so the one method here earns its place as eight-plus
 * lines of composed query.
 */
final class BankAccountRepository
{
    /**
     * The console list: the `q` search across bank name, account number and
     * account name, the `status` filter over `is_active`, ordered by the
     * operator-controlled `sort_order` (then id, so rows sharing a sort order
     * keep a stable sequence) and paginated with the query string preserved.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        return BankAccount::query()
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(function ($query) use ($term) {
                $query->where('bank_name', 'like', "%{$term}%")
                    ->orWhere('account_number', 'like', "%{$term}%")
                    ->orWhere('account_name', 'like', "%{$term}%");
            }))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }
}
