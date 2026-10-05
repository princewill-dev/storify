<?php

namespace App\Http\Controllers\Api\V1\Management\Concerns;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesManagementContext
{
    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function accessibleStoreIds(Request $request)
    {
        return $this->user($request)->accessibleStores()->pluck('id');
    }

    protected function authorizeStore(Request $request, Store $store): void
    {
        $user = $this->user($request);

        if ((int) $store->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($store->id)->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
