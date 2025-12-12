<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Vendor;
use App\Models\VendorSubscription;
use App\Services\PaystackService;
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

        if (!$vendor->is_verified) {
            Log::info('vendor.subscription.unverified_vendor', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.auth.verify-otp', ['vendor' => $vendor])
                ->with('warning', 'Please verify your email to continue.');
        }

        if (!$vendor->stores()->exists()) {
            Log::info('vendor.subscription.no_store', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor])
                ->with('warning', 'Please create your store first.');
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

                    if ($vendor->status !== Vendor::STATUS_ACTIVE) {
                        $oldVendorStatus = $vendor->status;
                        $vendor->update(['status' => Vendor::STATUS_ACTIVE]);
                        
                        Log::info('vendor.account.auto_activated', [
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

                return redirect()->route('vendor.dashboard')
                    ->with('success', 'Subscription payment successful! Your account and store have been activated.');
            } else {
                $payment->update([
                    'status' => Payment::STATUS_FAILED,
                    'gateway_reference' => $txnData['id'] ?? null,
                    'gateway_response' => $txnData,
                    'failure_reason' => $txnData['gateway_response'] ?? 'Payment was not successful',
                ]);

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
}
