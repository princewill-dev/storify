<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListSubscriptionPlansRequest;
use App\Http\Requests\Admin\SubscriptionPlanRequest;
use App\Http\Resources\Admin\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Repositories\Admin\SubscriptionPlanRepository;
use App\Services\Admin\SubscriptionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-11 (admin console) — subscription plan CRUD ("Subscription Fee" screen).
 *
 * Legacy `/office/subscription-plans` listed plans by `sort_order` and offered
 * create/edit modals plus a guarded delete. Two legacy defects are fixed
 * rather than cloned:
 *
 *  - the modals rendered `is_trial` / `trial_days` but the validator dropped
 *    them, so the fields never persisted; they are accepted, required-for-trial
 *    and stored here;
 *  - a plan's subscriptions FK cascades, so a plan delete erases its
 *    subscription rows. The guard covers pending and suspended rows too (a
 *    pending subscription is mid-checkout — deleting its plan would break the
 *    payment callback), and the list payload carries `can_delete` so the SPA
 *    disables Delete up front instead of round-tripping the error.
 *
 * Money: the column stores decimal naira (legacy schema); writes accept
 * `amount_kobo` (preferred, the house unit) or a decimal `amount`, and both
 * read shapes are returned.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListSubscriptionPlansRequest` for the list filters, `SubscriptionPlanRequest`
 * for the shared create/edit payload), the list and count queries in
 * `SubscriptionPlanRepository`, the write workflows with their transaction
 * boundaries and audit rows in `SubscriptionPlanService`, and the row shaping
 * in `SubscriptionPlanResource`. The platform-admin guard deliberately stays
 * in the body — it must not move into FormRequest::authorize() or middleware —
 * and the guarded-delete refusal stays beside it, after the guard, so guard
 * order (403 first) is unchanged.
 */
class SubscriptionPlanController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly SubscriptionPlanRepository $plans,
        private readonly SubscriptionPlanService $service,
    ) {}

    public function index(ListSubscriptionPlansRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $plans = $this->plans->paginateForAdmin($request->validated());

        return $this->ok(
            $plans->getCollection()->map(fn (SubscriptionPlan $plan) => $this->payload($plan))->values()->all(),
            null,
            200,
            $this->paginationMeta($plans),
        );
    }

    public function store(SubscriptionPlanRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $plan = $this->service->create($request->validated(), $request->user());

        return $this->ok(['plan' => $this->payload($this->plans->loadSubscriptionCounts($plan))], 'Subscription plan created.', 201);
    }

    public function update(SubscriptionPlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->service->update($plan, $request->validated(), $request->user());

        return $this->ok(['plan' => $this->payload($this->plans->loadSubscriptionCounts($plan->fresh()))], 'Subscription plan updated.');
    }

    public function destroy(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // The guard covers pending and suspended rows too: the subscriptions
        // FK cascades, and a pending subscription is mid-checkout, so deleting
        // its plan would break the payment callback.
        if ($plan->subscriptions()->whereIn('status', SubscriptionPlanRepository::GUARDED_STATUSES)->exists()) {
            return $this->error('Cannot delete a plan with active subscriptions. Deactivate it instead.', 422);
        }

        $name = $plan->name;

        $this->service->delete($plan, $request->user());

        return $this->ok([], "Plan \"{$name}\" deleted successfully.");
    }

    /**
     * The row shape, kept as a thin seam the list and every write echo share;
     * the fields live in SubscriptionPlanResource.
     *
     * @return array<string, mixed>
     */
    private function payload(SubscriptionPlan $plan): array
    {
        return SubscriptionPlanResource::make($plan)->resolve();
    }
}
