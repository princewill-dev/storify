<?php

namespace App\Http\Controllers\Management;

use App\Actions\Subscriptions\ActivateSubscriptionWithCoupon;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Services\PaystackService;
use App\Services\StoreActivationNotifier;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class SubscriptionPaymentController extends Controller
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly ActivateSubscriptionWithCoupon $couponActivator,
        private readonly StoreActivationNotifier $activationNotifier,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->business?->hasActiveSubscription()) {
            return redirect()->route('management.dashboard')->with('info', 'You already have an active subscription.');
        }
        if (! $user->selected_plan_id) {
            return redirect()->route('management.subscription.plan')->with('warning', 'Please select a plan first.');
        }

        $plan = SubscriptionPlan::find($user->selected_plan_id);
        if (! $plan) {
            return redirect()->route('management.subscription.plan')->with('error', 'Selected plan is no longer available.');
        }

        return view('management.subscription.payment', [
            'user' => $user,
            'plan' => $plan,
            'paystackPublicKey' => $this->paystack->getPublicKey(),
            'isOnTrial' => $user->isOnTrial(),
            'daysLeft' => $user->daysLeftOnTrial(),
            'paymentAttemptKey' => (string) Str::uuid(),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('management.dashboard')],
                ['label' => 'Subscription', 'url' => route('management.subscription.plan')],
                ['label' => 'Payment'],
            ],
        ]);
    }

    public function initialize(Request $request): RedirectResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:100']]);
        $user = $request->user();

        if ($user->business?->hasActiveSubscription()) {
            return redirect()->route('management.dashboard')->with('error', 'You already have an active subscription.');
        }

        $plan = $user->selected_plan_id
            ? SubscriptionPlan::active()->find($user->selected_plan_id)
            : SubscriptionPlan::active()->default()->first();
        if (! $plan) {
            return back()->with('error', 'No subscription plan available.');
        }

        $coupon = session('applied_coupon_code')
            ? Coupon::query()->where('code', session('applied_coupon_code'))->first()
            : null;
        $amount = (float) $plan->amount;

        if ($coupon?->isValid() && $coupon->isApplicableTo($plan->id)) {
            $amount = max(0, $amount - $coupon->calculateDiscount($amount));
        } else {
            $coupon = null;
            session()->forget('applied_coupon_code');
        }

        if ($coupon && $amount <= 0) {
            try {
                $result = $this->couponActivator->execute($user, $plan, $coupon);
                $this->activationNotifier->send($user);
                session()->forget('applied_coupon_code');

                return redirect()->route('management.dashboard')
                    ->with('success', "{$result->subscription->subscriptionPlan?->name} activated successfully.");
            } catch (DomainException $e) {
                return back()->with('error', $e->getMessage());
            } catch (Throwable $e) {
                Log::error('subscription.coupon_activation_failed', [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'coupon_id' => $coupon->id,
                    'error' => $e->getMessage(),
                ]);

                return back()->with('error', 'The subscription could not be activated. Please try again.');
            }
        }

        $existing = Payment::query()
            ->where('business_id', $user->business_id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            $url = data_get($existing->gateway_response, 'authorization_url');

            return $url ? redirect()->away($url) : back()->with('error', 'This payment attempt could not be initialized. Reload and try again.');
        }

        [$subscription, $payment] = DB::transaction(function () use ($user, $plan, $amount, $coupon, $request, $data) {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'subscription_plan_id' => $plan->id,
                'status' => Subscription::STATUS_PENDING,
            ]);
            $payment = Payment::create([
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'subscription_id' => $subscription->id,
                'idempotency_key' => $data['idempotency_key'],
                'amount' => $amount,
                'currency' => $plan->currency,
                'status' => Payment::STATUS_PENDING,
                'payment_type' => Payment::TYPE_SUBSCRIPTION,
                'ip_address' => $request->ip(),
                'metadata' => [
                    'plan_name' => $plan->name,
                    'plan_id' => $plan->id,
                    'coupon_code' => $coupon?->code,
                ],
            ]);
            $method = PaymentMethod::query()->where('code', 'paystack')->first();
            Transaction::create([
                'reference' => $payment->reference,
                'business_id' => $user->business_id,
                'payment_method_id' => $method?->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => TransactionStatus::PENDING,
                'metadata' => ['payment_id' => $payment->id, 'payment_type' => 'subscription'],
            ]);

            return [$subscription, $payment];
        });

        $result = $this->paystack->initializePayment([
            'email' => $user->email,
            'amount' => (int) round((float) $payment->amount * 100),
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => route('management.subscription.callback'),
            'metadata' => [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'plan_name' => $plan->name,
            ],
        ]);

        if (! $result['success']) {
            DB::transaction(function () use ($payment, $subscription, $result) {
                $payment->update(['status' => Payment::STATUS_FAILED, 'failure_reason' => $result['message'] ?? 'Initialization failed']);
                $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
                Transaction::where('reference', $payment->reference)->update(['status' => TransactionStatus::CANCELED]);
            });

            return back()->with('error', 'Failed to initialize payment. Please try again.');
        }

        $payment->update(['gateway_response' => $result['data']]);

        return redirect()->away($result['data']['authorization_url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $reference = (string) $request->query('reference', '');
        $payment = Payment::query()
            ->where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $payment) {
            return redirect()->route('management.subscription.plan')->with('error', 'Payment record not found.');
        }
        if ($payment->status === Payment::STATUS_SUCCESS) {
            return redirect()->route('management.dashboard')->with('success', 'Payment already processed successfully.');
        }

        $verification = $this->paystack->doubleVerifyPayment($reference);
        $gateway = $verification['data'] ?? [];
        $verifiedAmount = (int) ($gateway['amount'] ?? -1);
        $expectedAmount = (int) round((float) $payment->amount * 100);
        $verifiedCurrency = strtoupper((string) ($gateway['currency'] ?? ''));

        if (! $verification['success']
            || strtolower((string) ($gateway['status'] ?? '')) !== 'success'
            || $verifiedAmount !== $expectedAmount
            || $verifiedCurrency !== strtoupper($payment->currency)) {
            $payment->update(['status' => Payment::STATUS_FAILED, 'failure_reason' => 'Gateway verification mismatch.']);

            return redirect()->route('management.subscription.payment')->with('error', 'Payment verification failed.');
        }

        $activated = DB::transaction(function () use ($payment, $gateway, $request): bool {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($lockedPayment->status === Payment::STATUS_SUCCESS) {
                return false;
            }

            $lockedPayment->update([
                'status' => Payment::STATUS_SUCCESS,
                'gateway_reference' => $gateway['id'] ?? null,
                'gateway_response' => $gateway,
                'paid_at' => now(),
            ]);
            Transaction::where('reference', $lockedPayment->reference)->update([
                'status' => TransactionStatus::CONFIRMED,
                'gateway_reference' => $gateway['id'] ?? null,
                'gateway_response' => $gateway,
                'paid_at' => now(),
            ]);

            $subscription = Subscription::query()->lockForUpdate()->findOrFail($lockedPayment->subscription_id);
            $plan = $subscription->subscriptionPlan;
            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => $plan->interval === 'monthly' ? now()->addMonth() : now()->addYear(),
                'metadata' => ['payment_id' => $lockedPayment->id, 'payment_reference' => $lockedPayment->reference],
            ]);

            $request->user()->update(['trial_ends_at' => null, 'status' => 'active', 'is_verified' => true]);
            Store::query()
                ->where('business_id', $lockedPayment->business_id)
                ->whereIn('status', [Store::STATUS_PENDING, Store::STATUS_SUSPENDED])
                ->update(['status' => Store::STATUS_ACTIVE]);

            $couponCode = data_get($lockedPayment->metadata, 'coupon_code');
            if ($couponCode) {
                $coupon = Coupon::query()->where('code', $couponCode)->lockForUpdate()->first();
                if ($coupon && $coupon->isValid()) {
                    $coupon->increment('uses_count');
                    $coupon->refresh();
                    if ($coupon->isExhausted()) {
                        $coupon->update(['is_active' => false]);
                    }
                }
            }

            return true;
        });

        if ($activated) {
            $this->activationNotifier->send($request->user());
            Log::info('subscription.payment_confirmed', ['payment_id' => $payment->id, 'user_id' => $request->user()->id]);
        }

        session()->forget(['applied_coupon_code', 'pending_subscription_payment']);

        return redirect()->route('management.dashboard')->with('success', 'Subscription payment successful.');
    }
}
