<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Actions\Subscriptions\ActivateSubscriptionWithEarlyPass;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Subscription\ApplyEarlyPassRequest;
use App\Http\Resources\Subscription\SubscriptionResource;
use App\Models\EarlyPass;
use App\Models\SubscriptionPlan;
use App\Services\StoreActivationNotifier;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WS-32 — early-access pass redemption.
 *
 * Legacy shipped the endpoint (`management.subscription.check-early-pass`) but
 * no screen ever called it — the pass could only be redeemed by hand-crafted
 * requests. The endpoint is ported with a deliberate code-entry affordance in
 * the SPA (the coupon panel and /subscription/redeem).
 *
 * Redemption runs through App\Actions\Subscriptions\ActivateSubscriptionWithEarlyPass,
 * which locks the user and pass rows, requires a business, enforces the pass's
 * availability and per-user uniqueness, refuses to stack on an active
 * subscription, activates the business's pending/suspended stores and writes
 * the usage row.
 */
class EarlyPassController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly ActivateSubscriptionWithEarlyPass $activator,
        private readonly StoreActivationNotifier $activationNotifier,
    ) {}

    public function apply(ApplyEarlyPassRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->user($request);
        $earlyPass = EarlyPass::query()->where('code', trim($data['code']))->first();
        $plan = SubscriptionPlan::query()->active()->default()->first();

        if (! $earlyPass) {
            return $this->error('Invalid code. Please check and try again.', 422);
        }

        if (! $plan) {
            return $this->error('No subscription plan available.', 422);
        }

        try {
            $subscription = $this->activator->execute($user, $earlyPass, $plan);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Log::error('api.management.early_pass_apply_failed', [
                'user_id' => $user->id,
                'code' => $earlyPass->code,
                'error' => $e->getMessage(),
            ]);

            return $this->error('An error occurred. Please try again.', 500);
        }

        $this->activationNotifier->send($user);

        Log::info('api.management.early_pass_activated', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'early_pass_id' => $earlyPass->id,
        ]);

        return $this->ok([
            'success' => true,
            'activated' => true,
            'redirect' => '/',
            'subscription' => SubscriptionResource::make($subscription)->resolve(),
        ], 'Early access activated! Taking you to your dashboard…');
    }
}
