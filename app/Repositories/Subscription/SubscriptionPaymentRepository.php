<?php

namespace App\Repositories\Subscription;

use App\Enums\TransactionStatus;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query and persistence layer for the WS-08 subscription money path.
 *
 * This class holds no transaction boundaries and never aborts: the workflows
 * that must be atomic live in SubscriptionPaymentService, which composes
 * these primitives inside its own transaction blocks.
 */
final class SubscriptionPaymentRepository
{
    public function businessHasActiveSubscription(?int $businessId): bool
    {
        return Subscription::query()
            ->where('business_id', $businessId)
            ->active()
            ->exists();
    }

    public function findByIdempotencyKey(?int $businessId, string $key): ?Payment
    {
        return Payment::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function findForBusinessByReference(?int $businessId, string $reference): ?Payment
    {
        return Payment::query()
            ->where('reference', $reference)
            ->where('business_id', $businessId)
            ->first();
    }

    /**
     * The plan a checkout should charge for.
     */
    public function resolvePlan(User $user, string $paymentType, ?int $businessId, bool $allowDefault): ?SubscriptionPlan
    {
        // An explicit selection is authoritative: if it is gone, say so rather
        // than silently charging a different plan.
        if ($user->selected_plan_id) {
            return SubscriptionPlan::query()->active()->find($user->selected_plan_id);
        }

        if ($paymentType === Payment::TYPE_RENEWAL) {
            // An explicit renewal should target the plan the business was
            // actually on, not the platform default.
            $previous = Subscription::query()
                ->where('business_id', $businessId)
                ->latest()
                ->first();

            if ($previous) {
                $plan = SubscriptionPlan::query()->active()->find($previous->subscription_plan_id);
                if ($plan) {
                    return $plan;
                }
            }
        }

        return $allowDefault ? SubscriptionPlan::query()->active()->default()->first() : null;
    }

    /**
     * Platform coupons carry no business; business-scoped coupons must
     * belong to the authenticated business.
     */
    public function findCouponByCode(string $code, ?int $businessId): ?Coupon
    {
        $code = strtoupper(trim($code));

        return Coupon::query()
            ->where('code', $code)
            ->where(fn ($q) => $q->whereNull('business_id')->orWhere('business_id', $businessId))
            ->first();
    }

    public function expiredSubscriptionFor(?int $businessId): ?Subscription
    {
        return Subscription::query()
            ->where('business_id', $businessId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->latest('expires_at')
            ->first();
    }

    public function paginateForBusiness(?int $businessId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Payment::query()
            ->with('subscription.subscriptionPlan')
            ->where('business_id', $businessId)
            ->whereIn('payment_type', [Payment::TYPE_SUBSCRIPTION, Payment::TYPE_RENEWAL])
            ->when($status, fn ($q, $status) => $q->where('status', $status))
            // Insertion order is a stable "newest first" even for payments
            // created within the same second.
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function lockPaymentForUpdate(int $paymentId): Payment
    {
        return Payment::query()->lockForUpdate()->findOrFail($paymentId);
    }

    public function lockSubscriptionForUpdate(?int $subscriptionId): Subscription
    {
        return Subscription::query()->lockForUpdate()->findOrFail($subscriptionId);
    }

    public function lockCouponByCode(string $code): ?Coupon
    {
        return Coupon::query()->where('code', $code)->lockForUpdate()->first();
    }

    public function createPendingSubscription(User $user, SubscriptionPlan $plan): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'business_id' => $user->business_id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPendingPayment(User $user, SubscriptionPlan $plan, Subscription $subscription, array $attributes): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'business_id' => $user->business_id,
            'subscription_id' => $subscription->id,
            'status' => Payment::STATUS_PENDING,
            ...$attributes,
        ]);
    }

    public function createPendingTransaction(Payment $payment, string $paymentType): Transaction
    {
        // Read off the payment rather than hard-coded: the platform can bill
        // through more than one gateway, and a transaction filed under the
        // wrong method makes the admin reports — which group by this column —
        // attribute the money to a provider that never took it.
        $method = PaymentMethod::query()->where('code', $payment->payment_gateway)->first();

        return Transaction::create([
            'reference' => $payment->reference,
            'business_id' => $payment->business_id,
            'payment_method_id' => $method?->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => TransactionStatus::PENDING->value,
            'metadata' => ['payment_id' => $payment->id, 'payment_type' => $paymentType],
        ]);
    }

    public function markPaymentFailed(Payment $payment, string $reason): void
    {
        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Everything the gateway call established about this attempt, in one write.
     *
     * The three columns answer three different questions and all three are asked
     * later, in different places: `payment_gateway` is *who to ask* when the
     * payment is verified or refunded, `gateway_response` is what the checkout
     * looked like at the time (the idempotent replay re-reads its
     * `authorization_url`), and `metadata.provider_reference` is the name the
     * provider knows this payment by when it is not the reference we issued.
     *
     * `metadata` is merged, never replaced — it already carries the plan, the
     * coupon and the amount breakdown that activation and the reports read.
     */
    public function recordInitializedGateway(Payment $payment, string $code, ?array $response, ?string $providerReference): void
    {
        $metadata = $payment->metadata ?? [];

        if ($providerReference !== null) {
            $metadata['provider_reference'] = $providerReference;
        }

        $payment->update([
            'payment_gateway' => $code,
            'gateway_response' => $response,
            'metadata' => $metadata,
        ]);
    }

    public function markPaymentSuccessful(Payment $payment, array $gateway, ?string $providerReference = null): void
    {
        $payment->update([
            'status' => Payment::STATUS_SUCCESS,
            'gateway_reference' => $this->gatewayReference($gateway, $providerReference),
            'gateway_response' => $gateway,
            'paid_at' => now(),
        ]);
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function cancelTransactions(string $reference, array $attributes = []): void
    {
        Transaction::where('reference', $reference)->update([
            'status' => TransactionStatus::CANCELED->value,
            ...$attributes,
        ]);
    }

    public function confirmTransactions(string $reference, array $gateway, ?string $providerReference = null): void
    {
        Transaction::where('reference', $reference)->update([
            'status' => TransactionStatus::CONFIRMED->value,
            'gateway_reference' => $this->gatewayReference($gateway, $providerReference),
            'gateway_response' => $gateway,
            'paid_at' => now(),
        ]);
    }

    /**
     * The provider's name for a settled payment, as its deliveries quote it.
     *
     * The caller's name wins when it has one: it comes from the driver, which is
     * the only place that knows whether a payload's `id` is the payment or
     * something nested inside it. Paystack's is the payment, so the two agree.
     * Bachs reports a charge whose `id` is the charge, while the name its
     * webhooks and its lookup are both addressed by is the *checkout* id beside
     * it — and this column has to hold that one or a replayed delivery cannot be
     * matched. The fallback keeps callers that hold only the payload working.
     *
     * @param  array<string, mixed>  $gateway
     */
    private function gatewayReference(array $gateway, ?string $providerReference): ?string
    {
        return $providerReference ?? ($gateway['id'] ?? null);
    }

    public function activateSubscription(Subscription $subscription, ?SubscriptionPlan $plan, Payment $payment): void
    {
        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'expires_at' => $plan?->interval === 'yearly' ? now()->addYear() : now()->addMonth(),
            'metadata' => [
                ...($subscription->metadata ?? []),
                'payment_id' => $payment->id,
                'payment_reference' => $payment->reference,
            ],
        ]);
    }

    public function activateUser(int $userId): void
    {
        User::query()->whereKey($userId)->update([
            'trial_ends_at' => null,
            'status' => 'active',
            'is_verified' => true,
        ]);
    }

    public function activatePendingStores(?int $businessId): void
    {
        Store::query()
            ->where('business_id', $businessId)
            ->whereIn('status', [Store::STATUS_PENDING, Store::STATUS_SUSPENDED])
            ->update(['status' => Store::STATUS_ACTIVE]);
    }

    public function incrementCouponUse(Coupon $coupon): void
    {
        $coupon->increment('uses_count');
        $coupon->refresh();
    }

    public function deactivateCoupon(Coupon $coupon): void
    {
        $coupon->update(['is_active' => false]);
    }
}
