<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $stores = Store::query()
            ->where('status', '!=', 'deleted')
            ->with('business:id,name,business_code')
            ->withCount(['products', 'orders'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy(
                in_array($request->string('sort')->toString(), ['name', 'slug', 'status', 'balance', 'created_at'], true)
                    ? $request->string('sort')->toString()
                    : 'created_at',
                $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc'
            )
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $stores->getCollection()->map(fn (Store $store) => $this->payload($store))->values()->all(),
            null,
            200,
            $this->paginationMeta($stores)
        );
    }

    public function show(Store $store): JsonResponse
    {
        $store->load('business:id,name,business_code')->loadCount(['products', 'orders']);

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
            'business' => $store->business?->name,
            'business_id' => $store->business_id,
            'products_count' => $store->products_count ?? null,
            'orders_count' => $store->orders_count ?? null,
            'created_at' => $store->created_at?->toISOString(),
        ];
    }
}
