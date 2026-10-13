<?php

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Plans\EntitlementRegistry;
use Database\Seeders\SpatiePermissionSeeder;

/*
| AD-19 — the admin's control over what a plan contains, and over who is on it.
|
| Two endpoints, and each closes a hole that only showed up once a plan started
| meaning something. The editor is what makes "admin-controlled plans" true
| rather than aspirational — before it, `features` was a bullet list nothing
| read, so editing a plan changed a marketing page and nothing else. The
| assignment endpoint is the escape hatch the store limit makes mandatory: the
| moment a cap can refuse somebody, there has to be a way to lift it that does
| not involve a SQL client.
|
| The recurring theme in the assertions below is that the *registry* is the
| boundary, not this controller: a key the platform does not know is dropped
| rather than stored, a blank limit means unlimited rather than zero, and the
| catalogue the editor renders comes from the same source the API writes
| through.
*/

function ad19Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad19SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad19Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Entry',
        'amount' => '5000.00',
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => false,
        'is_trial' => false,
        'features' => ['3 stores'],
        'sort_order' => 1,
        'tier' => 1,
    ], $attributes));
}

/* ------------------------------------------------------- the plan contents */

test('the entitlements catalogue describes every entitlement the platform knows', function () {
    // The editor is built from this, so a new entitlement appears in the console
    // with no frontend change — the same contract the payment screens have with
    // PaymentGatewayRegistry.
    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->getJson('/api/v1/admin/entitlements')
        ->assertOk()
        ->assertJsonCount(count(EntitlementRegistry::keys()), 'data')
        ->assertJsonPath('data.0.key', EntitlementRegistry::keys()[0]);
});

test('a plan is created with its entitlements and tier', function () {
    $response = $this->withToken(ad19Token(ad19SuperAdmin()))
        ->postJson('/api/v1/admin/subscription-plans', [
            'name' => 'Growth',
            'amount_kobo' => 1500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'interval_count' => 1,
            'tier' => 2,
            'entitlements' => [
                EntitlementRegistry::STORES => 20,
                EntitlementRegistry::OWN_GATEWAY => true,
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.plan.tier', 2)
        ->assertJsonPath('data.plan.entitlements.'.EntitlementRegistry::STORES, 20)
        ->assertJsonPath('data.plan.entitlements.'.EntitlementRegistry::OWN_GATEWAY, true);
});

test('a blank limit is stored as unlimited and never as zero', function () {
    // The most expensive mistake available in this area. The enterprise plan's
    // defining feature is an empty box in the admin form, and reading that box
    // as zero turns the plan that grants everything into the plan that cannot
    // create one store.
    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->postJson('/api/v1/admin/subscription-plans', [
            'name' => 'Enterprise',
            'amount_kobo' => 5000000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'interval_count' => 1,
            'tier' => 3,
            'entitlements' => [
                EntitlementRegistry::STORES => '',
                EntitlementRegistry::OWN_GATEWAY => true,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.plan.entitlements.'.EntitlementRegistry::STORES, null);

    $plan = SubscriptionPlan::where('name', 'Enterprise')->firstOrFail();

    expect($plan->entitlements[EntitlementRegistry::STORES])->toBeNull();
});

test('an entitlement key the registry does not know is dropped, not stored', function () {
    // A key left behind by an entitlement that no longer exists would otherwise
    // sit in the column forever, read by nothing and confusing everyone who
    // looks at it.
    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->postJson('/api/v1/admin/subscription-plans', [
            'name' => 'Growth',
            'amount_kobo' => 1500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'interval_count' => 1,
            'tier' => 2,
            'entitlements' => [
                EntitlementRegistry::STORES => 20,
                'unlimited_everything' => true,
            ],
        ])
        ->assertCreated();

    $plan = SubscriptionPlan::where('name', 'Growth')->firstOrFail();

    expect($plan->entitlements)->toBe([EntitlementRegistry::STORES => 20]);
});

test('an edit that says nothing about entitlements leaves them alone', function () {
    // Deliberate deviation from this endpoint's full-replacement contract: the
    // column is new, so every client that predates it omits the key, and reading
    // that as "grant nothing" would empty a plan the first time anybody renamed
    // it.
    $plan = ad19Plan([
        'entitlements' => [EntitlementRegistry::STORES => 7],
    ]);

    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/subscription-plans/{$plan->plan_code}", [
            'name' => 'Entry Renamed',
            'amount_kobo' => 500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'interval_count' => 1,
        ])
        ->assertOk();

    expect($plan->fresh()->entitlements)->toBe([EntitlementRegistry::STORES => 7])
        ->and($plan->fresh()->name)->toBe('Entry Renamed');
});

test('changing what a plan grants is written to the audit trail', function () {
    // The field with the widest blast radius on the form: an edit here changes
    // what every business on the plan is entitled to, immediately. If it is not
    // recorded, it is not attributable.
    $plan = ad19Plan(['entitlements' => [EntitlementRegistry::STORES => 3]]);

    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/subscription-plans/{$plan->plan_code}", [
            'name' => 'Entry',
            'amount_kobo' => 500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'interval_count' => 1,
            'tier' => 1,
            'entitlements' => [EntitlementRegistry::STORES => 25],
        ])
        ->assertOk();

    $log = ActivityLog::where('action', 'subscription_plan_updated')->latest('id')->firstOrFail();

    expect($log->old_values['entitlements'] ?? null)->toBe([EntitlementRegistry::STORES => 3])
        ->and($log->new_values['entitlements'] ?? null)->toBe([EntitlementRegistry::STORES => 25]);
});

/* ------------------------------------------------------ assigning a plan */

test('an admin puts a trialling business on a plan, and it takes effect', function () {
    // The commonest support case, and the whole justification for the store cap
    // being safe to ship: a business ringing up because a limit refused them has
    // to be fixable by somebody who is not a DBA.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ad19Plan([
        'name' => 'Enterprise',
        'tier' => 3,
        'entitlements' => [EntitlementRegistry::STORES => null],
    ]);

    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $plan->plan_code])
        ->assertOk()
        // A trial has no subscription row to move, so the choice lands on the
        // owner — which is where Entitlements reads a trial's plan from.
        ->assertJsonPath('data.applied', 'trial')
        ->assertJsonPath('data.subscription', null)
        ->assertJsonPath('data.entitlements.entitlements.'.EntitlementRegistry::STORES, null)
        ->assertJsonPath('data.entitlements.source', 'trial');

    expect($owner->fresh()->selected_plan_id)->toBe($plan->id);
});

test('assigning to a paying business moves the plan and leaves the paid period alone', function () {
    // An admin granting an upgrade is granting the *plan*, not free days. Moving
    // `expires_at` either way would silently change a renewal date the business
    // has already been told about.
    [$owner, $business] = createBusinessOwner();

    $entry = ad19Plan(['name' => 'Entry', 'tier' => 1]);
    $enterprise = ad19Plan([
        'name' => 'Enterprise',
        'tier' => 3,
        'entitlements' => [EntitlementRegistry::STORES => null],
    ]);

    $expiresAt = now()->addDays(12)->startOfSecond();

    $subscription = Subscription::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'subscription_plan_id' => $entry->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subMonth(),
        'expires_at' => $expiresAt,
    ]);

    $response = $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $enterprise->plan_code])
        ->assertOk()
        ->assertJsonPath('data.applied', 'subscription');

    expect($subscription->fresh()->subscription_plan_id)->toBe($enterprise->id)
        ->and($subscription->fresh()->expires_at->toDateTimeString())
        ->toBe($expiresAt->toDateTimeString());

    // And the business can now do what the entry plan refused.
    expect($response->json('data.entitlements.entitlements.'.EntitlementRegistry::STORES))->toBeNull();
});

test('assigning a plan does not charge anybody', function () {
    // The reason this is a separate door from the owner's plan change. A support
    // action that depends on the customer paying is useless in exactly the case
    // it exists for.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ad19Plan(['name' => 'Enterprise', 'tier' => 3]);

    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $plan->plan_code])
        ->assertOk();

    expect(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('subscriptions')->count())->toBe(0);
});

test('a deactivated plan cannot be assigned', function () {
    // A withdrawn plan would give a business entitlements no screen offers and
    // no price list explains — usually by mistake, picked from a stale dropdown.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ad19Plan(['name' => 'Withdrawn', 'is_active' => false, 'is_default' => false]);

    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $plan->plan_code])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan_code');

    expect($owner->fresh()->selected_plan_id)->toBeNull();
});

test('assigning a plan is audited against the business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ad19Plan(['name' => 'Enterprise', 'tier' => 3]);

    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $plan->plan_code])
        ->assertOk();

    $log = ActivityLog::where('action', 'business_plan_assigned')->latest('id')->firstOrFail();

    expect($log->new_values['plan'] ?? null)->toBe('Enterprise')
        ->and($log->description)->toContain($business->name);
});

/* ------------------------------------------------------------ permissions */

test('the plan editor and the assignment endpoint are closed to business owners', function () {
    // A business-scoped owner passes the route permission, because its
    // in-business "Super Admin" role carries the whole permission bundle,
    // `admin.*` names included. What stops it is the platform guard in the
    // controller body — see the note at the bottom of this test about what that
    // means for a malformed payload.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ad19Plan(['name' => 'Enterprise', 'tier' => 3]);

    setPermissionsTeamId($business->id);
    expect($owner->can('admin.subscriptions'))->toBeTrue();

    $this->withToken(ad19Token($owner))
        ->getJson('/api/v1/admin/entitlements')
        ->assertStatus(403);

    $this->withToken(ad19Token($owner))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $plan->plan_code])
        ->assertStatus(403);

    expect($owner->fresh()->selected_plan_id)->toBeNull();

    // And a malformed payload is answered 422 rather than 403, because the
    // FormRequest runs before the controller body. That ordering is the
    // platform's, not this endpoint's — every admin route keeps its guard in the
    // body so its position relative to route binding never moves — and it is
    // asserted here rather than left as a surprise, since the visible effect is
    // that a token which should never reach this route can read its validation
    // shape.
    $this->withToken(ad19Token($owner))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => 'nope'])
        ->assertStatus(422);
});

test('an admin without the subscriptions permission cannot assign a plan', function () {
    // The action changes what a business is entitled to, so it belongs to
    // whoever is allowed to decide what a plan contains.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $plan = ad19Plan(['name' => 'Enterprise', 'tier' => 3]);

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $this->withToken(ad19Token($admin))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $plan->plan_code])
        ->assertStatus(403);

    expect($owner->fresh()->selected_plan_id)->toBeNull();
});

/* --------------------------------------------------- the limit it lifts */

test('the endpoint a support team reaches for actually unblocks a refused store', function () {
    // End to end, because the two halves were built separately: the store cap
    // refuses, and the console can lift it. If either half is wrong the other
    // looks fine in isolation.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);

    $entry = ad19Plan([
        'name' => 'Entry',
        'tier' => 1,
        'entitlements' => [EntitlementRegistry::STORES => 3],
    ]);

    Subscription::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'subscription_plan_id' => $entry->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subWeek(),
        'expires_at' => now()->addMonth(),
    ]);

    for ($i = 0; $i < 3; $i++) {
        Store::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'name' => 'Store '.$i,
            'slug' => 'ad19-'.$i.'-'.strtolower(str()->random(8)),
            'status' => Store::STATUS_ACTIVE,
        ]);
    }

    $token = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->withToken($token)
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Fourth',
            'has_website' => true,
            'is_physical' => false,
        ])
        ->assertStatus(403);

    $enterprise = ad19Plan([
        'name' => 'Enterprise',
        'tier' => 3,
        'entitlements' => [EntitlementRegistry::STORES => null],
    ]);

    $this->withToken(ad19Token(ad19SuperAdmin()))
        ->putJson("/api/v1/admin/businesses/{$business->business_code}/plan", ['plan_code' => $enterprise->plan_code])
        ->assertOk();

    $this->withToken($token)
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Fourth',
            'has_website' => true,
            'is_physical' => false,
        ])
        ->assertCreated();
});
