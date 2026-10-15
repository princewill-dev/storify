<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer token is only good for a customer a business owns.
 *
 * Accounts are scoped by `$customer->business_id`, so the account API has no
 * tenancy boundary at all when that is null — there is no tenant to scope
 * orders, payments or addresses to. Rows like that exist: registration never
 * used to set the column, and the backfill could only repair the ones whose
 * orders named a single business. The rest belong to nobody.
 *
 * Applied to the whole `storefront/account` group rather than checked inside
 * each controller method, so a route added under it later cannot forget. A
 * 403 rather than a 404: unlike an unknown store slug, there is nothing here to
 * conceal — the holder of the token is the account, and the useful answer is
 * that its account is broken, not that the endpoint is missing.
 */
class EnsureCustomerHasBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Customer|null $customer */
        $customer = $request->user('sanctum_customer') ?? $request->user();

        if ($customer && $customer->business_id === null) {
            return response()->json([
                'message' => 'This account is not attached to a store. Please contact support.',
                'errors' => ['account' => ['no_business']],
            ], 403);
        }

        return $next($request);
    }
}
