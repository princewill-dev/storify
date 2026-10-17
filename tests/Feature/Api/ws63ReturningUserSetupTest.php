<?php

use App\Models\Impersonation;
use App\Models\KycApplication;
use App\Models\Otp;
use App\Models\StoreBank;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| WS-63 — the setup gate for returning users
|--------------------------------------------------------------------------
| Records that predate plan selection, KYC and payout destinations sign in to
| a dashboard they cannot trade from. `nextStep()` therefore gained a
| `payment_setup` branch between `plans` and `welcome`: an owner is held at
| `/settings/payment-settings` until KYC is submitted/approved and the business
| has named a payout destination.
|
| The interesting assertions are the ones that say what does *not* count:
| a `store_banks` row without `businesses.payout_bank_id`, a KYC application
| belonging to a previous business, and business registration — which is shown
| in the checklist but gates nothing.
*/

function ruoToken(User $user, array $abilities = ['management']): string
{
    return $user->createToken('management-access', $abilities, now()->addHour())->plainTextToken;
}

function ruoOtp(string $email, string $type): string
{
    return (string) Otp::where('identifier', $email)->where('type', $type)->latest('id')->value('code');
}

/**
 * The owner's approved application, moved to rejected.
 */
function ruoRejectKyc(User $user): void
{
    $application = KycApplication::query()
        ->where('user_id', $user->id)
        ->orderByDesc('id')
        ->firstOrFail();

    $application->forceFill([
        'status' => KycApplication::STATUS_REJECTED,
        'rejected_at' => now(),
    ])->save();
}

function ruoNext(User $user, ?string $token = null): string
{
    return (string) test()->withToken($token ?? ruoToken($user))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->json('data.next');
}

test('a returning owner with a plan but no kyc is sent to payment setup', function () {
    [$owner] = createBusinessOwner([
        'kyc' => 'none',
        'trial_ends_at' => now()->addWeek(),
    ]);

    $this->withToken(ruoToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'payment_setup')
        ->assertJsonPath('data.user.onboarding.complete', false)
        ->assertJsonPath('data.user.onboarding.steps.plan.state', 'trial')
        ->assertJsonPath('data.user.onboarding.steps.plan.done', true)
        ->assertJsonPath('data.user.onboarding.steps.kyc.state', 'required')
        ->assertJsonPath('data.user.onboarding.steps.kyc.done', false)
        ->assertJsonPath('data.user.onboarding.steps.payouts.state', 'missing')
        ->assertJsonPath('data.user.onboarding.steps.payouts.done', false);
});

test('approved kyc without a payout destination is still payment setup', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ruoToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'payment_setup')
        ->assertJsonPath('data.user.onboarding.steps.kyc.state', 'approved')
        ->assertJsonPath('data.user.onboarding.steps.kyc.done', true)
        ->assertJsonPath('data.user.onboarding.steps.payouts.done', false);
});

test('a bank row that is not the payout destination is not connected', function () {
    // The regression this gate exists to catch. `store_banks` rows predate
    // `businesses.payout_bank_id`, and a row nobody has chosen as the
    // destination is still not a destination.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    StoreBank::factory()->create(['business_id' => $business->id]);

    expect($business->fresh()->payout_bank_id)->toBeNull()
        ->and(ruoNext($owner))->toBe('payment_setup');
});

test('submitted kyc and a payout destination reach the welcome screen once', function () {
    [$owner, $business] = createBusinessOwner(
        ['kyc' => 'submitted', 'trial_ends_at' => now()->addWeek()],
        ['welcome_seen_at' => null],
    );

    connectBusinessPayoutBank($business);

    $this->withToken(ruoToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'welcome')
        ->assertJsonPath('data.user.onboarding.complete', true)
        ->assertJsonPath('data.user.onboarding.steps.kyc.state', 'submitted')
        ->assertJsonPath('data.user.onboarding.steps.kyc.done', true)
        ->assertJsonPath('data.user.onboarding.steps.payouts.state', 'connected');
});

test('approved kyc and a payout destination with the welcome seen reach the dashboard', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    connectBusinessPayoutBank($business);

    $this->withToken(ruoToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard')
        ->assertJsonPath('data.user.onboarding.complete', true);
});

test('a rejected kyc sends the owner back to payment setup even with a payout destination', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    connectBusinessPayoutBank($business);
    ruoRejectKyc($owner);

    $this->withToken(ruoToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'payment_setup')
        ->assertJsonPath('data.user.onboarding.complete', false)
        ->assertJsonPath('data.user.onboarding.steps.kyc.state', 'rejected')
        ->assertJsonPath('data.user.onboarding.steps.kyc.done', false);
});

test('a business with no plan is sent to plans before payment setup', function () {
    [$owner] = createBusinessOwner([
        'kyc' => 'none',
        'trial_ends_at' => null,
    ]);

    $this->withToken(ruoToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'plans')
        ->assertJsonPath('data.user.onboarding.steps.plan.state', 'none')
        ->assertJsonPath('data.user.onboarding.steps.plan.done', false);
});

test('staff never receive the setup gate', function () {
    [, $business] = createBusinessOwner();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    $this->withToken(ruoToken($staff))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard')
        ->assertJsonPath('data.user.onboarding.next', 'dashboard');
});

test('an impersonated session is not held at payment setup', function () {
    [$owner] = createBusinessOwner([
        'kyc' => 'none',
        'trial_ends_at' => now()->addWeek(),
    ]);

    [$admin] = createBusinessOwner();

    $impersonation = Impersonation::create([
        'impersonator_id' => $admin->id,
        'impersonated_id' => $owner->id,
        'started_at' => now(),
    ]);

    $impersonated = ruoToken($owner, [
        'management',
        Impersonation::ABILITY_PREFIX.$impersonation->id,
    ]);

    $this->withToken($impersonated)
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard')
        ->assertJsonPath('data.user.onboarding.next', 'dashboard');

    // The owner's own session is not exempted by the admin's debug session.
    expect(ruoNext($owner))->toBe('payment_setup');
});

test('business registration is reported but gates nothing', function () {
    [$ownerUnanswered, $businessUnanswered] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['is_registered' => null],
    );
    connectBusinessPayoutBank($businessUnanswered);

    $this->withToken(ruoToken($ownerUnanswered))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard')
        ->assertJsonPath('data.user.onboarding.complete', true)
        ->assertJsonPath('data.user.onboarding.steps.business_registration.state', 'unanswered')
        ->assertJsonPath('data.user.onboarding.steps.business_registration.done', false);

    [$ownerNo, $businessNo] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['is_registered' => false],
    );
    connectBusinessPayoutBank($businessNo);

    $this->withToken(ruoToken($ownerNo))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard')
        ->assertJsonPath('data.user.onboarding.steps.business_registration.state', 'not_registered')
        ->assertJsonPath('data.user.onboarding.steps.business_registration.done', true);

    [$ownerYes, $businessYes] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['is_registered' => true],
    );
    connectBusinessPayoutBank($businessYes);

    $this->withToken(ruoToken($ownerYes))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.onboarding.steps.business_registration.state', 'registered')
        ->assertJsonPath('data.user.onboarding.steps.business_registration.done', true);
});

test('login and otp verification carry the onboarding block', function () {
    [$owner] = createBusinessOwner([
        'email' => 'returning-setup@example.test',
        'trial_ends_at' => now()->addWeek(),
        'kyc' => 'none',
    ]);

    $this->postJson('/api/v1/management/auth/login', [
        'email' => 'returning-setup@example.test',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.otp_required', true);

    $response = $this->postJson('/api/v1/management/auth/verify-otp', [
        'email' => 'returning-setup@example.test',
        'otp' => ruoOtp('returning-setup@example.test', 'business_login'),
    ])->assertOk();

    expect($response->json('data.next'))->toBe('payment_setup')
        ->and($response->json('data.user.onboarding.next'))->toBe('payment_setup')
        ->and($response->json('data.user.onboarding.steps.kyc.done'))->toBeFalse();
});

test('the password and email checks precede the setup gate', function () {
    [$owner] = createBusinessOwner([
        'force_password_change' => true,
        'trial_ends_at' => now()->addWeek(),
        'kyc' => 'none',
    ]);

    expect(ruoNext($owner))->toBe('change_password');

    $owner->forceFill(['force_password_change' => false, 'is_verified' => false])->save();

    expect(ruoNext($owner->fresh()))->toBe('verify_email');

    $owner->forceFill(['is_verified' => true])->save();

    expect(ruoNext($owner->fresh()))->toBe('payment_setup');
});

test('a legacy kyc application with no business counts, one from another business does not', function () {
    [$owner, $business] = createBusinessOwner([
        'kyc' => 'none',
        'trial_ends_at' => now()->addWeek(),
    ]);

    connectBusinessPayoutBank($business);

    KycApplication::query()->create([
        'user_id' => $owner->id,
        'business_id' => null,
        'status' => KycApplication::STATUS_APPROVED,
        'legal_name' => 'Test Owner',
        'first_name' => 'Test',
        'last_name' => 'Owner',
        'phone_number' => '08030000000',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'address_line' => '1 Test Street',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'kyc_document_id' => 'NIN-123456789',
        'submitted_at' => now()->subDay(),
        'approved_at' => now()->subDay(),
    ]);

    expect(ruoNext($owner))->toBe('dashboard');

    // An application written for a different business is not this business's
    // identity, even when it sits on the same user row.
    [$otherOwner, $otherBusiness] = createBusinessOwner();

    KycApplication::query()
        ->where('user_id', $owner->id)
        ->update(['business_id' => $otherBusiness->id]);

    expect(ruoNext($owner))->toBe('payment_setup');
});
