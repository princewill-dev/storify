<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $stores = $user->accessibleStores()
            ->where('status', '!=', 'deleted')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('slug', 'like', $term));
            })
            ->withCount(['products', 'orders'])
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $stores->getCollection()->map(fn (Store $store) => $this->payload($store))->values()->all(),
            null,
            200,
            [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ]
        );
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $store->loadCount(['products', 'orders']);
        $store->load(['ownershipType', 'businessType']);

        return $this->ok(['store' => $this->payload($store)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'pos_enabled' => (bool) $store->pos_enabled,
            'balance' => (int) $store->balance,
            'payment_mode' => $store->payment_mode,
            'products_count' => $store->products_count ?? null,
            'orders_count' => $store->orders_count ?? null,
            'customers_count' => $store->customers_count ?? null,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            'created_at' => $store->created_at?->toISOString(),
        ];
    }

    private function authorizeStore(Request $request, Store $store): void
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $store->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($store->id)->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }
}
