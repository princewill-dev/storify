<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\EarlyPass;
use App\Models\Payment;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Vendor;
use App\Models\VendorKycApplication;
use App\Models\VendorSubscription;
use App\Models\Transaction;
use App\Models\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class VendorSubscriptionController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    public function showSubscriptionPlan(Request $request, Vendor $vendor): View|RedirectResponse
    {
        $authVendor = $request->user('vendor');

        if (!$authVendor || $authVendor->id !== $vendor->id) {
            Log::warning('vendor.subscription.unauthorized_access', [
                'auth_vendor_id' => $authVendor?->id,
                'requested_vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.auth.login');
        }

        // if (!$vendor->is_verified) {
        //     Log::info('vendor.subscription.unverified_vendor', [
        //         'vendor_id' => $vendor->id,
        //     ]);
        //     return redirect()->route('vendor.auth.verify-otp', ['vendor' => $vendor])
        //         ->with('warning', 'Please verify your email to continue.');
        // }

        if (!$vendor->stores()->exists()) {
            Log::info('vendor.subscription.no_store', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor]);
        }

        if ($vendor->hasActiveSubscription()) {
            Log::info('vendor.subscription.already_subscribed', [
                'vendor_id' => $vendor->id,
                'subscription_id' => $vendor->activeSubscription->id ?? null,
            ]);
            return redirect()->route('vendor.dashboard')
                ->with('info', 'You already have an active subscription.');
        }

        $plan = SubscriptionPlan::active()->default()->first();

        if (!$plan) {
            Log::error('vendor.subscription.no_plan_available', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.dashboard')
                ->with('error', 'No subscription plan available at the moment. Please contact support.');
        }

        Log::info('vendor.subscription.plan_viewed', [
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'plan_amount' => $plan->amount,
        ]);

        return view('vendors.subscription.plan', [
            'vendor' => $vendor,
            'plan' => $plan,
            'paystackPublicKey' => $this->paystackService->getPublicKey(),
        ]);
    }

    public function initializePayment(Request $request, Vendor $vendor): RedirectResponse
    {
        $authVendor = $request->user('vendor');

        if (!$authVendor || $authVendor->id !== $vendor->id) {
            Log::warning('vendor.subscription.payment.unauthorized', [
                'auth_vendor_id' => $authVendor?->id,
                'requested_vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.auth.login');
        }

        if ($vendor->hasActiveSubscription()) {
            Log::warning('vendor.subscription.payment.already_subscribed', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.dashboard')
                ->with('error', 'You already have an active subscription.');
        }

        $plan = SubscriptionPlan::active()->default()->first();

        if (!$plan) {
            Log::error('vendor.subscription.payment.no_plan', [
                'vendor_id' => $vendor->id,
            ]);
            return back()->with('error', 'No subscription plan available.');
        }

        DB::beginTransaction();
        try {
            $subscription = VendorSubscription::create([
                'vendor_id' => $vendor->id,
                'subscription_plan_id' => $plan->id,
                'status' => VendorSubscription::STATUS_PENDING,
            ]);

            $payment = Payment::create([
                'vendor_id' => $vendor->id,
                'vendor_subscription_id' => $subscription->id,
                'amount' => $plan->amount,
                'currency' => $plan->currency,
                'status' => Payment::STATUS_PENDING,
                'payment_type' => Payment::TYPE_SUBSCRIPTION,
                'ip_address' => $request->ip(),
                'metadata' => [
                    'plan_name' => $plan->name,
                    'plan_id' => $plan->id,
                    'vendor_email' => $vendor->email,
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
                'vendor_id' => $vendor->id,
                'payment_id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'subscription_id' => $subscription->id,
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ]);

            $paystackData = [
                'email' => $vendor->email,
                'amount' => $payment->amount * 100,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'callback_url' => route('vendor.subscription.callback', ['vendor' => $vendor]),
                'metadata' => [
                    'vendor_id' => $vendor->id,
                    'vendor_account_id' => $vendor->account_id,
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
                    'vendor_id' => $vendor->id,
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
                'vendor_id' => $vendor->id,
                'payment_id' => $payment->id,
                'authorization_url' => $result['data']['authorization_url'] ?? null,
            ]);

            session(['pending_subscription_payment' => $payment->id]);

            return redirect()->away($result['data']['authorization_url']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('vendor.subscription.payment.exception', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'An error occurred. Please try again.');
        }
    }

    public function handleCallback(Request $request, Vendor $vendor): RedirectResponse
    {
        $reference = $request->query('reference');
        $trxref = $request->query('trxref');

        Log::info('vendor.subscription.callback.received', [
            'vendor_id' => $vendor->id,
            'reference' => $reference,
            'trxref' => $trxref,
            'query_params' => $request->query(),
        ]);

        if (!$reference) {
            Log::warning('vendor.subscription.callback.no_reference', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
                ->with('error', 'Invalid payment reference.');
        }

        $payment = Payment::where('reference', $reference)
            ->where('vendor_id', $vendor->id)
            ->first();

        if (!$payment) {
            Log::error('vendor.subscription.callback.payment_not_found', [
                'vendor_id' => $vendor->id,
                'reference' => $reference,
            ]);
            return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
                ->with('error', 'Payment record not found.');
        }

        if ($payment->status === Payment::STATUS_SUCCESS) {
            Log::info('vendor.subscription.callback.already_processed', [
                'vendor_id' => $vendor->id,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('vendor.dashboard')
                ->with('success', 'Payment already processed successfully!');
        }

        $verification = $this->paystackService->doubleVerifyPayment($reference);

        if (!$verification['success']) {
            Log::error('vendor.subscription.callback.verification_failed', [
                'vendor_id' => $vendor->id,
                'payment_id' => $payment->id,
                'reference' => $reference,
                'error' => $verification['message'],
            ]);

            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_reason' => $verification['message'],
            ]);

            return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
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
                        'status' => VendorSubscription::STATUS_ACTIVE,
                        'starts_at' => $startsAt,
                        'expires_at' => $expiresAt,
                        'metadata' => [
                            'payment_id' => $payment->id,
                            'payment_reference' => $payment->reference,
                            'activated_at' => now()->toDateTimeString(),
                        ],
                    ]);

                    Log::info('vendor.subscription.activated', [
                        'vendor_id' => $vendor->id,
                        'subscription_id' => $payment->vendorSubscription->id,
                        'payment_id' => $payment->id,
                        'amount_paid' => $payment->amount,
                        'starts_at' => $startsAt,
                        'expires_at' => $expiresAt,
                    ]);

                    if ($vendor->status !== Vendor::STATUS_ACTIVE || !$vendor->is_verified) {
                        $oldVendorStatus = $vendor->status;
                        $vendor->update([
                            'status' => Vendor::STATUS_ACTIVE,
                            'is_verified' => true,
                        ]);
                        
                        // Auto-approve KYC to allow store creation
                        VendorKycApplication::updateOrCreate(
                            ['vendor_id' => $vendor->id],
                            [
                                'status' => VendorKycApplication::STATUS_APPROVED,
                                'approved_at' => now(),
                                'legal_name' => $vendor->name,
                                'phone_number' => $vendor->phone,
                                'payload' => ['auto_approved_via_subscription' => true],
                            ]
                        );

                        Log::info('vendor.account.auto_activated_and_verified', [
                            'vendor_id' => $vendor->id,
                            'old_status' => $oldVendorStatus,
                            'new_status' => Vendor::STATUS_ACTIVE,
                            'subscription_id' => $payment->vendorSubscription->id,
                        ]);
                    }

                    $inactiveStores = $vendor->stores()->whereIn('status', [
                        \App\Models\Store::STATUS_PENDING,
                        \App\Models\Store::STATUS_SUSPENDED,
                    ])->get();

                    foreach ($inactiveStores as $store) {
                        $oldStoreStatus = $store->status;
                        $store->update(['status' => \App\Models\Store::STATUS_ACTIVE]);
                        
                        Log::info('vendor.store.auto_activated', [
                            'vendor_id' => $vendor->id,
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

                // Store the first store ID in session for success page
                $firstStore = $vendor->stores()->first();
                session(['onboarding_store_id' => $firstStore?->id]);

                return redirect()->route('vendor.kyc.store.success', ['vendor' => $vendor])
                    ->with('success', 'Subscription payment successful! Your account and store have been activated.');
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
                    'vendor_id' => $vendor->id,
                    'payment_id' => $payment->id,
                    'txn_status' => $txnStatus,
                ]);

                return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
                    ->with('error', 'Payment was not successful. Please try again.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('vendor.subscription.callback.exception', [
                'vendor_id' => $vendor->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
                ->with('error', 'An error occurred while processing your payment.');
        }
    }

    /**
     * Check and apply an early pass code to skip payment.
     */
    public function checkEarlyPass(Request $request, Vendor $vendor): JsonResponse
    {
        $code = trim($request->input('code', ''));
        
        Log::info('vendor.early_pass.check_request', [
            'vendor_id' => $vendor->id,
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
                'vendor_id' => $vendor->id,
                'code' => $code,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid code. Please check and try again.',
            ]);
        }

        if (!$earlyPass->isAvailable()) {
            Log::info('vendor.early_pass.not_active', [
                'vendor_id' => $vendor->id,
                'code' => $code,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'This code is not valid or expired.',
            ]);
        }

        if ($earlyPass->usages()->where('vendor_id', $vendor->id)->exists()) {
             return response()->json([
                 'success' => false,
                 'message' => 'You have already used this code.',
             ]);
        }

        // Check if vendor already has active subscription
        if ($vendor->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active subscription.',
            ]);
        }

        // Get the default plan
        $plan = SubscriptionPlan::active()->default()->first();

        if (!$plan) {
            Log::error('vendor.early_pass.no_plan', [
                'vendor_id' => $vendor->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No subscription plan available.',
            ]);
        }

        DB::beginTransaction();
        try {
            // Create subscription (free via early pass)
            $subscription = VendorSubscription::create([
                'vendor_id' => $vendor->id,
                'subscription_plan_id' => $plan->id,
                'status' => VendorSubscription::STATUS_ACTIVE,
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
            if ($vendor->status !== Vendor::STATUS_ACTIVE || !$vendor->is_verified) {
                $vendor->update([
                    'status' => Vendor::STATUS_ACTIVE,
                    'is_verified' => true,
                ]);

                // Auto-approve KYC to allow store creation
                VendorKycApplication::updateOrCreate(
                    ['vendor_id' => $vendor->id],
                    [
                        'status' => VendorKycApplication::STATUS_APPROVED,
                        'approved_at' => now(),
                        'legal_name' => $vendor->name,
                        'phone_number' => $vendor->phone,
                        'payload' => ['auto_approved_via_early_pass' => true],
                    ]
                );
            }

            // Activate all pending/suspended stores
            $stores = $vendor->stores()->whereIn('status', [
                Store::STATUS_PENDING,
                Store::STATUS_SUSPENDED,
            ])->get();

            foreach ($stores as $store) {
                $store->update(['status' => Store::STATUS_ACTIVE]);
            }

            // Mark early pass as used
            $activeStore = $stores->first();
            $earlyPass->markAsUsed($vendor->id, $activeStore?->id);

            // Get the first store for the success redirect
            $store = $vendor->stores()->first();

            DB::commit();

            Log::info('vendor.early_pass.applied_successfully', [
                'vendor_id' => $vendor->id,
                'early_pass_id' => $earlyPass->id,
                'subscription_id' => $subscription->id,
                'stores_activated' => $stores->count(),
            ]);

            // Store the store ID in session for success page
            session(['onboarding_store_id' => $store?->id]);

            return response()->json([
                'success' => true,
                'message' => 'Early access activated! Redirecting...',
                'redirect_url' => route('vendor.kyc.store.success', ['vendor' => $vendor]),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('vendor.early_pass.apply_failed', [
                'vendor_id' => $vendor->id,
                'code' => $code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred. Please try again.',
            ]);
        }
    }
}
