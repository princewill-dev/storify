<?php

use App\Models\Business;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\StoreQuota;
use Database\Seeders\SubscriptionPlanSeeder;

/*
| WS-52 — the store cap, and the seeder that stops eating the admin's work.
|
| `settings.store_creation_limit` was stored, validated, displayed in the admin
| console, and read by no creation path on the platform: a plan that said three
| stores could run thirty. These tests pin the first limit that actually bites,
| and the three decisions inside it that are easy to get wrong in a way nobody
| notices until an owner is angry:
|
|   * a **deleted** store frees its slot, because deleting is a status on a row
|     that stays in the table;
|   * a **suspended** store does not, because the store still exists;
|   * a downgrade **grandfathers** — every store kept, no new one added — rather
|     than deleting anything.
*/

function ws52Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws52Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.strtolower(str()->random(6)),
        'slug' => 'ws52-'.strtolower(str()->random(10)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws52Stores(User $owner, int $count, array $attributes = []): void
{
    for ($i = 1; $i <= $count; $i++) {
        ws52Store($owner, $attributes);
    }
}

function ws52Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Entry',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'tier' => 1,
        'entitlements' => [
            EntitlementRegistry::STORES => 3,
            EntitlementRegistry::OWN_GATEWAY => false,
        ],
        'sort_order' => 1,
    ], $attributes));
}

function ws52Subscribe(User $user, SubscriptionPlan $plan): Subscription
{
    return Subscription::create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subWeek(),
        'expires_at' => now()->addMonth(),
    ]);
}

/* ------------------------------------------------------------- the cap */

test('a business under its store limit can add another', function () {
    // The control. A cap that refuses everything is not a cap, it is an outage.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws52Subscribe($owner, ws52Plan());

    ws52Stores($owner, 2);

    $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Third Store',
            'has_website' => true,
            'is_physical' => false,
        ])
        ->assertCreated();
});

test('a business at its store limit is refused, and told which plan it is on', function () {
    // The whole point. Before this the third store was a formality and the
    // fourth was free.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws52Subscribe($owner, ws52Plan());

    ws52Stores($owner, 3);

    $response = $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Fourth Store',
            'has_website' => true,
            'is_physical' => false,
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('code', StoreQuota::REFUSAL_CODE)
        ->assertJsonPath('redirect', '/plans');

    expect($response->json('message'))
        ->toContain('Entry')
        ->toContain('3 stores');

    expect(Store::where('business_id', $business->id)->count())->toBe(3);
});

test('the refusal names the plan that would allow more', function () {
    // "You have reached your limit" with nothing after it is an instruction to
    // leave. The plan is read from the table, so a renamed plan is named right.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws52Subscribe($owner, ws52Plan());

    ws52Plan([
        'name' => 'Growth',
        'tier' => 2,
        'amount' => 15000,
        'entitlements' => [EntitlementRegistry::STORES => 20],
        'sort_order' => 2,
    ]);

    ws52Stores($owner, 3);

    $response = $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Fourth Store',
            'has_website' => true,
            'is_physical' => false,
        ]);

    expect($response->json('message'))->toContain('Growth')->toContain('20 stores');
});

test('a deleted store frees its slot', function () {
    // `Store` has no SoftDeletes — deleting is `status = 'deleted'` on a row
    // that stays. Counting rows would keep charging the business for a store it
    // has removed, which makes the limit a lie the owner can see.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws52Subscribe($owner, ws52Plan());

    ws52Stores($owner, 2);
    ws52Store($owner, ['status' => Store::STATUS_DELETED]);

    $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Replacement',
            'has_website' => true,
            'is_physical' => false,
        ])
        ->assertCreated();
});

test('a suspended store still occupies its slot', function () {
    // The other half of the same rule. A suspended store still exists on the
    // business's account, so the space it holds is not free — otherwise
    // suspending a store would be a way to run more of them than the plan sells.
    [$owner] = createBusinessOwner(['is_verified' => true]);
    ws52Subscribe($owner, ws52Plan());

    ws52Stores($owner, 2);
    ws52Store($owner, ['status' => Store::STATUS_SUSPENDED]);

    $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Fourth Store',
            'has_website' => true,
            'is_physical' => false,
        ])
        ->assertStatus(403);
});

test('a downgrade grandfathers every store and then refuses the next', function () {
    // The owner's decision, and the one a customer relationship depends on:
    // moving down a tier never takes anything away. The business keeps all
    // twelve stores and simply cannot add a thirteenth.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);

    $enterprise = ws52Plan([
        'name' => 'Enterprise',
        'tier' => 3,
        'amount' => 50000,
        'entitlements' => [EntitlementRegistry::STORES => null],
        'sort_order' => 3,
    ]);
    $entry = ws52Plan();

    $subscription = ws52Subscribe($owner, $enterprise);

    // Twelve stores on a plan with no ceiling.
    ws52Stores($owner, 12);

    $subscription->update(['subscription_plan_id' => $entry->id]);

    expect(Store::where('business_id', $business->id)
        ->where('status', '!=', Store::STATUS_DELETED)
        ->count())->toBe(12);

    $response = $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Thirteenth',
            'has_website' => true,
            'is_physical' => false,
        ]);

    $response->assertStatus(403);

    // The message has to say the stores keep working. An owner who has just
    // been told they are nine over their limit will otherwise assume their
    // stores are about to be taken away.
    expect($response->json('message'))
        ->toContain('12')
        ->toContain('keeps working');

    expect(Store::where('business_id', $business->id)
        ->where('status', '!=', Store::STATUS_DELETED)
        ->count())->toBe(12);
});

test('an unlimited plan never refuses', function () {
    // `null` is unlimited, and it is most of what the top tier sells. Reading it
    // as zero would turn the plan that grants everything into the plan that
    // cannot create one store — the single most expensive mistake available here.
    [$owner] = createBusinessOwner(['is_verified' => true]);

    ws52Subscribe($owner, ws52Plan([
        'name' => 'Enterprise',
        'tier' => 3,
        'entitlements' => [EntitlementRegistry::STORES => null],
    ]));

    ws52Stores($owner, 9);

    $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Tenth',
            'has_website' => true,
            'is_physical' => false,
        ])
        ->assertCreated();
});

test('the create screen is told what the business holds against what it allows', function () {
    // Sent so the form is not offered when its only outcome is a refusal — the
    // same contract the payment screens hold with GatewayGrant.
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws52Subscribe($owner, ws52Plan());

    ws52Stores($owner, 2);

    $this->withToken(ws52Token($owner))
        ->getJson('/api/v1/management/stores/onboarding/options')
        ->assertOk()
        ->assertJsonPath('data.store_quota.used', 2)
        ->assertJsonPath('data.store_quota.limit', 3)
        ->assertJsonPath('data.store_quota.remaining', 1)
        ->assertJsonPath('data.store_quota.reached', false)
        ->assertJsonPath('data.store_quota.upgrade_url', '/plans');
});

test('the create screen reports the limit as reached at the cap', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws52Subscribe($owner, ws52Plan());

    ws52Stores($owner, 3);

    $this->withToken(ws52Token($owner))
        ->getJson('/api/v1/management/stores/onboarding/options')
        ->assertOk()
        ->assertJsonPath('data.store_quota.reached', true)
        ->assertJsonPath('data.store_quota.remaining', 0);
});

test('the store count is asked of the business and nobody else', function () {
    // Tenant scoping. A business's cap is its own stores, and a second business
    // with a full slate must not be able to make the first one look full.
    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    [$otherOwner] = createBusinessOwner(['is_verified' => true]);

    ws52Subscribe($owner, ws52Plan());

    ws52Stores($otherOwner, 5);

    expect(StoreQuota::used((int) $business->id))->toBe(0)
        ->and(StoreQuota::reached($business->fresh()))->toBeFalse();
});

/* ------------------------------------------------- what the limit falls to */

test('no plan and no trial entitles a business to no stores at all', function () {
    // Fail closed, and unreachable over HTTP: `EnsureManagementSubscription`
    // refuses these businesses the whole `/management/*` group first. Tested
    // here because it is the belt to that pair of braces, and because the shape
    // of the refusal — an actionable message, not a store count — is the part
    // somebody will read.
    [$owner, $business] = createBusinessOwner();

    $refusal = StoreQuota::refusal($business->fresh());

    expect($refusal)->not->toBeNull()
        ->and($refusal['code'])->toBe(StoreQuota::REFUSAL_CODE)
        ->and($refusal['redirect'])->toBe('/plans')
        ->and($refusal['message'])->toContain('no active plan')
        ->and(StoreQuota::used((int) $business->id))->toBe(0);
});

test('a live trial with no plan row anywhere still gets the house allowance', function () {
    // A deployment before anybody has marked a default plan — every fresh
    // install, and every test database. A trial that resolved to nothing would
    // be a trial that cannot create a single store, which is the opposite of
    // what a trial is for. It falls back to the registry's own defaults, which
    // are the entry tier's, so nobody gets more than the cheapest plan gives.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    expect(StoreQuota::refusal($business->fresh()))->toBeNull()
        ->and(StoreQuota::used((int) $business->id))->toBe(0);

    $this->withToken(ws52Token($owner))
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/v1/management/stores', [
            'name' => 'Trial Store',
            'has_website' => true,
            'is_physical' => false,
        ])
        ->assertCreated();
});

/* ------------------------------------------------------- the seeder itself */

test('the plan seeder seeds three tiers once and never rewrites them', function () {
    // `updateOrCreate` with a fresh `plan_code` inside the values array ran on
    // every `db:seed`, so a saved checkout URL broke on each deploy. A seeder
    // that cannot be run twice is a seeder nobody can run.
    $this->seed(SubscriptionPlanSeeder::class);

    $entry = SubscriptionPlan::where('name', 'Entry')->firstOrFail();
    $code = $entry->plan_code;

    $this->seed(SubscriptionPlanSeeder::class);

    expect(SubscriptionPlan::where('name', 'Entry')->count())->toBe(1)
        ->and($entry->fresh()->plan_code)->toBe($code)
        ->and(SubscriptionPlan::where('name', 'Growth')->firstOrFail()->tier)->toBe(2)
        ->and(SubscriptionPlan::where('name', 'Enterprise')->firstOrFail()->tier)->toBe(3);
});

test('the seeded tiers carry the shape the owner asked for', function () {
    $this->seed(SubscriptionPlanSeeder::class);

    $stores = fn (string $name): ?int => SubscriptionPlan::where('name', $name)
        ->firstOrFail()
        ->entitlements[EntitlementRegistry::STORES] ?? null;

    $ownGateway = fn (string $name): bool => SubscriptionPlan::where('name', $name)
        ->firstOrFail()
        ->entitlements[EntitlementRegistry::OWN_GATEWAY] ?? false;

    expect($stores('Entry'))->toBe(3)
        ->and($stores('Growth'))->toBe(20)
        // Unlimited, and only here.
        ->and($stores('Enterprise'))->toBeNull()
        ->and($ownGateway('Entry'))->toBeFalse()
        ->and($ownGateway('Growth'))->toBeFalse()
        ->and($ownGateway('Enterprise'))->toBeTrue();
});

test('the plan seeder leaves an admin plan alone, and does not take the default', function () {
    // The old seeder ran `whereNotIn('name', [...])->update(['is_active' => false])`
    // and cleared `is_default` on every plan. Both undid console work, and one
    // of them silently re-entitled every trial that had never picked a plan.
    $this->seed(SubscriptionPlanSeeder::class);

    $entry = SubscriptionPlan::where('name', 'Entry')->firstOrFail();

    $custom = SubscriptionPlan::create([
        'name' => 'Agency Retainer',
        'amount' => 120000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => true,
        'is_trial' => false,
        'tier' => 4,
        'sort_order' => 9,
    ]);

    // And an admin renames and re-prices a seeded tier, which is the whole point
    // of planning in data rather than in code.
    $entry->update(['name' => 'Starter', 'amount' => 7000]);

    $this->seed(SubscriptionPlanSeeder::class);

    expect($custom->fresh()->is_active)->toBeTrue()
        ->and($custom->fresh()->is_default)->toBeTrue()
        ->and($entry->fresh()->name)->toBe('Starter')
        ->and((float) $entry->fresh()->amount)->toBe(7000.0)
        ->and($entry->fresh()->is_active)->toBeTrue()
        // The seeded plans never take the default: it decides what a trial
        // nobody has chosen a plan for resolves to, and moving it at deploy time
        // would re-entitle every such trial without anybody asking.
        ->and(SubscriptionPlan::where('is_default', true)->value('id'))->toBe($custom->id);
});

test('the seeder is idempotent against a business that already runs on it', function () {
    // End to end: seed, subscribe a business, seed again, and the business's
    // entitlement is the one it bought.
    $this->seed(SubscriptionPlanSeeder::class);

    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    ws52Subscribe($owner, SubscriptionPlan::where('name', 'Growth')->firstOrFail());

    ws52Stores($owner, 20);

    $this->seed(SubscriptionPlanSeeder::class);

    $refusal = StoreQuota::refusal($business->fresh());

    expect($refusal)->not->toBeNull()
        ->and($refusal['message'])->toContain('Growth');

    expect(Business::find($business->id))->not->toBeNull();
});
