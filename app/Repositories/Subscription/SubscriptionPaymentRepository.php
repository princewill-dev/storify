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
        $method = PaymentMethod::query()->where('code', 'paystack')->first();

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

    public function setPaymentGatewayResponse(Payment $payment, ?array $response): void
    {
        $payment->update(['gateway_response' => $response]);
    }

    public function markPaymentSuccessful(Payment $payment, array $gateway): void
    {
        $payment->update([
            'status' => Payment::STATUS_SUCCESS,
            'gateway_reference' => $gateway['id'] ?? null,
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

    public function confirmTransactions(string $reference, array $gateway): void
    {
        Transaction::where('reference', $reference)->update([
            'status' => TransactionStatus::CONFIRMED->value,
            'gateway_reference' => $gateway['id'] ?? null,
            'gateway_response' => $gateway,
            'paid_at' => now(),
        ]);
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
