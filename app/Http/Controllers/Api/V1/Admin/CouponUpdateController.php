<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\UpdateCouponRequest;
use App\Http\Resources\Admin\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;

/**
 * WS-12 — coupon edit parity (OF-4.2).
 *
 * The legacy edit page allowed changing the coupon **code**; the shared
 * admin CouponController's update() validation never accepted `code` and the
 * SPA disabled the field, so codes became immutable after creation. This
 * controller is the same update with `code` (unique ignoring self) added, and
 * the route module re-registers `PUT coupons/{coupon}` against it — the route
 * collection keys on method+URI, so a module file replaces the shared entry
 * without editing routes/api/v1/admin.php (the AD-08 pattern).
 *
 * The payload shape deliberately matches CouponController's so the existing
 * SPA type stays valid.
 *
 * The controller keeps the HTTP shape only — status codes, message strings and
 * the envelope. Validation lives in `UpdateCouponRequest` (including `code`'s
 * unique-ignoring-self rule), the row shaping in `CouponResource`, and the
 * uppercase-code / boolean normalisation stays here, after validation, exactly
 * as before. The write is a single `update()` with no query building, no
 * transaction and no ledger/mail step, so no repository or service is
 * warranted. The platform-admin guard deliberately stays in the body: it must
 * not move into FormRequest::authorize() or middleware.
 */
class CouponUpdateController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        // Codes are stored uppercase (legacy uppercased on create and on edit).
        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper($data['code']);
        }

        // `has`/`boolean` are read here, not in the rules: an omitted flag
        // leaves the stored value alone, and sending `false` is not the same
        // as sending nothing.
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $coupon->update($data);

        return $this->ok(['coupon' => $this->payload($coupon->fresh())], 'Coupon updated.');
    }

    /**
     * The row payload, shaped by CouponResource. Kept as a thin private seam
     * so the response site reads as it did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function payload(Coupon $coupon): array
    {
        return CouponResource::make($coupon)->resolve();
    }
}
