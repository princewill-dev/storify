<?php

use App\Models\Business;
use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function onboardingToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function unprovisionedOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ], $attributes));
}

test('business setup creates the business and provisions its roles', function () {
    $user = unprovisionedOwner();

    $response = $this->withToken(onboardingToken($user))->postJson('/api/v1/management/setup', [
        'name' => 'Acme Stores',
        'description' => 'A test business',
        'phone' => '08012345678',
        'business_location' => 'Lagos',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.business.name', 'Acme Stores')
        ->assertJsonPath('data.next', 'plans');

    $user->refresh();

    expect($user->business_id)->not->toBeNull();
    // The legacy form validated a phone number and then threw it away.
    expect($user->phone)->toBe('08012345678');

    $business = Business::find($user->business_id);

    expect($business->status)->toBe('active');
    expect($business->business_location)->toBe('Lagos');

    setPermissionsTeamId($business->id);

    expect($user->fresh()->hasRole('Super Admin'))->toBeTrue();
});

test('business setup is rejected when the account already has a business', function () {
    [$owner, $business] = createBusinessOwner();

    $this->withToken(onboardingToken($owner))
        ->postJson('/api/v1/management/setup', ['name' => 'Second Business'])
        ->assertStatus(409);

    expect(Business::where('user_id', $owner->id)->count())->toBe(1);
});

test('business setup is rejected before the email is verified', function () {
    $user = unprovisionedOwner(['is_verified' => false]);

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/setup', ['name' => 'Acme Stores'])
        ->assertStatus(403);

    expect($user->fresh()->business_id)->toBeNull();
});

test('business setup derives a name when the screen did not ask for one', function () {
    // "How can we be of help" collects intents and nothing else. The business
    // row still needs a name — a plan cannot be priced against nothing — so it
    // is derived from the owner's address and corrected later, on the payment
    // settings screen. A blank name is therefore not an error any more.
    $user = unprovisionedOwner(['email' => 'chidi.okonkwo@example.com']);

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/setup', [
            'name' => '',
            'intents' => ['sell_online', 'sell_in_store'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.user.business.name', 'Chidi Okonkwo')
        ->assertJsonPath('data.next', 'plans');

    expect(Business::find($user->fresh()->business_id)->onboarding_intents)
        ->toBe(['sell_online', 'sell_in_store']);
});

test('business setup stores the default intent when none is submitted', function () {
    $user = unprovisionedOwner();

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/setup', [])
        ->assertCreated();

    expect(Business::find($user->fresh()->business_id)->onboarding_intents)
        ->toBe([Business::DEFAULT_ONBOARDING_INTENT]);
});

test('business setup refuses an intent that is not on the list', function () {
    $user = unprovisionedOwner();

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/setup', ['intents' => ['sell_online', 'sell_cars']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('intents.1');
});

test('registration does not require a name or a phone number', function () {
    $this->postJson('/api/v1/management/auth/register', [
        'email' => 'ada.eze@example.com',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertCreated();

    // `users.name` is NOT NULL, so the account still gets one — derived, and
    // displayed nowhere the person can see until they confirm it.
    $user = User::where('email', 'ada.eze@example.com')->firstOrFail();

    expect($user->name)->toBe('Ada Eze')
        ->and($user->phone)->toBeNull();
});

test('registration still accepts a name and phone from an older build of the app', function () {
    $this->postJson('/api/v1/management/auth/register', [
        'name' => 'Ada Eze',
        'email' => 'ada@example.com',
        'phone' => '08012345678',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertCreated();

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect($user->name)->toBe('Ada Eze')
        ->and($user->phone)->toBe('08012345678');
});

test('a forced password change does not require the current password', function () {
    $user = unprovisionedOwner([
        'password' => Hash::make('temporary-password'),
        'force_password_change' => true,
    ]);

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertOk();

    $user->refresh();

    expect(Hash::check('brand-new-password', $user->password))->toBeTrue();
    expect($user->force_password_change)->toBeFalse();
});

test('a normal password change still requires the current password', function () {
    $user = unprovisionedOwner([
        'password' => Hash::make('current-password'),
        'force_password_change' => false,
    ]);

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertStatus(422);

    expect(Hash::check('current-password', $user->fresh()->password))->toBeTrue();
});

test('me reports the impersonator during an impersonation session', function () {
    [$owner, $business] = createBusinessOwner();
    [$admin] = createBusinessOwner();

    $impersonation = Impersonation::create([
        'impersonator_id' => $admin->id,
        'impersonated_id' => $owner->id,
        'started_at' => now(),
    ]);

    $token = $owner->createToken(
        'management-access',
        ['management', Impersonation::ABILITY_PREFIX.$impersonation->id],
        now()->addHour(),
    )->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.impersonator.id', $admin->id)
        ->assertJsonPath('data.impersonator.name', $admin->name);
});

test('me reports no impersonator for a normal session', function () {
    [$owner] = createBusinessOwner();

    $this->withToken(onboardingToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.impersonator', null);
});

test('me reports the permissions a user holds through their role', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // The owner holds no permissions directly — everything arrives through the
    // business's Super Admin role. An earlier payload read getPermissionNames(),
    // which returns only DIRECT assignments, so this came back empty and every
    // permission-gated control in the SPA hid itself.
    $permissions = $this->withToken(onboardingToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->json('data.user.permissions');

    expect($permissions)->not->toBeEmpty()
        ->and($permissions)->toContain('stores view')
        ->and($permissions)->toContain('orders view');
});

test('a forced password change blocks the rest of the management api', function () {
    [$owner] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'force_password_change' => true,
    ]);

    $this->withToken(onboardingToken($owner))
        ->getJson('/api/v1/management/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('code', 'change_password')
        ->assertJsonPath('redirect', '/change-password');
});

test('the forced password change leaves the auth routes reachable', function () {
    [$owner] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'force_password_change' => true,
    ]);

    // Without this the SPA could not sign the user out or learn that it is
    // gated — legacy exempted the same set.
    $this->withToken(onboardingToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk();
});

test('clearing the forced password change restores access', function () {
    [$owner] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'force_password_change' => false,
    ]);

    $this->withToken(onboardingToken($owner))
        ->getJson('/api/v1/management/dashboard')
        ->assertOk();
});

test('staff are exempt from the forced password change gate', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
        'force_password_change' => true,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(onboardingToken($staff))
        ->getJson('/api/v1/management/dashboard')
        ->assertOk();
});
