<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BusinessController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $businesses = Business::query()
            ->with(['owner:id,name,email,account_code', 'activeSubscription.subscriptionPlan:id,name'])
            ->withCount(['stores', 'warehouses', 'users'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('business_code', 'like', $term)
                    ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->orderBy(
                in_array($request->string('sort')->toString(), ['name', 'business_code', 'status', 'created_at'], true)
                    ? $request->string('sort')->toString()
                    : 'created_at',
                $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc'
            )
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $businesses->getCollection()->map(fn (Business $business) => $this->payload($business))->values()->all(),
            null,
            200,
            $this->paginationMeta($businesses)
        );
    }

    public function show(Business $business): JsonResponse
    {
        $business->load([
            'owner:id,name,email,account_code,phone,status',
            'stores:id,business_id,name,slug,status,store_type',
            'activeSubscription.subscriptionPlan:id,name',
        ])->loadCount(['stores', 'warehouses', 'users']);

        return $this->ok(['business' => $this->payload($business, detailed: true)]);
    }

    public function suspend(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $business->update(['status' => 'suspended']);

        Log::info('api.admin.business_suspended', ['business_id' => $business->id, 'reason' => $data['reason']]);

        return $this->ok(['business' => $this->payload($business->fresh())], 'Business suspended.');
    }

    public function activate(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $business->update(['status' => 'active']);

        Log::info('api.admin.business_activated', ['business_id' => $business->id, 'reason' => $data['reason']]);

        return $this->ok(['business' => $this->payload($business->fresh())], 'Business activated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Business $business, bool $detailed = false): array
    {
        $data = [
            'id' => $business->id,
            'name' => $business->name,
            'business_code' => $business->business_code,
            'status' => $business->status,
            'owner' => $business->owner ? [
                'id' => $business->owner->id,
                'name' => $business->owner->name,
                'email' => $business->owner->email,
                'account_code' => $business->owner->account_code,
            ] : null,
            'plan' => $business->activeSubscription?->subscriptionPlan?->name,
            'stores_count' => $business->stores_count ?? null,
            'warehouses_count' => $business->warehouses_count ?? null,
            'users_count' => $business->users_count ?? null,
            'created_at' => $business->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data['currency'] = $business->currency;
            $data['stores'] = $business->stores->map(fn ($store) => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'store_type' => $store->store_type,
            ])->values()->all();
        }

        return $data;
    }
}
