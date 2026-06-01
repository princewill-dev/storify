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

class SubscriptionController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    public function showSubscriptionPlan(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if ($user->business?->hasActiveSubscription()) {
            Log::info('vendor.subscription.already_subscribed', [
                'user_id' => $user->id,
                'subscription_id' => $user->activeSubscription->id ?? null,
            ]);
            return redirect()->route('management.dashboard')
                ->with('info', 'You already have an active subscription.');
        }

        $plans = SubscriptionPlan::active()->orderBy('sort_order')->get();
        $defaultPlan = $plans->where('is_default', true)->first() ?? $plans->where('is_trial', false)->first();

        if ($plans->isEmpty()) {
            Log::error('vendor.subscription.no_plan_available', [
                'user_id' => $user->id,
            ]);
            return redirect()->route('management.dashboard')
                ->with('error', 'No subscription plan available at the moment. Please contact support.');
        }

        Log::info('vendor.subscription.plan_viewed', [
            'user_id' => $user->id,
            'plans_count' => $plans->count(),
        ]);

        return view('management.subscription.plan', [
            'vendor' => $user,
            'plans' => $plans,
            'defaultPlan' => $defaultPlan,
            'paystackPublicKey' => $this->paystackService->getPublicKey(),
        ]);
    }

    /**
     * Activate a free trial subscription for the vendor.
     */
    public function activateTrial(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if ($user->business?->hasActiveSubscription()) {
            return redirect()->route('management.dashboard')
                ->with('warning', 'You already have an active subscription.');
        }

        $plan = $request->plan_id
            ? SubscriptionPlan::find($request->plan_id)
            : SubscriptionPlan::active()->trial()->first();

        if (!$plan || !$plan->is_trial) {
            return back()->with('error', 'No trial plan available.');
        }

        DB::beginTransaction();
        try {
            SubscriptionModel::create([
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => $plan->getTrialExpiresAt(),
                'metadata' => [
                    'is_trial' => true,
                    'trial_days' => $plan->trial_days,
                    'activated_at' => now()->toDateTimeString(),
                ],
            ]);

            $user->update([
                'is_verified' => true,
            ]);

            DB::commit();

            return redirect()->route('management.dashboard')
                ->with('success', 'Free trial activated! Welcome to Storify.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('subscription.trial.failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Something went wrong. Please try again.');
        }
    }

    public function initializePayment(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if ($user->business?->hasActiveSubscription()) {
            Log::warning('vendor.subscription.payment.already_subscribed', [
                'user_id' => $user->id,
            ]);
            return redirect()->route('management.dashboard')
                ->with('error', 'You already have an active subscription.');
        }

        $plan = $request->plan_id ? SubscriptionPlan::find($request->plan_id) : SubscriptionPlan::active()->default()->first();

        if (!$plan) {
            Log::error('vendor.subscription.payment.no_plan', [
                'user_id' => $user->id,
            ]);
            return back()->with('error', 'No subscription plan available.');
        }

        DB::beginTransaction();
        try {
            $amount = (float) $plan->amount;
            $couponCode = session('applied_coupon_code', $request->coupon_code);
            $coupon = null;

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
                    'vendor_email' => $user->email,
                    'coupon_code' => $couponCode ?? null,
                ],
            ]);

            // Create Transaction record
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

            Log::info('vendor.subscription.payment.created', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'subscription_id' => $subscription->id,
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ]);

            $paystackData = [
                'email' => $user->email,
                'amount' => $payment->amount * 100,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'callback_url' => route('management.subscription.callback'),
                'metadata' => [
                    'user_id' => $user->id,
                    'vendor_account_id' => $user->account_id,
                    'payment_id' => $payment->id,
                    'payment_code' => $payment->payment_code,
                    'subscription_id' => $subscription->id,
                    'plan_name' => $plan->name,
                ],
            ];

            $result = $this->paystackService->initializePayment($paystackData);

            if (!$result['success']) {
                DB::rollBack();
                Log::error('vendor.subscription.payment.initialize_failed', [
                    'user_id' => $user->id,
                    'payment_id' => $payment->id,
                    'error' => $result['message'],
                ]);
                return back()->with('error', 'Failed to initialize payment. Please try again.');
            }

            $payment->update([
                'gateway_response' => $result['data'],
            ]);

            DB::commit();

            Log::info('vendor.subscription.payment.initialized', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'authorization_url' => $result['data']['authorization_url'] ?? null,
            ]);

            session(['pending_subscription_payment' => $payment->id]);

            return redirect()->away($result['data']['authorization_url']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('vendor.subscription.payment.exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'An error occurred. Please try again.');
        }
    }

    public function handleCallback(Request $request): RedirectResponse
    {
        $user = $request->user();
        $reference = $request->query('reference');
        $trxref = $request->query('trxref');

        Log::info('vendor.subscription.callback.received', [
            'user_id' => $user->id,
            'reference' => $reference,
            'trxref' => $trxref,
            'query_params' => $request->query(),
        ]);

        if (!$reference) {
            Log::warning('vendor.subscription.callback.no_reference', [
                'user_id' => $user->id,
            ]);
            return redirect()->route('management.subscription.plan')
                ->with('error', 'Invalid payment reference.');
        }

        $payment = Payment::where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (!$payment) {
            Log::error('vendor.subscription.callback.payment_not_found', [
                'user_id' => $user->id,
                'reference' => $reference,
            ]);
            return redirect()->route('management.subscription.plan')
                ->with('error', 'Payment record not found.');
        }

        if ($payment->status === Payment::STATUS_SUCCESS) {
            Log::info('vendor.subscription.callback.already_processed', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('management.dashboard')
                ->with('success', 'Payment already processed successfully!');
        }

        $verification = $this->paystackService->doubleVerifyPayment($reference);

        if (!$verification['success']) {
            Log::error('vendor.subscription.callback.verification_failed', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'reference' => $reference,
                'error' => $verification['message'],
            ]);

            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_reason' => $verification['message'],
            ]);

            return redirect()->route('management.subscription.plan')
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

                // Update Transaction record
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
                    $startsAt = now();
                    $expiresAt = now()->addYear();

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

                    Log::info('vendor.subscription.activated', [
                        'user_id' => $user->id,
                        'subscription_id' => $payment->vendorSubscription->id,
                        'payment_id' => $payment->id,
                        'amount_paid' => $payment->amount,
                        'starts_at' => $startsAt,
                        'expires_at' => $expiresAt,
                    ]);

                    if ($user->status !== 'active' || !$user->is_verified) {
                        $oldVendorStatus = $user->status;
                        $user->update([
                            'status' => 'active',
                            'is_verified' => true,
                        ]);
                        
                        // Auto-approve KYC to allow store creation
                        KycApplication::updateOrCreate(
                            ['user_id' => $user->id],
                            [
                                'status' => KycApplication::STATUS_APPROVED,
                                'approved_at' => now(),
                                'legal_name' => $user->name,
                                'phone_number' => $user->phone,
                                'payload' => ['auto_approved_via_subscription' => true],
                            ]
                        );

                        Log::info('vendor.account.auto_activated_and_verified', [
                            'user_id' => $user->id,
                            'old_status' => $oldVendorStatus,
                            'new_status' => 'active',
                            'subscription_id' => $payment->vendorSubscription->id,
                        ]);
                    }

                    $inactiveStores = $user->stores()->whereIn('status', [
                        \App\Models\Store::STATUS_PENDING,
                        \App\Models\Store::STATUS_SUSPENDED,
                    ])->get();

                    foreach ($inactiveStores as $store) {
                        $oldStoreStatus = $store->status;
                        $store->update(['status' => \App\Models\Store::STATUS_ACTIVE]);
                        
                        Log::info('vendor.store.auto_activated', [
                            'user_id' => $user->id,
                            'store_id' => $store->id,
                            'store_code' => $store->store_id,
                            'old_status' => $oldStoreStatus,
                            'new_status' => \App\Models\Store::STATUS_ACTIVE,
                            'subscription_id' => $payment->vendorSubscription->id,
                        ]);
                    }
                }

                DB::commit();

                session()->forget('pending_subscription_payment');
                session()->forget('applied_coupon_code');

                // Increment coupon usage if applied
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

                // Update Transaction record
                $transaction = Transaction::where('reference', $payment->reference)->first();
                if ($transaction) {
                    $transaction->update([
                        'status' => TransactionStatus::CANCELED,
                        'gateway_response' => $txnData,
                    ]);
                }

                DB::commit();

                Log::warning('vendor.subscription.payment.not_successful', [
                    'user_id' => $user->id,
                    'payment_id' => $payment->id,
                    'txn_status' => $txnStatus,
                ]);

                return redirect()->route('management.subscription.plan')
                    ->with('error', 'Payment was not successful. Please try again.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('vendor.subscription.callback.exception', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->route('management.subscription.plan')
                ->with('error', 'An error occurred while processing your payment.');
        }
    }

    /**
     * Check and apply an early pass code to skip payment.
     */
    public function checkEarlyPass(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = trim($request->input('code', ''));
        
        Log::info('vendor.early_pass.check_request', [
            'user_id' => $user->id,
            'code' => $code,
            'ip' => $request->ip(),
        ]);

        if (empty($code)) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a code',
            ]);
        }

        // Find the early pass
        $earlyPass = EarlyPass::where('code', $code)->first();

        if (!$earlyPass) {
            Log::info('vendor.early_pass.not_found', [
                'user_id' => $user->id,
                'code' => $code,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid code. Please check and try again.',
            ]);
        }

        if (!$earlyPass->isAvailable()) {
            Log::info('vendor.early_pass.not_active', [
                'user_id' => $user->id,
                'code' => $code,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'This code is not valid or expired.',
            ]);
        }

        if ($earlyPass->usages()->where('user_id', $user->id)->exists()) {
             return response()->json([
                 'success' => false,
                 'message' => 'You have already used this code.',
             ]);
        }

        // Check if vendor already has active subscription
        if ($user->business?->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active subscription.',
            ]);
        }

        // Get the default plan
        $plan = SubscriptionPlan::active()->default()->first();

        if (!$plan) {
            Log::error('vendor.early_pass.no_plan', [
                'user_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No subscription plan available.',
            ]);
        }

        DB::beginTransaction();
        try {
            // Create subscription (free via early pass)
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



            // Activate vendor
            if ($user->status !== 'active' || !$user->is_verified) {
                $user->update([
                    'status' => 'active',
                    'is_verified' => true,
                ]);

                // Auto-approve KYC to allow store creation
                KycApplication::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'status' => KycApplication::STATUS_APPROVED,
                        'approved_at' => now(),
                        'legal_name' => $user->name,
                        'phone_number' => $user->phone,
                        'payload' => ['auto_approved_via_early_pass' => true],
                    ]
                );
            }

            // Activate all pending/suspended stores
            $stores = $user->stores()->whereIn('status', [
                Store::STATUS_PENDING,
                Store::STATUS_SUSPENDED,
            ])->get();

            foreach ($stores as $store) {
                $store->update(['status' => Store::STATUS_ACTIVE]);
            }

            // Mark early pass as used
            $activeStore = $stores->first();
            $earlyPass->markAsUsed($user->id, $activeStore?->id);

            // Get the first store for the success redirect
            $store = $user->stores()->first();

            DB::commit();

            Log::info('vendor.early_pass.applied_successfully', [
                'user_id' => $user->id,
                'early_pass_id' => $earlyPass->id,
                'subscription_id' => $subscription->id,
                'stores_activated' => $stores->count(),
            ]);

            // Send "Store is Live" email to vendor and admin
            $this->sendStoreActivationEmails($user);

            // No session needed - success page will query the database
            return response()->json([
                'success' => true,
                'message' => 'Early access activated! Redirecting...',
                'redirect_url' => route('management.store.success', ['vendor' => $user]),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('vendor.early_pass.apply_failed', [
                'user_id' => $user->id,
                'code' => $code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred. Please try again.',
            ]);
        }
    }

    /**
     * Send "Store is Live" email notifications to vendor and admin
     */
    public function showPlans(Request $request): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) return redirect()->route('management.auth.login');
        if (!$vendor->is_verified) return redirect()->route('management.auth.verify-otp', ['vendor' => $vendor]);

        $plans = SubscriptionPlan::active()->orderBy('sort_order')->get();
        return view('auth.business.plans', compact('vendor', 'plans'));
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

    public function showCheckout(Request $request, SubscriptionPlan $plan): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) return redirect()->route('management.auth.login');

        $couponCode = session('applied_coupon_code', $request->coupon_code);
        $discount = 0;

        if ($couponCode) {
            $coupon = Coupon::where('code', $couponCode)->first();
            if ($coupon && $coupon->isValid()) {
                $discount = $coupon->calculateDiscount((float) $plan->amount);
            }
        }

        $total = max(0, (float) $plan->amount - $discount);

        return view('auth.business.checkout', compact('vendor', 'plan', 'couponCode', 'discount', 'total'));
    }

    private function sendStoreActivationEmails(User $user): void
    {
        // Get the latest store
        $store = $user->stores()->latest()->first();
        
        if (!$store) {
            Log::warning('vendor.subscription.email.no_store', [
                'user_id' => $user->id,
            ]);
            return;
        }

        // Send email to vendor
        if (!empty($user->email)) {
            try {
                Mail::to($user->email)->queue(new VendorStoreCreated($store));
                Log::info('vendor.subscription.store_live_email_sent', [
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                    'recipient' => $user->email,
                ]);
            } catch (\Throwable $e) {
                Log::error('vendor.subscription.store_live_email_failed', [
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Send email to admins
        $admins = User::where('role', 'superadmin')->pluck('email')->filter()->all();
        if (!empty($admins)) {
            try {
                Mail::to($admins)->queue(new AdminStoreCreated($store));
                Log::info('vendor.subscription.admin_notification_sent', [
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                    'admin_count' => count($admins),
                ]);
            } catch (\Throwable $e) {
                Log::error('vendor.subscription.admin_notification_failed', [
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
