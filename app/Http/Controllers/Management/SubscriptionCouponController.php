<?php

namespace App\Http\Controllers\Management;

use App\Actions\Subscriptions\ActivateSubscriptionWithCoupon;
use App\Http\Controllers\Controller;
use App\Mail\CouponExhaustedMail;
use App\Models\Coupon;
use App\Services\StoreActivationNotifier;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class SubscriptionCouponController extends Controller
{
    public function __construct(
        private readonly ActivateSubscriptionWithCoupon $activator,
        private readonly StoreActivationNotifier $activationNotifier,
    ) {}

    public function validateCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
        ]);
        $code = strtoupper(trim($data['code']));
        $coupon = Coupon::query()->where('code', $code)->first();

        if (! $coupon?->isValid()) {
            return response()->json(['valid' => false, 'message' => 'Invalid or expired coupon code.']);
        }

        if (! empty($data['plan_id']) && ! $coupon->isApplicableTo((int) $data['plan_id'])) {
            $planName = $coupon->subscriptionPlan?->name ?? 'another plan';

            return response()->json(['valid' => false, 'message' => "This coupon only applies to {$planName}."]);
        }

        $plan = $coupon->subscriptionPlan;
        if ($plan && $this->activator->fullyCovers($coupon, $plan)) {
            try {
                $result = $this->activator->execute($request->user(), $plan, $coupon);
            } catch (DomainException $e) {
                return response()->json(['valid' => false, 'message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::error('subscription.coupon_activation_failed', [
                    'user_id' => $request->user()->id,
                    'coupon_code' => $coupon->code,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['valid' => false, 'message' => 'Could not activate this plan. Please try again.']);
            }

            if ($result->couponExhausted) {
                $this->notifyCouponExhausted($result->coupon);
            }
            $this->activationNotifier->send($request->user());

            return response()->json([
                'valid' => true,
                'code' => $code,
                'activated' => true,
                'message' => '✓ '.$plan->name.' activated! Taking you to your dashboard...',
                'redirect_url' => route('management.dashboard'),
            ]);
        }

        session(['applied_coupon_code' => $code]);
        $description = $coupon->discount_type === 'percentage'
            ? number_format($coupon->discount_value, 0).'% off'
            : '₦'.number_format($coupon->discount_value, 2).' off';
        $planLabel = $plan ? ' on '.$plan->name : ' on any plan';

        return response()->json([
            'valid' => true,
            'code' => $code,
            'description' => '✓ '.$description.$planLabel.' applied! Discount will be shown at checkout.',
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'plan_name' => $plan?->name,
        ]);
    }

    public function remove(): JsonResponse
    {
        session()->forget('applied_coupon_code');

        return response()->json(['success' => true]);
    }

    private function notifyCouponExhausted(Coupon $coupon): void
    {
        $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->queue(new CouponExhaustedMail($coupon));
        } catch (\Throwable $e) {
            Log::error('coupon.exhausted_email_failed', ['coupon_code' => $coupon->code, 'error' => $e->getMessage()]);
        }
    }
}
