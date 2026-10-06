<?php

use App\Models\Business;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Onboarding flow — legacy parity gaps
|--------------------------------------------------------------------------
| The rest of tests/Feature/Management/OnboardingFlowTest.php is already on
| the API. ManagementOnboardingApiTest covers POST /management/setup end to
| end (business created, assigned to the owner, Super Admin role provisioned
| for the business, phone persisted, duplicate/unverified/invalid refusals)
| and the `next: plans` hand-off; ws07subscriptionplansTest covers the plan
| catalogue behind the legacy plans page; ws09subscriptiongateTest covers an
| owner without a business being let through the subscription gate.
|
| Two behaviours the legacy file asserted are not pinned anywhere yet, and
| the API expresses them without redirects — the SPA reads the `next` field
| returned by the auth endpoints:
|
|  - a verified owner without a business is steered to setup (`next: setup`);
|  - setup links the business back to its owner (strict business_id identity
|    and the Business::owner relation).
*/

function onboardingFlowToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function unprovisionedBusinessOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ], $attributes));
}

test('a verified owner without a business is steered to business setup', function () {
    $owner = unprovisionedBusinessOwner();

    // Legacy 302'd GET /management/dashboard to /management/setup. The API
    // has no redirect middleware: the SPA calls /me on load and follows `next`.
    $this->withToken(onboardingFlowToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'setup')
        ->assertJsonPath('data.user.business_id', null)
        ->assertJsonPath('data.user.business', null);
});

test('business setup links the new business back to its owner', function () {
    $owner = unprovisionedBusinessOwner();

    $this->withToken(onboardingFlowToken($owner))
        ->postJson('/api/v1/management/setup', [
            'name' => 'Northstar Retail',
            'description' => 'A test retail business',
            'business_location' => 'Lagos',
        ])
        ->assertCreated();

    $business = Business::where('name', 'Northstar Retail')->firstOrFail();

    expect($owner->fresh()->business_id)->toBe($business->id)
        ->and($business->owner->is($owner->fresh()))->toBeTrue();
});
