<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\StoreCouponRequest;
use App\Http\Requests\Admin\UpdateCouponDetailsRequest;
use App\Http\Resources\Admin\CouponPlanResource;
use App\Http\Resources\Admin\CouponResource;
use App\Models\Coupon;
use App\Models\SubscriptionPlan;
use App\Repositories\Admin\CouponRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-12 (admin console) — platform coupons.
 *
 * Coupons are platform-wide (no business scoping) and are applied at
 * subscription checkout. Codes are stored uppercase on create — the unique
 * rule still validates the exact client casing, as it always has — and
 * `is_active` defaults to on.
 *
 * The routes live in routes/api/v1/admin.php behind `permission:admin.coupons`
 * plus the shared auth/audience/team middleware. Unlike most admin
 * controllers this one has never carried an in-body platform-admin guard, so
 * none is added here: adding one would change who passes the gate.
 *
 * `PUT coupons/{coupon}` is re-registered by
 * routes/api/v1/admin/ad12-money-config.php against CouponUpdateController
 * (OF-4.2, coupon-code edits); update() below keeps the original shared
 * registration's behaviour unchanged.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. The list filters (never validated; bad
 * values fall back silently) are read here and the query lives in
 * CouponRepository, store/update validation in the Admin FormRequests, and
 * the row/plan shapes in CouponResource and CouponPlanResource. store,
 * update, toggle and destroy are single-model writes with no transaction or
 * shared query, so no service layer is warranted.
 */
class CouponController extends ApiController
{
    public function __construct(
        private readonly CouponRepository $coupons,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // The endpoint has never validated its filters — an unknown sort or
        // an impossible per_page falls back rather than 422s — so the raw
        // request values are read here and the permissive handling lives in
        // the repository, matching the behaviour as-is.
        $coupons = $this->coupons->paginateForAdmin([
            'q' => $request->filled('q') ? $request->string('q')->toString() : null,
            'sort' => $request->string('sort')->toString(),
            'direction' => $request->string('direction')->toString(),
            'per_page' => (int) $request->integer('per_page', 20),
        ]);

        return $this->ok(
            $coupons->getCollection()->map(fn (Coupon $coupon) => $this->payload($coupon))->values()->all(),
            null,
            200,
            $this->paginationMeta($coupons)
        );
    }

    public function store(StoreCouponRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Codes are stored uppercase; uppercasing stays after validation, as
        // it always was, so the unique rule checks the submitted casing.
        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active', true);

        $coupon = Coupon::create($data);

        return $this->ok(['coupon' => $this->payload($coupon)], 'Coupon created.', 201);
    }

    public function update(UpdateCouponDetailsRequest $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validated();

        // `has`/`boolean` are read here, not in the rules: an omitted flag
        // leaves the stored value alone, and sending `false` is not the same
        // as sending nothing.
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
        // A single ordered read of three columns — deliberately not wrapped
        // in a repository method (indirection with no benefit).
        $plans = SubscriptionPlan::orderBy('name')
            ->get(['id', 'name', 'interval'])
            ->map(fn (SubscriptionPlan $plan) => CouponPlanResource::make($plan)->resolve())
            ->values()
            ->all();

        return $this->ok(['plans' => $plans]);
    }

    /**
     * The row shape, kept as a thin private seam so the response sites read
     * as they did before the extraction; the fields live in CouponResource
     * (the same shape the OF-4.2 coupon-edit route emits).
     *
     * @return array<string, mixed>
     */
    private function payload(Coupon $coupon): array
    {
        return CouponResource::make($coupon)->resolve();
    }
}
