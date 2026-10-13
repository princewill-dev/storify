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
use Illuminate\Support\Facades\Log;

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

    /**
     * The subscription a plan change attaches to.
     *
     * A plan change rides on the term already running, so the attempt has to be
     * filed against that subscription rather than against a fresh one — see
     * SubscriptionPaymentService::startAttempt().
     */
    public function activeSubscriptionFor(?int $businessId): ?Subscription
    {
        return Subscription::query()
            ->where('business_id', $businessId)
            ->active()
            ->latest('id')
            ->first();
    }

    /**
     * The plan an upgrade on this business is waiting to be paid for, if any.
     *
     * Scoped to the live subscription: a pending change on a subscription that
     * has since lapsed is a stale intention, and pricing a checkout from it
     * would sell the remainder of a period that is already over.
     */
    public function pendingPlanFor(?int $businessId): ?SubscriptionPlan
    {
        $subscription = Subscription::query()
            ->where('business_id', $businessId)
            ->whereNotNull('pending_plan_id')
            ->active()
            ->latest('id')
            ->first();

        return $subscription?->pendingPlan;
    }

    /**
     * The prorated difference quoted for that upgrade, in kobo.
     *
     * Null when there is no pending change — which is not the same as zero, and
     * the checkout treats the two differently: null is "there is nothing to pay
     * for", zero is "the change is free and has already been applied".
     */
    public function pendingChargeKobo(?int $businessId): ?int
    {
        $subscription = Subscription::query()
            ->where('business_id', $businessId)
            ->whereNotNull('pending_plan_id')
            ->active()
            ->latest('id')
            ->first();

        $charge = $subscription?->pending_charge_kobo;

        return $charge === null ? null : (int) $charge;
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
        if ($paymentType === Payment::TYPE_PLAN_CHANGE) {
            // Not the user's selection. A plan change was quoted against one
            // specific plan and recorded on the subscription, and pricing it
            // from `selected_plan_id` would charge the difference for whatever
            // plan happens to be selected — which after a walk through the plans
            // page is any of them.
            return $this->pendingPlanFor($businessId);
        }

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
            // A plan change is money this business paid and belongs in its own
            // billing history — it is not a purchase of a term, which is why it
            // is filed under its own type, but it is not invisible either.
            ->whereIn('payment_type', [
                Payment::TYPE_SUBSCRIPTION,
                Payment::TYPE_RENEWAL,
                Payment::TYPE_PLAN_CHANGE,
            ])
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
        // A plan change buys no time. It moves the plan and leaves the period
        // exactly where it was — which is the opposite of what the branch below
        // does, and the reason the two are separate paths rather than one with a
        // conditional date.
        if ($payment->payment_type === Payment::TYPE_PLAN_CHANGE) {
            $this->applyPlanChange($subscription, $payment);

            return;
        }

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

    /**
     * A settled plan change: the plan moves, the period does not.
     *
     * The plan comes from the payment's own metadata rather than from
     * `pending_plan_id`, and the difference matters when a customer abandons one
     * upgrade and starts another: the second request overwrites the pending
     * column, and a first payment that settles afterwards would otherwise be
     * applied to a plan it was never quoted against. Money has moved for a
     * specific plan, so that is the plan that lands — the customer paid the
     * figure they were shown.
     *
     * `expires_at` and `starts_at` are deliberately untouched. The money bought
     * the remainder of a period that was already paid for at the old plan's
     * price, so extending it would hand out free days with every upgrade and
     * truncating it would take back days that were paid for. If the period ran
     * out while the payment was in flight the swap still stands and carries into
     * whatever the business does next, which is the most useful thing to do with
     * a difference somebody has already paid.
     *
     * An unresolvable plan (deleted between quote and settlement) leaves the
     * pending columns in place rather than clearing them: the money is in, the
     * intention is still readable, and an admin can hand over the plan by hand
     * from the business console.
     */
    private function applyPlanChange(Subscription $subscription, Payment $payment): void
    {
        $target = SubscriptionPlan::query()->find(data_get($payment->metadata, 'plan_id'))
            ?? $subscription->pendingPlan;

        if ($target === null) {
            Log::warning('subscriptions.plan_change_unresolvable', [
                'subscription_id' => $subscription->id,
                'payment_id' => $payment->id,
                'pending_plan_id' => $subscription->pending_plan_id,
            ]);

            return;
        }

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_plan_id' => $target->id,
            'pending_plan_id' => null,
            'pending_charge_kobo' => null,
            'pending_requested_at' => null,
            // A paid upgrade supersedes a booked downgrade: the business has
            // just paid to move up, and the earlier intention to move down is
            // spent. It can book another one.
            'scheduled_plan_id' => null,
            'scheduled_at' => null,
            'metadata' => [
                ...($subscription->metadata ?? []),
                'plan_change_payment_id' => $payment->id,
                'plan_change_reference' => $payment->reference,
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
