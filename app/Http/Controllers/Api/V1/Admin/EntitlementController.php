<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\Plans\EntitlementRegistry;
use Illuminate\Http\JsonResponse;

/**
 * The vocabulary a plan can be written in.
 *
 * One read endpoint, and it returns {@see EntitlementRegistry} rather than a
 * list written here — which is the whole reason the plan editor is built from
 * this payload instead of from hard-coded fields. Adding an entitlement is one
 * entry in the registry and one check at its enforcement point; the admin
 * console picks it up with no frontend change, the same contract
 * `PaymentGatewayRegistry` has with the payments screens and `PluginRegistry`
 * with the plugin list.
 *
 * It is a separate path from `subscription-plans` on purpose. This is the
 * platform's vocabulary, not any one plan — and hanging it under a collection
 * whose other routes bind `{plan}` invites a future `GET subscription-plans/{plan}`
 * to swallow it as a plan code.
 *
 * Gated on `admin.subscriptions`, the permission that owns the plan CRUD: the
 * catalogue is not secret, but nothing should be reading the shape of a plan
 * without being allowed to change one.
 */
class EntitlementController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function index(): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok(EntitlementRegistry::cataloguePayload());
    }
}
