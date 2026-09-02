<?php

namespace App\Http\Controllers\Management;

use App\Actions\Subscriptions\ActivateSubscriptionWithEarlyPass;
use App\Http\Controllers\Controller;
use App\Models\EarlyPass;
use App\Models\SubscriptionPlan;
use App\Services\StoreActivationNotifier;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class EarlyPassController extends Controller
{
    public function __construct(
        private readonly ActivateSubscriptionWithEarlyPass $activator,
        private readonly StoreActivationNotifier $activationNotifier,
    ) {}

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $earlyPass = EarlyPass::query()->where('code', trim($data['code']))->first();
        $plan = SubscriptionPlan::active()->default()->first();

        if (! $earlyPass) {
            return response()->json(['success' => false, 'message' => 'Invalid code. Please check and try again.']);
        }
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'No subscription plan available.']);
        }

        try {
            $this->activator->execute($request->user(), $earlyPass, $plan);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('early_pass.apply_failed', [
                'user_id' => $request->user()->id,
                'code' => $earlyPass->code,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'An error occurred. Please try again.']);
        }

        $this->activationNotifier->send($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Early access activated! Redirecting...',
            'redirect_url' => route('management.dashboard'),
        ]);
    }
}
