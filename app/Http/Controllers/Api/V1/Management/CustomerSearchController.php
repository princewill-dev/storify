<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-19 — the customer group of global search.
 *
 * Re-registers `GET management/search` (the identical method+URI makes this
 * the winning definition) and delegates the other groups back to
 * SearchController so the products/orders shape cannot drift. It restores what
 * the base search dropped from legacy: account_id and full-name CONCAT
 * matching, a `customers view` gate, and restricted-staff store scoping.
 *
 * WS-34 (Global Search, Shell Badges & Avatar) owns the search UI and may
 * register its own handler for this route; when it does this controller
 * simply stops being the handler.
 */
class CustomerSearchController extends ApiController
{
    use ResolvesManagementContext;

    public function __invoke(Request $request, SearchController $search): JsonResponse
    {
        $response = $search($request);

        $payload = $response->getData(true);
        $payload['data']['customers'] = $this->customers($request);

        return response()->json($payload, $response->getStatusCode());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customers(Request $request): array
    {
        $user = $this->user($request);

        // The base search exposed customers to anyone who could reach the
        // endpoint, permission or not.
        if (! $user->can('customers view')) {
            return [];
        }

        $term = trim((string) $request->string('q'));

        if ($term === '') {
            return [];
        }

        $limit = min(10, max(1, (int) $request->integer('limit', 5)));
        $like = '%'.$term.'%';

        return Customer::query()
            ->where('business_id', $user->business_id)
            ->when($user->isRestrictedStaff(), fn ($q) => $q->whereHas(
                'orders',
                fn ($orders) => $orders->whereIn('store_id', $this->accessibleStoreIds($request))
            ))
            ->where(fn ($q) => $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('account_id', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]))
            ->limit($limit)
            ->get(['id', 'account_id', 'first_name', 'last_name', 'email', 'phone', 'status'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'account_id' => $customer->account_id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'status' => strtolower($customer->status),
            ])->values()->all();
    }
}
