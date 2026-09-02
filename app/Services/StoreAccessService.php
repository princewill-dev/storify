<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;

final class StoreAccessService
{
    public function allows(?User $user, Store $store): bool
    {
        if (! $user || (int) $user->business_id !== (int) $store->business_id) {
            return false;
        }

        if ($user->isStaff()) {
            return $user->assignedStores()->where('stores.id', $store->id)->exists();
        }

        return $user->accessibleStores()->where('stores.id', $store->id)->exists();
    }

    public function authorize(?User $user, Store $store): void
    {
        abort_unless($this->allows($user, $store), 403, 'You do not have access to this store.');
    }
}
