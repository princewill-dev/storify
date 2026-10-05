<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Coupon;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::query()
            ->with('subscriptionPlan:id,name')
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%'.$request->string('q').'%'))
            ->orderBy(
                in_array($request->string('sort')->toString(), ['code', 'discount_value', 'uses_count', 'expires_at', 'is_active', 'created_at'], true)
                    ? $request->string('sort')->toString()
                    : 'created_at',
                $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc'
            )
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $coupons->getCollection()->map(fn (Coupon $coupon) => $this->payload($coupon))->values()->all(),
            null,
            200,
            $this->paginationMeta($coupons)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code'],
            'name' => ['nullable', 'string', 'max:255'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active', true);

        $coupon = Coupon::create($data);

        return $this->ok(['coupon' => $this->payload($coupon)], 'Coupon created.', 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'discount_type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['sometimes', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $coupon->update($data);

        return $this->ok(['coupon' => $this->payload($coupon->fresh())], 'Coupon updated.');
    }

    public function toggle(Coupon $coupon): JsonResponse
    {
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return $this->ok(['coupon' => $this->payload($coupon->fresh())], 'Coupon updated.');
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return $this->ok([], 'Coupon deleted.');
    }

    public function plans(): JsonResponse
    {
        return $this->ok([
            'plans' => SubscriptionPlan::orderBy('name')->get(['id', 'name', 'interval'])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'plan' => $coupon->subscriptionPlan?->name,
            'subscription_plan_id' => $coupon->subscription_plan_id,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'discount_label' => $coupon->discount_label,
            'uses_count' => (int) $coupon->uses_count,
            'max_uses' => $coupon->max_uses,
            'expires_at' => $coupon->expires_at?->toISOString(),
            'is_active' => (bool) $coupon->is_active,
        ];
    }
}
