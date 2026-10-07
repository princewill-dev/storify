<?php

namespace App\Repositories\Management;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query building for the staff directory.
 *
 * The one read StaffController::index() composed inline — the business scope,
 * the status and search filters, the list eager loads and the ordering — lives
 * here. Reads and query building only: no abort() calls and no transaction
 * boundaries (the controller and the service own those).
 *
 * The directory is tenancy-scoped by `business_id` and `role = 'staff'`;
 * getting that scope wrong shows another business's team. The `when()` guards
 * are the controller's `filled()` checks resolved by the caller: a null status
 * or term means "not submitted", so the filter is skipped exactly as before.
 * The status value and search term arrive as strings, keeping the coercion the
 * controller's `$request->string()` helpers performed.
 *
 * There is no repository method for show()'s single `load()` — a lone Eloquent
 * call does not earn an indirection.
 */
final class StaffRepository
{
    /**
     * The staff list page.
     *
     * @param  string|null  $status  resolved from `filled('status')` by the controller
     * @param  string|null  $term  resolved from `filled('q')` by the controller
     */
    public function paginateForBusiness(User $user, ?string $status, ?string $term, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->where('business_id', $user->business_id)
            ->where('role', 'staff')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($term !== null, function ($q) use ($term) {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like));
            })
            ->with(['roles', 'assignedStores:id,name'])
            ->latest()
            ->paginate($perPage);
    }
}
