<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePosStoreAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $store = $request->route('store');

        if (! $user instanceof User
            || ! $store instanceof Store
            || (int) $user->business_id !== (int) $store->business_id
            || ! $user->accessibleStores()->whereKey($store->id)->exists()) {
            abort(403, 'You do not have access to this store.');
        }

        return $next($request);
    }
}
