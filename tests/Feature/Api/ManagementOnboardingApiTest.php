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

test('business setup validates the business name', function () {
    $user = unprovisionedOwner();

    $this->withToken(onboardingToken($user))
        ->postJson('/api/v1/management/setup', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
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
