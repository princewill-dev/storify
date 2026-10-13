<?php

use App\Models\Business;
use App\Models\Impersonation;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;

/*
| WS-40 — the step that used to stand between a business and its dashboard.
|
| This suite was written for a forced `payout_account` screen: a business that
| had not named a bank account was redirected to it on every sign-in and could
| not dismiss it. That screen is gone, and what is left of the suite is the
| proof that it is — every state that used to produce `payout_account` now
| produces `dashboard`, which is the whole point of the change.
|
| The states are kept as separate tests rather than collapsed into one, because
| they were separate reasons and each has to stop producing the step on its own:
| no bank named, a bank named with no account at the processor, a bank named with
| an account at a *different* processor, and an account with no bank named.
|
| `nextStep()` is still the single authority the management SPA obeys, so what
| the sign-in answer is matters even when the answer is the ordinary one. The
| branches that survive unchanged — staff, a lapsed subscription, a platform
| whose processor this build cannot run — are kept here for the same reason: the
| change was to one branch, and these are the assertions that say so.
*/

function ps40Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function ps40Switch(?string $code): void
{
    ps40Settings(['system_payment_processor' => $code]);
}

function ps40PlatformBachs(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function ps40Token(User $user, array $abilities = ['management']): string
{
    return $user->createToken('management-access', $abilities, now()->addHour())->plainTextToken;
}

function ps40Next(User $user, ?string $token = null): string
{
    $response = test()->withToken($token ?? ps40Token($user))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk();

    return (string) $response->json('data.next');
}

/**
 * The bank account the business named, without any account at a provider.
 */
function ps40PayoutBank(Business $business): StoreBank
{
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $business->forceFill(['payout_bank_id' => $bank->id])->save();

    return $bank;
}

/**
 * A business Storify collects for — the state that used to end the step.
 *
 * Written on the business, and no payment-method row is created. That is the
 * change stated as a fixture: the fact used to live on a row as an account at
 * the provider, and there is no longer any such thing for a row to hold.
 */
function ps40Managed(Business $business): void
{
    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();
}

function ps40Store(User $owner): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ps40-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('a business that has named no bank account is not stopped at sign-in', function () {
    // The gate this change removes. A business with no bank account named can
    // take a customer's money — Storify holds it and hands it over the moment
    // one is named — so sending it to a screen it cannot dismiss was stopping it
    // trading over paperwork that can arrive tomorrow.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect($business->payout_bank_id)->toBeNull()
        ->and(ps40Next($owner))->toBe('dashboard');
});

test('naming the bank account and having an account at the processor changes nothing at sign-in', function () {
    // The ordinary registered business. It reaches the dashboard, and so does
    // the one in the test above — which is the point: where a business is sent
    // no longer depends on whether its payouts are set up.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40PayoutBank($business);
    ps40Managed($business);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('a business that can sell but never named a bank account is not asked at all', function () {
    // An account at the processor that works, and no `store_banks` row behind
    // it — a business whose payouts were set up before the pointer existed, or
    // one that simply never named an account. It can sell today, and it is not
    // intercepted; what it is owed waits in the wallet until it names one.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40Managed($business);

    expect($business->payout_bank_id)->toBeNull()
        ->and(ps40Next($owner))->toBe('dashboard');
});

test('switching the processor does not re-prompt anyone', function () {
    // The state the old step existed for: a business registered on Bachs, with
    // the admin having since switched the platform to Paystack, holds no account
    // at the processor now in force. Under the old model that was a business
    // that could not take a payment, and the form was how it found out. It is
    // still a business that cannot take a payment *through Paystack* — the
    // resolver still offers nothing, and the storefront still says nothing is
    // available — but it is not locked out of its own dashboard over it.
    config([
        'services.paystack.secret_key' => 'sk_live_platform1234567890',
        'services.paystack.public_key' => 'pk_live_platform1234567890',
    ]);
    ps40PlatformBachs();
    ps40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40PayoutBank($business);
    ps40Managed($business);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('with the switch off nobody is sent anywhere unusual', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('a stored processor this build cannot run is not a business\'s problem', function () {
    // Enforced in the database, unusable in this build. Nothing can be charged
    // through anything — an admin's to fix, not a business's.
    ps40Switch('stripe');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('staff are never asked, whatever their business is missing', function () {
    ps40PlatformBachs();
    ps40Switch('bachs');

    [, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    expect(ps40Next($staff))->toBe('dashboard');
});

test('a member without the payment permission is not held anywhere', function () {
    // A paid-up business with no payout bank — the state the old step
    // intercepted. An owner without `settings payment` used to be exempted from
    // the form because they could not submit it, which was a favour; now nobody
    // is asked, and this is the assertion that says the exemption did not become
    // a trap when the branch went away.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $till = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
        // A paid-up business, so `plans` cannot be what answers this.
        'trial_ends_at' => now()->addWeek(),
    ]);

    setPermissionsTeamId($business->id);

    // Not staff, so it is the owner branch that answers.
    $till->forceFill(['role' => User::ROLE_BUSINESS_OWNER])->save();

    expect($till->fresh()->can('settings payment'))->toBeFalse()
        ->and(ps40Next($till->fresh()))->toBe('dashboard');
});

test('an impersonated session reaches the dashboard, and so does the owner', function () {
    // An admin debugging a business is not its owner, which is why the old step
    // exempted it. Both now answer the same way, and the interesting half is the
    // second assertion: the owner is not asked either.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    [$admin] = createBusinessOwner();

    $impersonation = Impersonation::create([
        'impersonator_id' => $admin->id,
        'impersonated_id' => $owner->id,
        'started_at' => now(),
    ]);

    $token = ps40Token($owner, ['management', Impersonation::ABILITY_PREFIX.$impersonation->id]);

    expect(ps40Next($owner, $token))->toBe('dashboard')
        // The owner's own session is not exempted by the impersonation and still
        // reaches the dashboard — the business has named no bank account.
        ->and(ps40Next($owner))->toBe('dashboard');
});

test('a lapsed subscription is paid for before the business is asked anything', function () {
    // `plans` first, and that ordering is a fact about money rather than
    // politeness: subscription billing runs on the platform's own provider
    // credentials, so a business that cannot take payments can still pay Storify.
    // Asking it where its money should go first would leave it unable to do
    // either.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDay()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('plans');
});
