<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Actions\Subscriptions\ActivateSubscriptionWithCoupon;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\CouponExhaustedMail;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\PaystackService;
use App\Services\StoreActivationNotifier;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The subscription money path — checkout, idempotent Paystack initialization
 * and the verified callback that activates the business.
 *
 * Money moves through kobo integers here: the tables keep naira decimals for
 * backwards compatibility, but every calculation (discount, total, gateway
 * comparison) converts to kobo first so no float rounding can creep in.
 */
class PaymentController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly PaystackService $paystack,
        private readonly ActivateSubscriptionWithCoupon $couponActivator,
        private readonly StoreActivationNotifier $activationNotifier,
    ) {}

    /**
     * Checkout summary: the plan about to be paid for, the coupon-adjusted
     * total and a fresh idempotency key.
     */
    public function show(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', Rule::in([Payment::TYPE_SUBSCRIPTION, Payment::TYPE_RENEWAL])],
        ]);

        $user = $this->user($request);
        $paymentType = $filters['payment_type'] ?? Payment::TYPE_SUBSCRIPTION;

        if ($this->businessHasActiveSubscription($request)) {
            return $this->error('You already have an active subscription.', 409);
        }

        // The checkout page only ever shows a plan the user chose; the
        // default-plan fallback belongs to the initialization endpoint. A
        // renewal with no history is really a first purchase, so it may fall
        // back too.
        $plan = $this->resolvePlan($user, $paymentType, $request, allowDefault: $paymentType === Payment::TYPE_RENEWAL);
        if (! $plan) {
            return $this->error(
                $user->selected_plan_id ? 'Selected plan is no longer available.' : 'Please select a plan first.',
                422,
            );
        }

        $planAmountKobo = $this->toKobo($plan->amount);
        $coupon = null;
        $discountKobo = 0;

        if (! empty($filters['coupon_code'])) {
            [$coupon, $couponError] = $this->resolveCoupon($filters['coupon_code'], $plan, $this->businessId($request));
            if ($couponError) {
                return $this->error($couponError, 422);
            }
            $discountKobo = $this->discountKobo($coupon, $planAmountKobo);
        }

        $totalKobo = max(0, $planAmountKobo - $discountKobo);

        return $this->ok([
            'plan' => $this->planPayload($plan),
            'payment_type' => $paymentType,
            'base_amount_kobo' => $planAmountKobo,
            'discount_kobo' => $discountKobo,
            'total_kobo' => $totalKobo,
            'total' => $this->toNaira($totalKobo),
            'currency' => $plan->currency,
            'coupon' => $coupon ? $this->couponPayload($coupon) : null,
            'trial' => [
                'on_trial' => $user->isOnTrial(),
                'days_left' => $user->daysLeftOnTrial(),
                'ends_at' => $user->trial_ends_at?->toISOString(),
            ],
            // Legacy regenerated a UUID per page view; the SPA keeps it for the
            // whole checkout attempt so retries cannot double-charge.
            'idempotency_key' => (string) Str::uuid(),
            'gateway_available' => filled(config('services.paystack.secret_key')),
            'expired_subscription' => $this->expiredSubscriptionPayload($request),
        ]);
    }

    /**
     * Initialize (or replay) a Paystack payment.
     */
    public function initialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', Rule::in([Payment::TYPE_SUBSCRIPTION, Payment::TYPE_RENEWAL])],
            'callback_url' => ['nullable', 'url', 'max:255'],
        ]);

        $user = $this->user($request);
        $paymentType = $data['payment_type'] ?? Payment::TYPE_SUBSCRIPTION;

        if ($this->businessHasActiveSubscription($request)) {
            return $this->error('You already have an active subscription.', 409);
        }

        $plan = $this->resolvePlan($user, $paymentType, $request, allowDefault: true);
        if (! $plan) {
            return $this->error('No subscription plan available.', 422);
        }

        $planAmountKobo = $this->toKobo($plan->amount);
        $coupon = null;
        $discountKobo = 0;

        if (! empty($data['coupon_code'])) {
            [$coupon, $couponError] = $this->resolveCoupon($data['coupon_code'], $plan, $this->businessId($request));
            if ($couponError) {
                // Legacy silently dropped a non-applicable or exhausted coupon
                // here and charged full price with no message — don't.
                return $this->error($couponError, 422);
            }
            $discountKobo = $this->discountKobo($coupon, $planAmountKobo);
        }

        $totalKobo = max(0, $planAmountKobo - $discountKobo);

        if ($coupon && $totalKobo <= 0) {
            return $this->activateWithCoupon($user, $plan, $coupon);
        }

        // Idempotency contract: re-submitting a key re-redirects to the stored
        // authorization URL instead of creating a second charge.
        $existing = $this->findByKey($request, $data['idempotency_key']);
        if ($existing) {
            return $this->replayedResponse($existing);
        }

        try {
            [$subscription, $payment] = DB::transaction(function () use ($request, $user, $plan, $coupon, $data, $paymentType, $planAmountKobo, $discountKobo, $totalKobo) {
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
                    'amount' => $this->toNaira($totalKobo),
                    'currency' => $plan->currency,
                    'status' => Payment::STATUS_PENDING,
                    'payment_type' => $paymentType,
                    'ip_address' => $request->ip(),
                    'metadata' => [
                        'plan_name' => $plan->name,
                        'plan_id' => $plan->id,
                        'plan_interval' => $plan->interval,
                        'coupon_code' => $coupon?->code,
                        'base_amount_kobo' => $planAmountKobo,
                        'discount_kobo' => $discountKobo,
                        'amount_kobo' => $totalKobo,
                    ],
                ]);

                $method = PaymentMethod::query()->where('code', 'paystack')->first();
                Transaction::create([
                    'reference' => $payment->reference,
                    'business_id' => $user->business_id,
                    'payment_method_id' => $method?->id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => TransactionStatus::PENDING->value,
                    'metadata' => ['payment_id' => $payment->id, 'payment_type' => $paymentType],
                ]);

                return [$subscription, $payment];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won the race; hand back
            // whatever it stored rather than creating a second charge.
            $existing = $this->findByKey($request, $data['idempotency_key']);

            return $existing
                ? $this->replayedResponse($existing)
                : $this->error('This payment attempt could not be initialized. Reload and try again.', 422);
        }

        $result = $this->paystack->initializePayment([
            'email' => $user->email,
            'amount' => $totalKobo,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $data['callback_url'] ?? url('/subscription/callback'),
            'metadata' => [
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'plan_name' => $plan->name,
            ],
        ]);

        if (! ($result['success'] ?? false)) {
            DB::transaction(function () use ($payment, $subscription, $result) {
                $payment->update([
                    'status' => Payment::STATUS_FAILED,
                    'failure_reason' => $result['message'] ?? 'Initialization failed.',
                ]);
                $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
                Transaction::where('reference', $payment->reference)
                    ->update(['status' => TransactionStatus::CANCELED->value]);
            });

            Log::warning('api.management.subscription_payment_initialize_failed', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
            ]);

            return $this->error('Failed to initialize payment. Please try again.', 502);
        }

        $payment->update(['gateway_response' => $result['data']]);

        Log::info('api.management.subscription_payment_initialized', [
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'amount_kobo' => $totalKobo,
        ]);

        return $this->ok([
            'redirect_url' => $result['data']['authorization_url'] ?? null,
            'reference' => $payment->reference,
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'total_kobo' => $totalKobo,
            'total' => $this->toNaira($totalKobo),
        ], 'Payment initialized. Redirecting to Paystack.');
    }

    /**
     * Paystack returns the browser here (via the SPA callback route). The
     * payment is verified a second time against the gateway before anything
     * is activated.
     */
    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:255']]);

        $payment = Payment::query()
            ->where('reference', $data['reference'])
            ->where('business_id', $this->businessId($request))
            ->first();

        if (! $payment) {
            return $this->error('Payment record not found.', 404);
        }

        if (! $payment->subscription_id) {
            return $this->error('This payment is not a subscription payment.', 422);
        }

        if ($payment->status === Payment::STATUS_SUCCESS) {
            return $this->ok([
                'already_processed' => true,
                'message' => 'Payment already processed successfully.',
                'redirect' => '/',
                'payment' => $this->paymentPayload($payment),
            ], 'Payment already processed successfully.');
        }

        $verification = $this->paystack->doubleVerifyPayment($payment->reference);
        $gateway = $verification['data'] ?? [];
        $expectedKobo = $this->toKobo($payment->amount);
        $verifiedKobo = (int) ($gateway['amount'] ?? -1);
        $verifiedCurrency = strtoupper((string) ($gateway['currency'] ?? ''));

        if (! ($verification['success'] ?? false)
            || strtolower((string) ($gateway['status'] ?? '')) !== 'success'
            || $verifiedKobo !== $expectedKobo
            || $verifiedCurrency !== strtoupper((string) $payment->currency)) {
            // The transaction is failed with the payment so it cannot sit
            // indefinitely pending in the generic transactions list.
            DB::transaction(function () use ($payment) {
                $payment->update([
                    'status' => Payment::STATUS_FAILED,
                    'failure_reason' => 'Gateway verification mismatch.',
                ]);
                Transaction::where('reference', $payment->reference)->update([
                    'status' => TransactionStatus::CANCELED->value,
                    'metadata' => ['payment_id' => $payment->id, 'payment_type' => $payment->payment_type, 'failure_reason' => 'Gateway verification mismatch.'],
                ]);
            });

            Log::warning('api.management.subscription_payment_verification_failed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'expected_kobo' => $expectedKobo,
                'verified_kobo' => $verifiedKobo,
                'verified_currency' => $verifiedCurrency,
            ]);

            return $this->error('Payment verification failed.', 422);
        }

        [$activated, $exhaustedCoupon] = DB::transaction(function () use ($payment, $gateway) {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status === Payment::STATUS_SUCCESS) {
                return [false, null];
            }

            $lockedPayment->update([
                'status' => Payment::STATUS_SUCCESS,
                'gateway_reference' => $gateway['id'] ?? null,
                'gateway_response' => $gateway,
                'paid_at' => now(),
            ]);

            Transaction::where('reference', $lockedPayment->reference)->update([
                'status' => TransactionStatus::CONFIRMED->value,
                'gateway_reference' => $gateway['id'] ?? null,
                'gateway_response' => $gateway,
                'paid_at' => now(),
            ]);

            $subscription = Subscription::query()->lockForUpdate()->findOrFail($lockedPayment->subscription_id);
            $plan = $subscription->subscriptionPlan;

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => $plan?->interval === 'yearly' ? now()->addYear() : now()->addMonth(),
                'metadata' => [
                    ...($subscription->metadata ?? []),
                    'payment_id' => $lockedPayment->id,
                    'payment_reference' => $lockedPayment->reference,
                ],
            ]);

            User::query()->whereKey($lockedPayment->user_id)->update([
                'trial_ends_at' => null,
                'status' => 'active',
                'is_verified' => true,
            ]);

            Store::query()
                ->where('business_id', $lockedPayment->business_id)
                ->whereIn('status', [Store::STATUS_PENDING, Store::STATUS_SUSPENDED])
                ->update(['status' => Store::STATUS_ACTIVE]);

            $exhaustedCoupon = null;
            $couponCode = data_get($lockedPayment->metadata, 'coupon_code');
            if ($couponCode) {
                $coupon = Coupon::query()->where('code', $couponCode)->lockForUpdate()->first();
                if ($coupon && $coupon->isValid()) {
                    $coupon->increment('uses_count');
                    $coupon->refresh();
                    if ($coupon->isExhausted()) {
                        $coupon->update(['is_active' => false]);
                        $exhaustedCoupon = $coupon;
                    }
                }
            }

            return [true, $exhaustedCoupon];
        });

        if ($activated) {
            $payment->refresh();
            // Notifications and ledger attribution follow the paying user, not
            // whoever opens the callback: a teammate with the subscription
            // permission can complete the return leg, and the owner still has
            // to be told and the journal line attributed to the payer.
            $user = User::query()->find($payment->user_id) ?? $this->user($request);

            $this->activationNotifier->send($user);

            if ($exhaustedCoupon) {
                $this->notifyCouponExhausted($exhaustedCoupon);
            }

            $ledger = app(LedgerPostingService::class);
            $ledger->safe(fn () => $ledger->postSubscriptionPayment($payment, $user->id));

            Log::info('api.management.subscription_payment_confirmed', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'subscription_id' => $payment->subscription_id,
            ]);
        }

        return $this->ok([
            'already_processed' => ! $activated,
            'message' => $activated ? 'Subscription payment successful.' : 'Payment already processed successfully.',
            'redirect' => '/',
            'payment' => $this->paymentPayload($payment->fresh()),
            'subscription' => $this->subscriptionPayload($payment->fresh()->subscription),
        ], $activated ? 'Subscription payment successful.' : 'Payment already processed successfully.');
    }

    /**
     * Billing history. Scoped to the business rather than the *active*
     * subscription so payments do not vanish when a term lapses (legacy
     * scoped it to the active subscription and lost the history).
     */
    public function payments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Payment::STATUS_SUCCESS, Payment::STATUS_PENDING, Payment::STATUS_FAILED, Payment::STATUS_ABANDONED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $payments = Payment::query()
            ->with('subscription.subscriptionPlan')
            ->where('business_id', $this->businessId($request))
            ->whereIn('payment_type', [Payment::TYPE_SUBSCRIPTION, Payment::TYPE_RENEWAL])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            // Insertion order is a stable "newest first" even for payments
            // created within the same second.
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            $payments->getCollection()->map(fn (Payment $payment) => $this->paymentPayload($payment))->all(),
            null,
            200,
            $this->paginationMeta($payments),
        );
    }

    /**
     * A fully-covering coupon activates immediately, with no gateway step.
     */
    private function activateWithCoupon(User $user, SubscriptionPlan $plan, Coupon $coupon): JsonResponse
    {
        try {
            $result = $this->couponActivator->execute($user, $plan, $coupon);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Log::error('api.management.subscription_coupon_activation_failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'coupon_code' => $coupon->code,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The subscription could not be activated. Please try again.', 500);
        }

        if ($result->couponExhausted) {
            $this->notifyCouponExhausted($result->coupon);
        }

        $this->activationNotifier->send($user);

        Log::info('api.management.subscription_activated_with_coupon', [
            'user_id' => $user->id,
            'subscription_id' => $result->subscription->id,
            'coupon_code' => $coupon->code,
        ]);

        return $this->ok([
            'activated' => true,
            'redirect' => '/',
            'subscription' => $this->subscriptionPayload($result->subscription),
        ], $plan->name.' activated successfully.');
    }

    private function replayedResponse(Payment $payment): JsonResponse
    {
        $url = data_get($payment->gateway_response, 'authorization_url');

        if (! $url) {
            return $this->error('This payment attempt could not be initialized. Reload and try again.', 422);
        }

        return $this->ok([
            'redirect_url' => $url,
            'reference' => $payment->reference,
            'payment_id' => $payment->id,
            'already_initialized' => true,
        ], 'This payment attempt is already in progress. Redirecting to the gateway.');
    }

    private function findByKey(Request $request, string $key): ?Payment
    {
        return Payment::query()
            ->where('business_id', $this->businessId($request))
            ->where('idempotency_key', $key)
            ->first();
    }

    private function resolvePlan(User $user, string $paymentType, Request $request, bool $allowDefault): ?SubscriptionPlan
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
                ->where('business_id', $this->businessId($request))
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
     * @return array{0: ?Coupon, 1: ?string} coupon and the error message, if any
     */
    private function resolveCoupon(string $code, SubscriptionPlan $plan, ?int $businessId): array
    {
        $code = strtoupper(trim($code));

        // Platform coupons carry no business; business-scoped coupons must
        // belong to the authenticated business.
        $coupon = Coupon::query()
            ->where('code', $code)
            ->where(fn ($q) => $q->whereNull('business_id')->orWhere('business_id', $businessId))
            ->first();

        if (! $coupon?->isValid()) {
            return [null, 'Invalid or expired coupon code.'];
        }

        if (! $coupon->isApplicableTo($plan->id)) {
            $planName = $coupon->subscriptionPlan?->name ?? 'another plan';

            return [null, "This coupon only applies to {$planName}."];
        }

        return [$coupon, null];
    }

    private function businessHasActiveSubscription(Request $request): bool
    {
        return Subscription::query()
            ->where('business_id', $this->businessId($request))
            ->active()
            ->exists();
    }

    private function businessId(Request $request): ?int
    {
        return $this->user($request)->business_id;
    }

    private function notifyCouponExhausted(Coupon $coupon): void
    {
        $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->queue(new CouponExhaustedMail($coupon));
        } catch (Throwable $e) {
            Log::error('coupon.exhausted_email_failed', ['coupon_code' => $coupon->code, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Percentage coupons store "20.00" meaning 20%; converting to basis
     * points (2000) keeps the discount arithmetic integer-only.
     */
    private function discountKobo(Coupon $coupon, int $amountKobo): int
    {
        if ($coupon->discount_type === 'percentage') {
            return intdiv($amountKobo * $this->toKobo($coupon->discount_value), 10000);
        }

        return min($this->toKobo($coupon->discount_value), $amountKobo);
    }

    /**
     * Naira decimal string -> integer kobo, without floating point.
     */
    private function toKobo(string|int|float|null $amount): int
    {
        $value = trim((string) $amount);

        if ($value === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $kobo = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$kobo : $kobo;
    }

    /**
     * Integer kobo -> the naira decimal string the payments table stores.
     */
    private function toNaira(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);

        return sprintf('%s%d.%02d', $sign, intdiv($kobo, 100), $kobo % 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function planPayload(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'name' => $plan->name,
            'description' => $plan->description,
            'amount' => (string) $plan->amount,
            'amount_kobo' => $this->toKobo($plan->amount),
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'interval_count' => (int) $plan->interval_count,
            'features' => $plan->features ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function couponPayload(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (string) $coupon->discount_value,
            'discount_label' => $coupon->discount_label,
            'plan_name' => $coupon->subscriptionPlan?->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function expiredSubscriptionPayload(Request $request): ?array
    {
        $subscription = Subscription::query()
            ->where('business_id', $this->businessId($request))
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->latest('expires_at')
            ->first();

        if (! $subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan_name' => $subscription->subscriptionPlan?->name,
            'expired_at' => $subscription->expires_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(?Subscription $subscription): ?array
    {
        if (! $subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'subscription_code' => $subscription->subscription_code,
            'status' => $subscription->status,
            'starts_at' => $subscription->starts_at?->toISOString(),
            'expires_at' => $subscription->expires_at?->toISOString(),
            'plan_name' => $subscription->subscriptionPlan?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Payment $payment): array
    {
        $displayStatus = match ($payment->status) {
            Payment::STATUS_SUCCESS => 'paid',
            Payment::STATUS_FAILED => 'failed',
            Payment::STATUS_ABANDONED => 'cancelled',
            default => 'pending',
        };

        return [
            'id' => $payment->id,
            'payment_code' => $payment->payment_code,
            'reference' => $payment->reference,
            'reference_truncated' => Str::length($payment->reference) > 24
                ? Str::substr($payment->reference, 0, 12).'…'.Str::substr($payment->reference, -8)
                : $payment->reference,
            'amount' => (string) $payment->amount,
            'amount_kobo' => $this->toKobo($payment->amount),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'display_status' => $displayStatus,
            'payment_type' => $payment->payment_type,
            'plan_name' => data_get($payment->metadata, 'plan_name'),
            'coupon_code' => data_get($payment->metadata, 'coupon_code'),
            'failure_reason' => $payment->failure_reason,
            'paid_at' => $payment->paid_at?->toISOString(),
            'created_at' => $payment->created_at?->toISOString(),
            'subscription' => $this->subscriptionPayload($payment->subscription),
        ];
    }
}
