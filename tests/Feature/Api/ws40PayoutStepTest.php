<?php

use App\Models\Business;
use App\Models\Impersonation;
use App\Models\KycApplication;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;

/*
| WS-40 — the payout destination and where a sign-in is steered.
|
| This suite was first written for a forced `payout_account` screen, then
| rewritten when that screen was removed. WS-63 brought the requirement back as
| part of `payment_setup`, alongside KYC: `nextStep()` now sends an owner to the
| setup wizard until the business has named a payout destination.
|
| The states are kept separate because they are separate reasons a business can
| be incomplete: no bank named, a bank named with no account at the processor, a
| bank named with an account at a *different* processor, and an account with no
| bank named. The processor cases now all answer `dashboard` when a destination
| exists — the processor is not what the gate is about — and `payment_setup`
| when it does not.
|
| The branches that do not depend on the destination — staff, a lapsed
| subscription, an impersonated admin, a platform whose processor this build
| cannot run — are kept here because `nextStep()` is still the single authority
| the management SPA obeys, and these are the assertions that say which branch
| answered.
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

test('a business that has named no payout destination is sent to setup', function () {
    // WS-63's gate. KYC is approved by the fixture, so the destination is the
    // only thing outstanding.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect($business->payout_bank_id)->toBeNull()
        ->and(ps40Next($owner))->toBe('payment_setup');
});

test('naming the bank account lets the business through', function () {
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40PayoutBank($business);
    ps40Managed($business);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('an account at the processor without a payout destination is still stopped', function () {
    // A business whose payouts were set up before `payout_bank_id` existed, or
    // one that never chose a destination. An account at the processor is not
    // the same fact as a destination, and this is the assertion that keeps the
    // two from being confused again.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40Managed($business);

    expect($business->payout_bank_id)->toBeNull()
        ->and(ps40Next($owner))->toBe('payment_setup');
});

test('switching the processor does not re-prompt a business with a destination', function () {
    // The state the old step existed for: a business registered on Bachs, with
    // the admin having since switched the platform to Paystack, holds no account
    // at the processor now in force. It still cannot take a payment *through
    // Paystack* — the resolver offers nothing — but its destination is named,
    // so sign-in is the ordinary one.
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

test('with the switch off the destination is still what is asked for', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('payment_setup');
});

test('a stored processor this build cannot run does not change the setup gate', function () {
    // Enforced in the database, unusable in this build. Nothing can be charged
    // through anything — an admin's to fix, not a business's — and the setup
    // gate is about the business's own paperwork rather than the processor.
    ps40Switch('stripe');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('payment_setup');
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

test('a member without the payment permission still sees the gate, which their business has satisfied', function () {
    // The role is artificial — an owner without `settings payment` — but the
    // point is the shape of the question: the gate asks about the *business's*
    // destination, not about the viewer's ability to edit it. A complete
    // business is not held anywhere, whoever is looking.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40PayoutBank($business);

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

    // KYC is the owner's own identity, so the member needs one of their own —
    // the business's destination does not stand in for it.
    KycApplication::query()->create([
        'user_id' => $till->id,
        'business_id' => $business->id,
        'status' => KycApplication::STATUS_APPROVED,
        'legal_name' => 'Till Owner',
        'first_name' => 'Till',
        'last_name' => 'Owner',
        'phone_number' => '08030000000',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'address_line' => '1 Test Street',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'kyc_document_id' => 'NIN-987654321',
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
    ]);

    expect($till->fresh()->can('settings payment'))->toBeFalse()
        ->and(ps40Next($till->fresh()))->toBe('dashboard');
});

test('an impersonated session reaches the dashboard while the owner is still held', function () {
    // An admin debugging a business is not its owner: the setup checklist is
    // the owner's own obligation, so an impersonating admin is never held at
    // the wizard. The owner's own session is not exempted by that, and is.
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
        // The business has named no payout destination, so the owner's own
        // session is sent to setup.
        ->and(ps40Next($owner))->toBe('payment_setup');
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
