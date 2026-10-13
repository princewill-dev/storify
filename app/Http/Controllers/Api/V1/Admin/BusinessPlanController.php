<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\AssignBusinessPlanRequest;
use App\Http\Resources\Admin\BusinessPlanAssignmentResource;
use App\Models\Business;
use App\Models\SubscriptionPlan;
use App\Services\Admin\BusinessPlanService;
use Illuminate\Http\JsonResponse;

/**
 * AD-11 — putting a business on a plan, from the console.
 *
 * One action, and it exists because nothing in the platform could do this. See
 * {@see BusinessPlanService} for why it is a support action rather than a
 * purchase, why it is a second writer beside the owner's plan change instead of
 * the same one, and what "assign" means for a business that has a subscription
 * versus one that is only trialling.
 *
 * This lives apart from {@see SubscriptionController} deliberately: that
 * controller's contract is that it reads and never mutates a subscription, and
 * its docblock says so. Widening it would have quietly retired that guarantee.
 *
 * Gated on `admin.subscriptions` — the permission that owns the plan CRUD and
 * the subscription list. The action changes what a business is entitled to, so
 * it belongs to whoever is allowed to decide what a plan contains.
 */
class BusinessPlanController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(private readonly BusinessPlanService $plans) {}

    public function update(AssignBusinessPlanRequest $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        /** @var SubscriptionPlan $plan */
        $plan = $request->plan();

        $result = $this->plans->assign($business, $plan, $request->user());

        return $this->ok(
            BusinessPlanAssignmentResource::make((object) [
                'business' => $business,
                'plan' => $plan,
                'applied' => $result['applied'],
                'subscription' => $result['subscription'],
            ])->resolve(),
            $result['applied'] === BusinessPlanService::APPLIED_SUBSCRIPTION
                ? "Plan changed to \"{$plan->name}\"."
                : "Trial plan set to \"{$plan->name}\".",
        );
    }
}
