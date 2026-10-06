<?php

namespace App\Http\Controllers\Api\V1\Management\Concerns;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait ResolvesManagementContext
{
    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return Collection<int, int>
     */
    protected function accessibleStoreIds(Request $request)
    {
        return $this->user($request)->accessibleStoreIds();
    }

    protected function authorizeStore(Request $request, Store $store): void
    {
        $user = $this->user($request);

        if ((int) $store->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($store->id)->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }

    // paginationMeta() deliberately lives on ApiController only. This trait
    // carried a byte-identical copy, which silently shadowed the base method
    // for the 61 controllers that use it.
}
