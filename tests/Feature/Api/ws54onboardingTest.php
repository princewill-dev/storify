<?php

use App\Models\Business;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| WS-54 — the tail of onboarding
|--------------------------------------------------------------------------
| Two things the signup flow needs after the subscription exists:
|
|  - a business that has never been shown the `/welcome` interstitial is
|    steered back to it, once, and then never again;
|  - the two names signup derived from an email address can be corrected.
|
| The ordering assertions matter more than they look: `welcome` sits *after*
| the subscription check in `nextStep()`, so an owner who has paid for nothing
| is sent to plans rather than to a page congratulating them.
*/

function ws54Token(User $user, array $abilities = ['management']): string
{
    return $user->createToken('management-access', $abilities, now()->addHour())->plainTextToken;
}

/**
 * An owner with access in hand and a business that has never been welcomed.
 *
 * The payout destination is connected because these tests are about the
 * interstitial, not about WS-63's setup gate: without it `nextStep()` would
 * answer `payment_setup` before `welcome` for every fixture here.
 */
function ws54FreshBusiness(array $userAttributes = [], array $businessAttributes = []): array
{
    [$user, $business] = createBusinessOwner(
        array_merge(['trial_ends_at' => now()->addWeek()], $userAttributes),
        array_merge(['welcome_seen_at' => null], $businessAttributes),
    );

    connectBusinessPayoutBank($business);

    return [$user, $business->fresh()];
}

test('a business that has never been welcomed is steered to the welcome screen', function () {
    [$owner] = ws54FreshBusiness();

    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'welcome');
});

test('a business that has been welcomed is not', function () {
    // The population the migration's backfill creates: every business that
    // existed before this column did. Signing in must go straight through —
    // and the payout destination is connected so the WS-63 setup gate is not
    // what answers instead.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    connectBusinessPayoutBank($business);

    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard');
});

test('a business with no plan is sent to plans rather than to the welcome screen', function () {
    // The ordering decision, pinned. A person who has paid for nothing is not
    // congratulated, and the interstitial is not a substitute for a plan.
    [$owner] = ws54FreshBusiness(['trial_ends_at' => null]);

    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'plans');
});

test('marking the welcome screen seen clears it and reports the next step', function () {
    [$owner, $business] = ws54FreshBusiness();

    expect($business->welcome_seen_at)->toBeNull();

    $this->withToken(ws54Token($owner))
        ->postJson('/api/v1/management/onboarding/welcome-seen')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard');

    expect($business->fresh()->welcome_seen_at)->not->toBeNull();

    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard');
});

test('marking it seen twice does not move the recorded moment', function () {
    // "Skip for now" and a card click both land here, and the page can be
    // reached again by a reload before the navigation completes. The first
    // moment is the one that is true.
    [$owner, $business] = ws54FreshBusiness();

    $this->withToken(ws54Token($owner))
        ->postJson('/api/v1/management/onboarding/welcome-seen')
        ->assertOk();

    $first = $business->fresh()->welcome_seen_at;

    $this->travel(1)->days();

    $this->withToken(ws54Token($owner))
        ->postJson('/api/v1/management/onboarding/welcome-seen')
        ->assertOk();

    expect($business->fresh()->welcome_seen_at->equalTo($first))->toBeTrue();
});

test('an owner without a business cannot mark a welcome screen seen', function () {
    // 403 and not 422: the permission on this route comes from the business's
    // own role, so somebody with no business holds none and is refused before
    // the controller runs. The controller's own `! $business` guard is behind
    // that and unreachable from here.
    $owner = User::factory()->create([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $this->withToken(ws54Token($owner))
        ->postJson('/api/v1/management/onboarding/welcome-seen')
        ->assertStatus(403);

    // And they are steered to setup rather than to the interstitial anyway.
    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'setup');
});

test('a cashier is never sent to the welcome screen', function () {
    // The flag lives on the business so that the owner's checklist cannot be
    // dealt with by somebody who is not being onboarded.
    [$owner, $business] = ws54FreshBusiness();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws54Token($staff))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard');
});

test('a cashier cannot clear the owner\'s welcome screen', function () {
    [$owner, $business] = ws54FreshBusiness();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws54Token($staff))
        ->postJson('/api/v1/management/onboarding/welcome-seen')
        ->assertStatus(403);

    expect($business->fresh()->welcome_seen_at)->toBeNull();
});

test('the business details are written to the business and to the owner', function () {
    [$owner, $business] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek(), 'name' => 'Ada', 'phone' => null],
        ['name' => 'Ada', 'welcome_seen_at' => now()],
    );

    $this->withToken(ws54Token($owner))
        ->patchJson('/api/v1/management/business', [
            'name' => 'Eze Fabrics Ltd',
            'owner_name' => 'Ada Eze',
            'phone' => '08012345678',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Ada Eze')
        ->assertJsonPath('data.user.business.name', 'Eze Fabrics Ltd');

    expect($business->fresh()->name)->toBe('Eze Fabrics Ltd')
        ->and($owner->fresh()->name)->toBe('Ada Eze')
        ->and($owner->fresh()->phone)->toBe('08012345678');
});

test('the phone number can be cleared', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek(), 'phone' => '08012345678']);

    $this->withToken(ws54Token($owner))
        ->patchJson('/api/v1/management/business', [
            'name' => 'Eze Fabrics Ltd',
            'owner_name' => 'Ada Eze',
        ])
        ->assertOk();

    expect($owner->fresh()->phone)->toBeNull();
});

test('the business being edited is the caller\'s own, never one named in the request', function () {
    // There is no id in the payload, and adding one must not change whose row
    // is written.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $other] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Somebody Else Ltd']);

    $this->withToken(ws54Token($owner))
        ->patchJson('/api/v1/management/business', [
            'name' => 'Eze Fabrics Ltd',
            'owner_name' => 'Ada Eze',
            'business_id' => $other->id,
            'id' => $other->id,
        ])
        ->assertOk();

    expect($business->fresh()->name)->toBe('Eze Fabrics Ltd')
        ->and($other->fresh()->name)->toBe('Somebody Else Ltd');
});

test('a cashier cannot rename the business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws54Token($staff))
        ->patchJson('/api/v1/management/business', [
            'name' => 'Renamed By A Cashier',
            'owner_name' => 'A Cashier',
        ])
        ->assertStatus(403);
});

test('a lapsed business can still correct the name on its invoices', function () {
    // This is why `business.` is on SubscriptionGate::EXEMPT_PREFIXES: the
    // name on an invoice is worth being able to fix whether or not there is an
    // active plan behind it.
    [$owner, $business] = createBusinessOwner(
        ['trial_ends_at' => null],
        ['name' => 'Ada', 'welcome_seen_at' => now()],
    );

    // The gate is still shut for everything else. `stores` and not `dashboard`
    // on purpose: `dashboard` is on the gate's own exempt list, so it would
    // answer 200 whether or not this exemption worked.
    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/stores')
        ->assertStatus(403)
        ->assertJsonPath('code', 'subscription_required');

    $this->withToken(ws54Token($owner))
        ->patchJson('/api/v1/management/business', [
            'name' => 'Eze Fabrics Ltd',
            'owner_name' => 'Ada Eze',
            'phone' => '08012345678',
        ])
        ->assertOk();

    expect($business->fresh()->name)->toBe('Eze Fabrics Ltd');
});

test('the business details are validated', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws54Token($owner))
        ->patchJson('/api/v1/management/business', ['name' => '', 'owner_name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'owner_name']);
});

test('the intents travel with the auth payload', function () {
    // The SPA orders the welcome cards and pre-ticks the store form from this,
    // and both screens are reached straight off an auth response.
    [$owner] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['onboarding_intents' => ['sell_online', 'sell_in_store'], 'welcome_seen_at' => null],
    );

    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.business.intents', ['sell_online', 'sell_in_store']);
});

test('a business with no intents reports an empty list rather than null', function () {
    // Businesses that predate the column. The SPA reads this as an array on
    // every auth response, so it has to be one.
    [$owner] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['onboarding_intents' => null],
    );

    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.business.intents', []);
});

test('the businesses that predate the column are treated as already welcomed', function () {
    // The migration backfills `welcome_seen_at` at deploy time, so a business
    // whose column was set that way is the shape every existing business has.
    // This pins the *intent* of the backfill: a business with the flag set —
    // whenever it was set — never sees the interstitial.
    [$owner, $business] = createBusinessOwner(
        ['trial_ends_at' => now()->addWeek()],
        ['welcome_seen_at' => now()->subYear()],
    );

    connectBusinessPayoutBank($business);

    $this->withToken(ws54Token($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard');

    expect(Business::find($owner->business_id)->welcome_seen_at)->not->toBeNull();
});
