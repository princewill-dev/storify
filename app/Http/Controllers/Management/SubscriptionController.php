<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\EarlyPass;
use App\Models\Payment;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\KycApplication;
use App\Models\Subscription as SubscriptionModel;
use App\Models\Transaction;
use App\Models\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use App\Mail\VendorStoreCreated;
use App\Mail\AdminStoreCreated;
use App\Models\Coupon;
use App\Models\Setting;

class SubscriptionController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    protected function trialSettings(): array
    {
        static $settings;
        if ($settings === null) {
            $s = Setting::first();
            $settings = [
                'enabled' => $s?->trial_enabled ?? true,
                'days' => (int) ($s?->trial_days ?? 7),
            ];
        }
        return $settings;
    }

    public function showSubscriptionPlan(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $subscription = $user->business?->activeSubscription()?->first();
        $payments = collect();
        $plans = SubscriptionPlan::active()->where('is_trial', false)->orderBy('sort_order')->get();
        $trial = $this->trialSettings();

        if ($subscription) {
            $payments = Payment::where('vendor_subscription_id', $subscription->id)
                ->latest()
                ->take(20)
                ->get();
        }

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Subscription'],
        ];

        return view('management.subscription.plan', [
            'user' => $user,
            'subscription' => $subscription,
            'payments' => $payments,
            'plans' => $plans,
            'trialEnabled' => $trial['enabled'],
            'trialDays' => $trial['days'],
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function changePlan(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $subscription = $user->business?->activeSubscription()?->first();

        if (!$subscription) {
            return redirect()->route('management.subscription.plan')
                ->with('error', 'No active subscription found.');
        }

        $request->validate(['plan_id' => 'required|exists:subscription_plans,id']);

        $newPlan = SubscriptionPlan::where('id', $request->plan_id)
            ->where('is_trial', false)
            ->active()
            ->first();

        if (!$newPlan || $newPlan->id === $subscription->subscription_plan_id) {
            return back()->with('error', 'Invalid plan selection.');
        }

        $subscription->update([
            'subscription_plan_id' => $newPlan->id,
        ]);

        Log::info('subscription.plan_changed', [
            'user_id' => $user->id,
            'old_plan_id' => $subscription->getOriginal('subscription_plan_id'),
            'new_plan_id' => $newPlan->id,
        ]);

        return back()->with('success', "Your plan has been changed to {$newPlan->name}. The new billing amount will apply on your next renewal.");
    }

    public function selectPlan(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if ($user->business?->hasActiveSubscription()) {
            return redirect()->route('management.dashboard')
                ->with('warning', 'You already have an active subscription.');
        }

        $request->validate(['plan_id' => 'required|exists:subscription_plans,id']);

        $plan = SubscriptionPlan::where('id', $request->plan_id)
            ->where('is_trial', false)
            ->active()
            ->first();

        if (!$plan) {
            return back()->with('error', 'Invalid plan selection.');
        }

        $trial = $this->trialSettings();

        $user->update([
            'selected_plan_id' => $plan->id,
            'is_verified' => true,
            'trial_ends_at' => $trial['enabled'] ? now()->addDays($trial['days']) : null,
        ]);

        if (!$trial['enabled']) {
            return redirect()->route('management.subscription.payment')
                ->with('info', 'Please complete payment to activate your subscription.');
        }

        return redirect()->route('management.dashboard')
            ->with('success', "Your {$trial['days']}-day free trial has started! Your stores are live. Choose a paid plan before your trial ends to keep selling.");
    }

    public function showPayment(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if ($user->business?->hasActiveSubscription()) {
            return redirect()->route('management.dashboard')
                ->with('info', 'You already have an active subscription.');
        }

        if (!$user->selected_plan_id) {
            return redirect()->route('management.subscription.plan')
                ->with('warning', 'Please select a plan first.');
        }

        $plan = SubscriptionPlan::find($user->selected_plan_id);

        if (!$plan) {
            return redirect()->route('management.subscription.plan')
                ->with('error', 'Selected plan no longer available.');
        }

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Subscription', 'url' => route('management.subscription.plan')],
            ['label' => 'Payment'],
        ];

        return view('management.subscription.payment', [
            'user' => $user,
            'plan' => $plan,
            'paystackPublicKey' => $this->paystackService->getPublicKey(),
            'isOnTrial' => $user->isOnTrial(),
            'daysLeft' => $user->daysLeftOnTrial(),
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function processPayment(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if ($user->business?->hasActiveSubscription()) {
            return redirect()->route('management.dashboard')
                ->with('error', 'You already have an active subscription.');
        }

        $plan = $user->selected_plan_id
            ? SubscriptionPlan::find($user->selected_plan_id)
            : SubscriptionPlan::active()->default()->first();

        if (!$plan) {
            return back()->with('error', 'No subscription plan available.');
        }

        DB::beginTransaction();
        try {
            $amount = (float) $plan->amount;
            $couponCode = session('applied_coupon_code', $request->coupon_code);

            if ($couponCode) {
                $coupon = Coupon::where('code', $couponCode)->first();
                if ($coupon && $coupon->isValid()) {
                    $discount = $coupon->calculateDiscount($amount);
                    $amount = max(0, $amount - $discount);
                }
            }

            $subscription = SubscriptionModel::create([
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'subscription_plan_id' => $plan->id,
                'status' => SubscriptionModel::STATUS_PENDING,
            ]);

            $payment = Payment::create([
                'user_id' => $user->id,
                'vendor_subscription_id' => $subscription->id,
                'amount' => $amount,
                'currency' => $plan->currency,
                'status' => Payment::STATUS_PENDING,
                'payment_type' => Payment::TYPE_SUBSCRIPTION,
                'ip_address' => $request->ip(),
                'metadata' => [
                    'plan_name' => $plan->name,
                    'plan_id' => $plan->id,
                    'coupon_code' => $couponCode ?? null,
                ],
            ]);

            $paystackMethod = PaymentMethod::where('code', 'paystack')->first();
            Transaction::create([
                'reference' => $payment->reference,
                'payment_method_id' => $paystackMethod?->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => TransactionStatus::PENDING,
                'metadata' => [
                    'payment_id' => $payment->id,
                    'payment_type' => 'subscription',
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                ],
            ]);

            $paystackData = [
                'email' => $user->email,
                'amount' => $payment->amount * 100,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'callback_url' => route('management.subscription.callback'),
                'metadata' => [
                    'user_id' => $user->id,
                    'payment_id' => $payment->id,
                    'subscription_id' => $subscription->id,
                    'plan_name' => $plan->name,
                ],
            ];

            $result = $this->paystackService->initializePayment($paystackData);

            if (!$result['success']) {
                DB::rollBack();
                return back()->with('error', 'Failed to initialize payment. Please try again.');
            }

            $payment->update(['gateway_response' => $result['data']]);
            DB::commit();

            session(['pending_subscription_payment' => $payment->id]);

            return redirect()->away($result['data']['authorization_url']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('subscription.payment.exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'An error occurred. Please try again.');
        }
    }

    public function handleCallback(Request $request): RedirectResponse
    {
        $user = $request->user();
        $reference = $request->query('reference');

        Log::info('subscription.callback.received', [
            'user_id' => $user->id,
            'reference' => $reference,
        ]);

        if (!$reference) {
            return redirect()->route('management.subscription.plan')
                ->with('error', 'Invalid payment reference.');
        }

        $payment = Payment::where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (!$payment) {
            return redirect()->route('management.subscription.plan')
                ->with('error', 'Payment record not found.');
        }

        if ($payment->status === Payment::STATUS_SUCCESS) {
            return redirect()->route('management.dashboard')
                ->with('success', 'Payment already processed successfully!');
        }

        $verification = $this->paystackService->doubleVerifyPayment($reference);

        if (!$verification['success']) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_reason' => $verification['message'],
            ]);
            return redirect()->route('management.subscription.payment')
                ->with('error', 'Payment verification failed. Please try again.');
        }

        $txnData = $verification['data'];
        $txnStatus = strtolower($txnData['status'] ?? '');

        DB::beginTransaction();
        try {
            if ($txnStatus === 'success') {
                $payment->update([
                    'status' => Payment::STATUS_SUCCESS,
                    'gateway_reference' => $txnData['id'] ?? null,
                    'gateway_response' => $txnData,
                    'paid_at' => now(),
                ]);

                $transaction = Transaction::where('reference', $payment->reference)->first();
                if ($transaction) {
                    $transaction->update([
                        'status' => TransactionStatus::CONFIRMED,
                        'gateway_reference' => $txnData['id'] ?? null,
                        'gateway_response' => $txnData,
                        'paid_at' => now(),
                    ]);
                }

                if ($payment->vendorSubscription) {
                    $plan = $payment->vendorSubscription->subscriptionPlan;
                    $startsAt = now();
                    $expiresAt = $plan->interval === 'monthly'
                        ? now()->addMonth()
                        : now()->addYear();

                    $payment->vendorSubscription->update([
                        'status' => SubscriptionModel::STATUS_ACTIVE,
                        'starts_at' => $startsAt,
                        'expires_at' => $expiresAt,
                        'metadata' => [
                            'payment_id' => $payment->id,
                            'payment_reference' => $payment->reference,
                            'activated_at' => now()->toDateTimeString(),
                        ],
                    ]);

                    $user->update(['trial_ends_at' => null]);

                    if ($user->status !== 'active' || !$user->is_verified) {
                        $user->update([
                            'status' => 'active',
                            'is_verified' => true,
                        ]);
                    }

                    $inactiveStores = $user->stores()->whereIn('status', [
                        Store::STATUS_PENDING,
                        Store::STATUS_SUSPENDED,
                    ])->get();

                    if ($inactiveStores->isNotEmpty()) {
                        Store::whereIn('id', $inactiveStores->pluck('id')->all())
                            ->update(['status' => Store::STATUS_ACTIVE]);
                    }
                }

                DB::commit();

                session()->forget('pending_subscription_payment');
                session()->forget('applied_coupon_code');

                if ($payment->metadata['coupon_code'] ?? null) {
                    $usedCoupon = Coupon::where('code', $payment->metadata['coupon_code'])->first();
                    if ($usedCoupon) $usedCoupon->incrementUsage();
                }

                return redirect()->route('management.dashboard')
                    ->with('success', 'Subscription payment successful! Your account has been activated.');
            } else {
                $payment->update([
                    'status' => Payment::STATUS_FAILED,
                    'gateway_reference' => $txnData['id'] ?? null,
                    'gateway_response' => $txnData,
                    'failure_reason' => $txnData['gateway_response'] ?? 'Payment was not successful',
                ]);

                $transaction = Transaction::where('reference', $payment->reference)->first();
                if ($transaction) {
                    $transaction->update([
                        'status' => TransactionStatus::CANCELED,
                        'gateway_response' => $txnData,
                    ]);
                }

                DB::commit();

                return redirect()->route('management.subscription.payment')
                    ->with('error', 'Payment was not successful. Please try again.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('subscription.callback.exception', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('management.subscription.payment')
                ->with('error', 'An error occurred while processing your payment.');
        }
    }

    public function checkEarlyPass(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = trim($request->input('code', ''));

        if (empty($code)) {
            return response()->json(['success' => false, 'message' => 'Please enter a code']);
        }

        $earlyPass = EarlyPass::where('code', $code)->first();

        if (!$earlyPass) {
            return response()->json(['success' => false, 'message' => 'Invalid code. Please check and try again.']);
        }

        if (!$earlyPass->isAvailable()) {
            return response()->json(['success' => false, 'message' => 'This code is not valid or expired.']);
        }

        if ($earlyPass->usages()->where('user_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'You have already used this code.']);
        }

        if ($user->business?->hasActiveSubscription()) {
            return response()->json(['success' => false, 'message' => 'You already have an active subscription.']);
        }

        $plan = SubscriptionPlan::active()->default()->first();

        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'No subscription plan available.']);
        }

        DB::beginTransaction();
        try {
            $subscription = SubscriptionModel::create([
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'subscription_plan_id' => $plan->id,
                'status' => SubscriptionModel::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => now()->addYear(),
                'metadata' => [
                    'early_pass_id' => $earlyPass->id,
                    'early_pass_code' => $earlyPass->code,
                    'activated_at' => now()->toDateTimeString(),
                    'payment_skipped' => true,
                ],
            ]);

            $user->update(['trial_ends_at' => null]);

            if ($user->status !== 'active' || !$user->is_verified) {
                $user->update(['status' => 'active', 'is_verified' => true]);
            }

            $stores = $user->stores()->whereIn('status', [
                Store::STATUS_PENDING,
                Store::STATUS_SUSPENDED,
            ])->get();

            Store::whereIn('id', $stores->pluck('id'))->update(['status' => Store::STATUS_ACTIVE]);

            $activeStore = $stores->first();
            $earlyPass->markAsUsed($user->id, $activeStore?->id);

            $store = $user->stores()->first();

            DB::commit();

            $this->sendStoreActivationEmails($user);

            return response()->json([
                'success' => true,
                'message' => 'Early access activated! Redirecting...',
                'redirect_url' => route('management.store.success', ['user' => $user]),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('early_pass.apply_failed', [
                'user_id' => $user->id,
                'code' => $code,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'An error occurred. Please try again.']);
        }
    }

    public function showPlans(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('management.auth.login');
        if (!$user->is_verified) return redirect()->route('management.auth.verify-otp', ['user' => $user]);

        $plans = SubscriptionPlan::active()->where('is_trial', false)->orderBy('sort_order')->get();
        $trial = $this->trialSettings();
        return view('auth.business.plans', compact('user', 'plans', 'trial'));
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $code = $request->input('code', '');
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon || !$coupon->isValid()) {
            return response()->json(['valid' => false, 'message' => 'Invalid or expired coupon code.']);
        }

        $description = $coupon->discount_type === 'percentage'
            ? number_format($coupon->discount_value, 0) . '% off'
            : '₦' . number_format($coupon->discount_value, 2) . ' off';

        session(['applied_coupon_code' => $code]);

        return response()->json([
            'valid' => true,
            'description' => '✓ ' . $description . ' applied! Discount will be shown at checkout.',
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
        ]);
    }

    public function showCheckout(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        return redirect()->route('management.subscription.plan')
            ->with('warning', 'Please select a plan to start your free trial.');
    }

    private function sendStoreActivationEmails(User $user): void
    {
        $store = $user->stores()->latest()->first();

        if (!$store) {
            return;
        }

        if (!empty($user->email)) {
            try {
                Mail::to($user->email)->queue(new VendorStoreCreated($store));
            } catch (\Throwable $e) {
                Log::error('store_live_email_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $admins = User::where('role', 'superadmin')->pluck('email')->filter()->all();
        if (!empty($admins)) {
            try {
                Mail::to($admins)->queue(new AdminStoreCreated($store));
            } catch (\Throwable $e) {
                Log::error('admin_notification_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
