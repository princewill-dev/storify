<?php

use App\Mail\AdminStoreCreated;
use App\Mail\CouponExhaustedMail;
use App\Mail\StoreActivated;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerSetupService;
use App\Services\Subscription\PlatformBillingGateway;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

function ws08Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws08Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Starter Monthly',
        'description' => 'Everything you need to start selling.',
        'amount' => 5000.00,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => false,
        'is_trial' => false,
        'features' => ['Sell online', 'Unlimited products'],
    ], $attributes));
}

function ws08Store(User $owner, string $status = Store::STATUS_PENDING): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'WS08 Store',
        'slug' => 'ws08-'.Str::random(8),
        'status' => $status,
    ]);
}

/**
 * Fake the three Paystack calls the money path makes: initialize, verify and
 * the transaction list used for the second confirmation.
 */
function ws08FakeGateway(
    string $reference,
    int $amountKobo = 500000,
    string $currency = 'NGN',
    string $status = 'success',
    bool $initializeSucceeds = true,
): void {
    // Http::fake() merges stub callbacks and the earliest matching stub always
    // wins, so calling this a second time (e.g. once the payment's real
    // reference exists) would be shadowed by the first registration. Swap in a
    // fresh factory so each call replaces the previous stub set.
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($reference, $amountKobo, $currency, $status, $initializeSucceeds) {
        $url = $request->url();

        if (str_contains($url, 'transaction/initialize')) {
            if (! $initializeSucceeds) {
                return Http::response(['status' => false, 'message' => 'Invalid key'], 401);
            }

            return Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/ws08',
                    'access_code' => 'ws08_access',
                    // Paystack echoes the reference the *request* carried, which
                    // is not this helper's `$reference` when the fake is
                    // registered before the payment exists. Echoing the request
                    // is what keeps the fake honest: the money path records a
                    // provider's name for a payment only when the provider
                    // really did rename it, so a placeholder echoed back here
                    // would be read as Paystack having named the charge itself.
                    'reference' => $request->data()['reference'] ?? $reference,
                ],
            ]);
        }

        if (str_contains($url, 'transaction/verify')) {
            return Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'id' => 998877,
                    'status' => $status,
                    'amount' => $amountKobo,
                    'currency' => $currency,
                    'reference' => $reference,
                ],
            ]);
        }

        return Http::response([
            'status' => true,
            'data' => [[
                'id' => 998877,
                'status' => $status,
                'amount' => $amountKobo,
                'currency' => $currency,
                'reference' => $reference,
            ]],
        ]);
    });
}

test('the checkout summary shows the selected plan and a coupon-adjusted total', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addDays(3)]);
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);

    Coupon::create([
        'code' => 'SAVE20',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'is_active' => true,
    ]);

    $response = $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payment?coupon_code=SAVE20');

    $response->assertOk()
        ->assertJsonPath('data.plan.name', 'Starter Monthly')
        ->assertJsonPath('data.base_amount_kobo', 500000)
        ->assertJsonPath('data.discount_kobo', 100000)
        ->assertJsonPath('data.total_kobo', 400000)
        ->assertJsonPath('data.coupon.code', 'SAVE20')
        ->assertJsonPath('data.trial.on_trial', true);

    expect(Str::isUuid($response->json('data.idempotency_key')))->toBeTrue()
        ->and($response->json('data.trial.days_left'))->toBeGreaterThan(0)
        ->and($response->json('data.trial.days_left'))->toBeLessThanOrEqual(3);
});

test('the checkout summary refuses a business with an active subscription', function () {
    [$owner, $business] = createBusinessOwner();
    $plan = ws08Plan();

    Subscription::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subWeek(),
        'expires_at' => now()->addWeeks(3),
    ]);

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payment')
        ->assertStatus(409)
        ->assertJsonPath('message', 'You already have an active subscription.');
});

test('the checkout summary asks for a plan when none is selected and there is no default', function () {
    [$owner] = createBusinessOwner();
    ws08Plan();

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payment')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please select a plan first.');
});

test('the checkout summary reports an unavailable selected plan', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan(['is_active' => false]);
    $owner->update(['selected_plan_id' => $plan->id]);

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payment')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Selected plan is no longer available.');
});

test('the checkout summary rejects a coupon from another plan or business', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $otherPlan = ws08Plan(['name' => 'Pro Monthly', 'amount' => 9000.00]);
    $owner->update(['selected_plan_id' => $plan->id]);

    Coupon::create([
        'code' => 'PROONLY',
        'subscription_plan_id' => $otherPlan->id,
        'discount_type' => 'fixed',
        'discount_value' => 1000,
        'is_active' => true,
    ]);

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payment?coupon_code=PROONLY')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This coupon only applies to Pro Monthly.');

    // A coupon scoped to another business must not leak across tenants.
    // `business_id` is not mass-assignable on Coupon, so it is set explicitly.
    [, $otherBusiness] = createBusinessOwner();
    $theirsCoupon = Coupon::create([
        'code' => 'THEIRS',
        'discount_type' => 'percentage',
        'discount_value' => 50,
        'is_active' => true,
    ]);
    $theirsCoupon->forceFill(['business_id' => $otherBusiness->id])->save();

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payment?coupon_code=THEIRS')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid or expired coupon code.');
});

test('processing a payment creates the pending triple and redirects to paystack', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);

    ws08FakeGateway('ref_ws08_initialize');

    $response = $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0001']);

    $response->assertOk()
        ->assertJsonPath('data.redirect_url', 'https://checkout.paystack.com/ws08')
        ->assertJsonPath('data.total_kobo', 500000);

    $payment = Payment::latest('id')->first();

    expect($payment->status)->toBe(Payment::STATUS_PENDING)
        ->and($payment->payment_type)->toBe(Payment::TYPE_SUBSCRIPTION)
        ->and((string) $payment->amount)->toBe('5000.00')
        ->and($payment->idempotency_key)->toBe('key-0001')
        ->and(data_get($payment->metadata, 'amount_kobo'))->toBe(500000);

    expect(Subscription::find($payment->subscription_id)->status)->toBe(Subscription::STATUS_PENDING);

    $this->assertDatabaseHas('transactions', [
        'reference' => $payment->reference,
        'status' => 'pending',
    ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'transaction/initialize')
        && $request['amount'] === 500000
        && $request['currency'] === 'NGN');
});

test('the same idempotency key re-redirects to the stored authorization url without a second charge', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);

    ws08FakeGateway('ref_ws08_idempotent');

    $token = ws08Token($owner);
    $first = $this->withToken($token)->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0002']);
    $second = $this->withToken($token)->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0002']);

    $first->assertOk();
    $second->assertOk()->assertJsonPath('data.already_initialized', true);

    expect($first->json('data.redirect_url'))->toBe($second->json('data.redirect_url'))
        ->and(Payment::where('idempotency_key', 'key-0002')->count())->toBe(1)
        ->and(Subscription::count())->toBe(1);

    Http::assertSentCount(1);
});

test('a coupon reduces the amount sent to paystack', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);

    Coupon::create([
        'code' => 'SAVE20',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'is_active' => true,
    ]);

    ws08FakeGateway('ref_ws08_coupon');

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'key-0003',
            'coupon_code' => 'save20',
        ])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    expect((string) $payment->amount)->toBe('4000.00')
        ->and(data_get($payment->metadata, 'discount_kobo'))->toBe(100000);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'transaction/initialize')
        && $request['amount'] === 400000);
});

test('a coupon that does not apply is refused at checkout instead of being silently dropped', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $otherPlan = ws08Plan(['name' => 'Pro Monthly', 'amount' => 9000.00]);
    $owner->update(['selected_plan_id' => $plan->id]);

    Coupon::create([
        'code' => 'PROONLY',
        'subscription_plan_id' => $otherPlan->id,
        'discount_type' => 'fixed',
        'discount_value' => 1000,
        'is_active' => true,
    ]);

    ws08FakeGateway('ref_ws08_nocoupon');

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'key-0004',
            'coupon_code' => 'PROONLY',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This coupon only applies to Pro Monthly.');

    expect(Payment::count())->toBe(0)
        ->and(Subscription::count())->toBe(0);

    Http::assertNothingSent();
});

test('a fully covering coupon activates the subscription without touching the gateway', function () {
    Mail::fake();
    Http::fake();
    config(['mail.admin_email' => 'admin@storify.test']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addDays(2), 'status' => 'pending']);
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);
    $store = ws08Store($owner);

    Coupon::create([
        'code' => 'FREE100',
        'discount_type' => 'percentage',
        'discount_value' => 100,
        'max_uses' => 1,
        'uses_count' => 0,
        'is_active' => true,
    ]);

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'key-0005',
            'coupon_code' => 'FREE100',
        ])
        ->assertOk()
        ->assertJsonPath('data.activated', true)
        ->assertJsonPath('message', 'Starter Monthly activated successfully.');

    $subscription = Subscription::latest('id')->first();

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->expires_at->isFuture())->toBeTrue()
        ->and(data_get($subscription->metadata, 'coupon_code'))->toBe('FREE100');

    expect($owner->fresh()->trial_ends_at)->toBeNull()
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE)
        ->and(Coupon::where('code', 'FREE100')->first()->uses_count)->toBe(1)
        ->and(Coupon::where('code', 'FREE100')->first()->is_active)->toBeFalse()
        ->and($business->fresh()->hasActiveSubscription())->toBeTrue();

    Mail::assertQueued(StoreActivated::class);
    Mail::assertQueued(CouponExhaustedMail::class);

    Http::assertNothingSent();
    expect(Payment::count())->toBe(0);
});

test('a gateway initialization failure marks the attempt failed', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);

    ws08FakeGateway('ref_ws08_failed', initializeSucceeds: false);

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0006'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Failed to initialize payment. Please try again.');

    $payment = Payment::latest('id')->first();

    expect($payment->status)->toBe(Payment::STATUS_FAILED)
        ->and(Subscription::find($payment->subscription_id)->status)->toBe(Subscription::STATUS_CANCELLED);

    $this->assertDatabaseHas('transactions', [
        'reference' => $payment->reference,
        'status' => 'cancelled',
    ]);
});

test('the callback double-verifies and activates the subscription end to end', function () {
    Mail::fake();
    app(LedgerSetupService::class)->ensureForBusiness(null);

    User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addDays(2), 'status' => 'pending']);
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);
    $store = ws08Store($owner);

    ws08FakeGateway('ref_ws08_callback');

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0007'])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    // Re-fake with the real reference now that the payment exists.
    ws08FakeGateway($payment->reference, 500000);

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertOk()
        ->assertJsonPath('data.already_processed', false)
        ->assertJsonPath('message', 'Subscription payment successful.')
        ->assertJsonPath('data.payment.display_status', 'paid');

    $payment->refresh();
    $subscription = $payment->subscription->fresh();

    expect($payment->status)->toBe(Payment::STATUS_SUCCESS)
        ->and($payment->paid_at)->not->toBeNull()
        ->and((string) $payment->gateway_reference)->toBe('998877')
        ->and($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->starts_at)->not->toBeNull()
        ->and($subscription->expires_at->isBetween(now()->addMonth()->subMinutes(5), now()->addMonth()->addMinutes(5)))->toBeTrue();

    $this->assertDatabaseHas('transactions', [
        'reference' => $payment->reference,
        'status' => 'confirmed',
    ]);

    expect($owner->fresh()->trial_ends_at)->toBeNull()
        ->and($owner->fresh()->status)->toBe('active')
        ->and($store->fresh()->status)->toBe(Store::STATUS_ACTIVE)
        ->and($business->fresh()->hasActiveSubscription())->toBeTrue();

    Mail::assertQueued(StoreActivated::class);
    Mail::assertQueued(AdminStoreCreated::class);

    $this->assertDatabaseHas('journal_entries', [
        'business_id' => null,
        'idempotency_key' => 'subscription_payment:'.$payment->id,
    ]);
});

test('the callback is idempotent on a second visit', function () {
    Mail::fake();

    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);
    ws08Store($owner);

    ws08FakeGateway('ref_ws08_twice');

    $token = ws08Token($owner);
    $this->withToken($token)->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0008'])->assertOk();

    $payment = Payment::latest('id')->first();
    ws08FakeGateway($payment->reference);

    $this->withToken($token)->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)->assertOk();

    Mail::fake();

    $this->withToken($token)
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertOk()
        ->assertJsonPath('data.already_processed', true)
        ->assertJsonPath('message', 'Payment already processed successfully.');

    expect(Subscription::where('status', Subscription::STATUS_ACTIVE)->count())->toBe(1);

    Mail::assertNothingQueued();
});

test('the callback marks the payment failed on a gateway amount mismatch', function () {
    Mail::fake();

    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);
    $store = ws08Store($owner);

    ws08FakeGateway('ref_ws08_mismatch');

    $token = ws08Token($owner);
    $this->withToken($token)->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0009'])->assertOk();

    $payment = Payment::latest('id')->first();

    // The gateway confirms "success" but for a smaller amount than charged.
    ws08FakeGateway($payment->reference, 100);

    $this->withToken($token)
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Payment verification failed.');

    $payment->refresh();

    expect($payment->status)->toBe(Payment::STATUS_FAILED)
        ->and($payment->failure_reason)->toBe('Gateway verification mismatch.')
        ->and($payment->subscription->fresh()->status)->toBe(Subscription::STATUS_PENDING)
        ->and($store->fresh()->status)->toBe(Store::STATUS_PENDING)
        ->and($owner->business->fresh()->hasActiveSubscription())->toBeFalse();

    $this->assertDatabaseHas('transactions', [
        'reference' => $payment->reference,
        'status' => 'cancelled',
    ]);

    Mail::assertNothingQueued();
});

test('the callback refuses another business reference', function () {
    [$owner] = createBusinessOwner();
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);

    ws08FakeGateway('ref_ws08_tenant');

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0010'])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    [$intruder] = createBusinessOwner();

    // Every request in a test shares one application instance, and the sanctum
    // RequestGuard caches the first user it resolves — without forgetting the
    // guards here the intruder's request would still be authenticated as the
    // owner above (and would reach the gateway instead of the 404).
    $this->app['auth']->forgetGuards();

    $this->withToken(ws08Token($intruder))
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertStatus(404)
        ->assertJsonPath('message', 'Payment record not found.');

    expect($payment->fresh()->status)->toBe(Payment::STATUS_PENDING);
});

test('a lapsed subscription can be renewed against its previous plan', function () {
    [$owner, $business] = createBusinessOwner();
    $plan = ws08Plan(['amount' => 5000.00]);
    $defaultPlan = ws08Plan(['name' => 'Default Annual', 'amount' => 50000.00, 'interval' => 'yearly', 'is_default' => true]);

    Subscription::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subMonth()->subDay(),
        'expires_at' => now()->subDay(),
    ]);

    ws08FakeGateway('ref_ws08_renewal');

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payment?payment_type=renewal')
        ->assertOk()
        ->assertJsonPath('data.plan.name', 'Starter Monthly')
        ->assertJsonPath('data.payment_type', 'renewal')
        ->assertJsonPath('data.expired_subscription.plan_name', 'Starter Monthly');

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'key-0011',
            'payment_type' => 'renewal',
        ])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    expect($payment->payment_type)->toBe(Payment::TYPE_RENEWAL)
        ->and(data_get($payment->metadata, 'plan_id'))->toBe($plan->id)
        ->and(data_get($payment->metadata, 'plan_id'))->not->toBe($defaultPlan->id);
});

test('the billing history lists this business only', function () {
    [$owner, $business] = createBusinessOwner();
    $plan = ws08Plan();

    Payment::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'reference' => 'ref_ws08_history_old',
        'amount' => 5000,
        'currency' => 'NGN',
        'status' => Payment::STATUS_SUCCESS,
        'payment_type' => Payment::TYPE_SUBSCRIPTION,
        'paid_at' => now()->subMonth(),
        'metadata' => ['plan_name' => 'Starter Monthly'],
    ]);

    Payment::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'reference' => 'ref_ws08_history_new',
        'amount' => 4000,
        'currency' => 'NGN',
        'status' => Payment::STATUS_FAILED,
        'payment_type' => Payment::TYPE_RENEWAL,
        'failure_reason' => 'Gateway verification mismatch.',
        'metadata' => ['plan_name' => 'Starter Monthly'],
    ]);

    // A non-subscription payment on the same business must not appear in the
    // billing-history table.
    Payment::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'reference' => 'ref_ws08_history_pos',
        'amount' => 1500,
        'currency' => 'NGN',
        'status' => Payment::STATUS_SUCCESS,
        'payment_type' => Payment::TYPE_OTHER,
    ]);

    [$otherOwner, $otherBusiness] = createBusinessOwner();
    Payment::create([
        'business_id' => $otherBusiness->id,
        'user_id' => $otherOwner->id,
        'reference' => 'ref_ws08_history_other',
        'amount' => 9000,
        'currency' => 'NGN',
        'status' => Payment::STATUS_SUCCESS,
        'payment_type' => Payment::TYPE_SUBSCRIPTION,
    ]);

    $response = $this->withToken(ws08Token($owner))->getJson('/api/v1/management/subscription/payments');

    $response->assertOk();

    $references = array_column($response->json('data'), 'reference');

    expect($references)->toBe(['ref_ws08_history_new', 'ref_ws08_history_old'])
        ->and($response->json('data.0.display_status'))->toBe('failed')
        ->and($response->json('data.0.failure_reason'))->toBe('Gateway verification mismatch.')
        ->and($response->json('meta.total'))->toBe(2);

    // Failed-only filter.
    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/payments?status=failed')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('the billing endpoints require the subscription permission and a management token', function () {
    [$owner, $business] = createBusinessOwner();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws08Token($staff))
        ->getJson('/api/v1/management/subscription/payment')
        ->assertStatus(403);

    $this->withToken(ws08Token($staff))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'key-0012'])
        ->assertStatus(403);

    // withToken() persists as a default header and the sanctum guard caches the
    // first user resolved on this shared application instance; drop both so the
    // final request is genuinely unauthenticated rather than merely un-permitted.
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/management/subscription/payments')->assertStatus(401);
});

test('process payment validates its input', function () {
    [$owner] = createBusinessOwner();

    $token = ws08Token($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/process-payment', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('idempotency_key');

    $this->withToken($token)
        ->postJson('/api/v1/management/subscription/process-payment', [
            'idempotency_key' => 'key-0013',
            'payment_type' => 'refund',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_type');

    $this->withToken($token)
        ->getJson('/api/v1/management/subscription/callback')
        ->assertStatus(422)
        ->assertJsonValidationErrors('reference');
});

/*
|--------------------------------------------------------------------------
| Platform billing on Bachs, and the platform's own webhook
|--------------------------------------------------------------------------
|
| Storify's subscriptions are the platform's own charges, so they run on
| credentials the platform holds rather than on a business's connection. These
| tests cover the second gateway that can take them and the delivery that
| settles one without the customer's browser.
*/

/** The platform's Bachs account, as `config/services.php` would hold it. */
function ws08BachsConfig(): void
{
    config(['services.bachs' => [
        'api_key' => 'sk_sandbox_ws08abcdefgh',
        'webhook_secret' => 'whsec_ws08abcdefgh',
    ]]);
}

/**
 * Fake the two Bachs calls this money path makes: create the checkout session,
 * then read it back to see what the customer paid.
 *
 * The amount is a decimal string on purpose — Bachs quotes money in the
 * currency's major unit, and a fake that answered in kobo would let a units bug
 * through every test that used it.
 */
function ws08FakeBachs(string $chargeStatus = 'succeeded', string $amount = '5000.00'): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($chargeStatus, $amount) {
        if ($request->method() === 'POST') {
            return Http::response([
                'checkout_id' => 'chk_ws08',
                'checkout_url' => 'https://checkout.bachs.io/chk_ws08',
                'status' => 'open',
            ]);
        }

        return Http::response([
            'checkout_id' => 'chk_ws08',
            'status' => 'completed',
            'charge' => [
                'id' => 'chg_ws08',
                'status' => $chargeStatus,
                'amount' => $amount,
                'currency' => 'NGN',
            ],
        ]);
    });
}

/**
 * A signed Bachs delivery: the raw body plus the `X-Bachs-Signature-V2` header
 * it is HMAC'd into. Returns both because the controller hashes the bytes it
 * received, not a re-encoding of the array.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function ws08BachsWebhook(array $payload, string $secret = 'whsec_ws08abcdefgh'): array
{
    $body = json_encode($payload);
    $timestamp = time();

    return [$body, [
        'HTTP_X_BACHS_SIGNATURE_V2' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ]];
}

/** A `checkout.completed` for the session the fake above opens. */
function ws08BachsCompleted(string $paymentStatus = 'paid'): array
{
    return [
        'type' => 'checkout.completed',
        'data' => [
            'checkout_id' => 'chk_ws08',
            'payment_status' => $paymentStatus,
            'charge_id' => 'chg_ws08',
            'reference' => 'chk_ws08',
        ],
    ];
}

/**
 * A business set up to be billed through Bachs, with a plan chosen.
 *
 * The test case is passed in rather than reached for: a plain function has no
 * `$this`, and the seeder is a method on the case.
 *
 * @return array{0: User, 1: Business}
 */
function ws08BachsBilling(TestCase $test): array
{
    // The payment-methods row is what the admin switch reads. Bachs is not
    // seeded by a migration (Paystack is, for history reasons), so it only
    // exists once the seeder has run.
    $test->seed(PaymentMethodSeeder::class);
    ws08BachsConfig();
    Setting::create(['billing_gateway' => 'bachs']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addDays(2), 'status' => 'pending']);
    $plan = ws08Plan();
    $owner->update(['selected_plan_id' => $plan->id]);
    ws08Store($owner);

    return [$owner, $business];
}

test('bachs can bill a platform subscription end to end', function () {
    Mail::fake();
    app(LedgerSetupService::class)->ensureForBusiness(null);

    [$owner] = ws08BachsBilling($this);

    ws08FakeBachs();

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-key-1'])
        ->assertOk()
        ->assertJsonPath('data.redirect_url', 'https://checkout.bachs.io/chk_ws08')
        // The customer is told who is charging them, and it is read from the
        // engine rather than written into the controller.
        ->assertJsonPath('message', 'Payment initialized. Redirecting to Bachs.');

    $payment = Payment::latest('id')->first();

    expect($payment->payment_gateway)->toBe('bachs')
        // The name Bachs knows this payment by, kept because its deliveries and
        // its verify endpoint are both addressed by it and not by our reference.
        ->and(data_get($payment->metadata, 'provider_reference'))->toBe('chk_ws08')
        // The normalization the idempotent replay depends on: Paystack's key
        // name, holding Bachs' URL.
        ->and(data_get($payment->gateway_response, 'authorization_url'))->toBe('https://checkout.bachs.io/chk_ws08')
        ->and((string) Transaction::where('reference', $payment->reference)->value('payment_method_id'))
        ->toBe((string) PaymentMethod::where('code', 'bachs')->value('id'));

    $this->withToken(ws08Token($owner))
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertOk()
        ->assertJsonPath('data.already_processed', false);

    $payment->refresh();

    expect($payment->status)->toBe(Payment::STATUS_SUCCESS)
        ->and($payment->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

test('the same idempotency key re-redirects to the stored bachs checkout', function () {
    Mail::fake();

    [$owner] = ws08BachsBilling($this);

    ws08FakeBachs();

    $first = $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-replay-1'])
        ->assertOk();

    $second = $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-replay-1'])
        ->assertOk()
        ->assertJsonPath('data.already_initialized', true);

    expect($second->json('data.redirect_url'))->toBe($first->json('data.redirect_url'))
        ->and(Payment::query()->count())->toBe(1);
});

test('a billing gateway switched off refuses the checkout instead of borrowing the other one', function () {
    Mail::fake();

    [$owner] = ws08BachsBilling($this);

    PaymentMethod::where('code', 'bachs')->update(['is_active' => false]);

    Http::swap(new Factory);
    Http::fake(fn () => Http::response(['status' => true, 'data' => []]));

    $response = $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-off-1'])
        ->assertStatus(503);

    // Says which gateway and why, rather than "try again": an admin switched it
    // off, and trying again will not change that.
    expect($response->json('message'))->toContain('Bachs is switched off');

    // Nothing was charged anywhere. Paystack is configured and would have
    // answered, so a fallback would have shown up as a request here.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'checkout-sessions')
        || str_contains($request->url(), 'paystack'));

    expect(Payment::latest('id')->first()->status)->toBe(Payment::STATUS_FAILED);
});

test('a billing gateway with no server credentials refuses the checkout', function () {
    Mail::fake();

    [$owner] = ws08BachsBilling($this);

    config(['services.bachs' => ['api_key' => null, 'webhook_secret' => null]]);

    Http::swap(new Factory);
    Http::fake(fn () => Http::response(['status' => true, 'data' => []]));

    $response = $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-nokey-1'])
        ->assertStatus(503);

    // Names the missing field, never a value.
    expect($response->json('message'))->toContain('api_key');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'checkout-sessions'));
});

test('a signed bachs delivery activates the subscription without the browser coming back', function () {
    Mail::fake();
    app(LedgerSetupService::class)->ensureForBusiness(null);

    [$owner] = ws08BachsBilling($this);

    ws08FakeBachs();

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-wh-1'])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    [$body, $headers] = ws08BachsWebhook(ws08BachsCompleted());

    $this->call('POST', '/webhooks/subscription/bachs', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $payment->refresh();

    expect($payment->status)->toBe(Payment::STATUS_SUCCESS)
        ->and($payment->gateway_reference)->toBe('chk_ws08')
        ->and($payment->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);

    // A replayed delivery is the ordinary case for a webhook, not an attack.
    $this->call('POST', '/webhooks/subscription/bachs', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJsonPath('status', 'already processed');
});

test('a collection.succeeded delivery settles just as a checkout.completed one does', function () {
    Mail::fake();
    app(LedgerSetupService::class)->ensureForBusiness(null);

    [$owner] = ws08BachsBilling($this);

    ws08FakeBachs();

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-wh-2'])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    [$body, $headers] = ws08BachsWebhook([
        'type' => 'collection.succeeded',
        'data' => ['checkout_id' => 'chk_ws08', 'charge_id' => 'chg_ws08', 'reference' => 'chk_ws08'],
    ]);

    $this->call('POST', '/webhooks/subscription/bachs', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($payment->fresh()->status)->toBe(Payment::STATUS_SUCCESS)
        ->and($payment->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

test('a subscription delivery with a bad signature is refused and activates nothing', function () {
    Mail::fake();

    [$owner] = ws08BachsBilling($this);

    ws08FakeBachs();

    $this->withToken(ws08Token($owner))
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'bachs-wh-3'])
        ->assertOk();

    $payment = Payment::latest('id')->first();

    [$body, $headers] = ws08BachsWebhook(ws08BachsCompleted(), 'whsec_not_ours_at_all');

    $this->call('POST', '/webhooks/subscription/bachs', [], [], [], $headers, $body)
        ->assertStatus(400)
        ->assertJsonPath('status', 'unverified');

    expect($payment->fresh()->status)->toBe(Payment::STATUS_PENDING)
        ->and($payment->subscription->fresh()->status)->toBe(Subscription::STATUS_PENDING);
});

test('a delivery for a gateway the platform does not bill through is refused', function () {
    Mail::fake();
    $this->seed(PaymentMethodSeeder::class);

    config(['services.paystack' => ['secret_key' => 'sk_test_ws08', 'public_key' => 'pk_test_ws08']]);
    config(['services.bachs' => ['api_key' => 'sk_sandbox_ws08abcdefgh', 'webhook_secret' => 'whsec_ws08abcdefgh']]);

    Setting::create(['billing_gateway' => 'paystack']);

    [$body, $headers] = ws08BachsWebhook(ws08BachsCompleted());

    // Bachs is registered, has a driver and is connected here — it is simply not
    // what the platform bills through, so a delivery from it is about payments
    // this platform is not reconciling.
    $this->call('POST', '/webhooks/subscription/bachs', [], [], [], $headers, $body)
        ->assertStatus(404)
        ->assertJsonPath('status', 'unknown provider');

    // Switching the setting is enough — the seam reads the column on every call
    // rather than memoising it, which is what makes the very next request follow
    // the switch instead of the gateway it saw first.
    Setting::query()->first()->update(['billing_gateway' => 'bachs']);

    expect(app(PlatformBillingGateway::class)->code())->toBe('bachs');

    $this->call('POST', '/webhooks/subscription/paystack', [], [], [], [], '{}')
        ->assertStatus(404)
        ->assertJsonPath('status', 'unknown provider');
});

test('a verified delivery this endpoint does not act on is answered and ignored', function () {
    Mail::fake();
    $this->seed(PaymentMethodSeeder::class);
    ws08BachsConfig();
    Setting::create(['billing_gateway' => 'bachs']);

    [$body, $headers] = ws08BachsWebhook([
        'type' => 'collection.underpaid',
        'data' => ['checkout_id' => 'chk_ws08'],
    ]);

    // 200 so the provider stops retrying: understood, verified, and not
    // something this endpoint settles.
    $this->call('POST', '/webhooks/subscription/bachs', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJsonPath('status', 'unhandled event');
});
