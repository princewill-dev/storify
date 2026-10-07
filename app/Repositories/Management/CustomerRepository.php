<?php

namespace App\Repositories\Management;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads for the base customers API.
 *
 * The tenant scope and list filters and the show screen's order aggregates
 * live here. Reads and query building only: the controller keeps the HTTP
 * contract and the guards, and every write this module performs changes a
 * single customers row, so no service is involved.
 */
class CustomerRepository
{
    /**
     * The list query: business-scoped, filtered and newest first.
     *
     * The caller decides presence (an absent or blank status/search stays
     * unfiltered) and passes the extracted values; the uppercase status the
     * schema stores is applied here, where the filter lands.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listQuery(User $user, array $filters): Builder
    {
        return Customer::query()
            ->where('business_id', $user->business_id)
            // Presence, never truthiness: when() would drop a filled "0", but
            // the old when($request->filled(...)) applied it. Null means the
            // controller saw an absent/blank value, so only null skips.
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', strtoupper($filters['status'])))
            ->when(($filters['q'] ?? null) !== null, function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';

                $q->where(fn ($inner) => $inner->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->withCount('orders')
            ->latest();
    }

    /**
     * Show-screen aggregates, still one query each: total orders, completed
     * orders and the gross of completed orders.
     *
     * @return array{total_orders: int, completed_orders: int, total_spent: float}
     */
    public function orderStats(Customer $customer): array
    {
        return [
            'total_orders' => $customer->orders()->count(),
            'completed_orders' => $customer->orders()->where('status', 'completed')->count(),
            'total_spent' => (float) $customer->orders()->where('status', 'completed')->sum('total'),
        ];
    }
}
