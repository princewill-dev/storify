<?php

use App\Models\Business;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Money\Naira;
use App\Support\Plans\AnnualPricing;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\StoreQuota;
use Database\Seeders\SubscriptionPlanSeeder;

/*
| WS-52 — the store cap, and the plan seeder that owns the catalogue.
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

test('the plan seeder converges on three tiers in two cadences and keeps their plan codes', function () {
    // "Runnable twice" now means *converges* rather than *does nothing*: this
    // seeder rewrites the three tiers on every run and deletes everything else.
    // The half that must not move is `plan_code` — it is the route key and
    // checkout URLs are published under it, so a re-seed that reissued it would
    // break every link anybody had been sent. Each cadence has its own row and
    // therefore its own code, which is why both are captured here and not just
    // the first one found.
    $this->seed(SubscriptionPlanSeeder::class);

    $entry = SubscriptionPlan::where('name', 'Entry')->where('interval', 'monthly')->firstOrFail();
    $annual = SubscriptionPlan::where('name', 'Entry')->where('interval', 'yearly')->firstOrFail();
    $code = $entry->plan_code;
    $annualCode = $annual->plan_code;

    $this->seed(SubscriptionPlanSeeder::class);

    // The match key is `name` **and** `interval`: the twins deliberately share a
    // name, so keyed on the name alone `updateOrCreate` would match the monthly
    // row on both passes and clobber it with the annual figures.
    expect(SubscriptionPlan::where('name', 'Entry')->count())->toBe(2)
        ->and($entry->fresh()->plan_code)->toBe($code)
        ->and($annual->fresh()->plan_code)->toBe($annualCode)
        ->and(SubscriptionPlan::count())->toBe(6)
        ->and(SubscriptionPlan::where('name', 'Growth')->where('interval', 'monthly')->firstOrFail()->tier)->toBe(2)
        ->and(SubscriptionPlan::where('name', 'Enterprise')->where('interval', 'monthly')->firstOrFail()->tier)->toBe(3)
        ->and(SubscriptionPlan::where('name', 'Enterprise')->where('interval', 'yearly')->firstOrFail()->tier)->toBe(3);
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

    // And the opening offer carries the default, because nothing held it: this
    // table is empty at the start of every test, which is what a fresh install's
    // looks like. Without this the flag is left to the console on a new database
    // and a trial nobody has chosen a plan for resolves to *no* plan at all.
    // The half that matters just as much — that a held flag is never taken — is
    // pinned by the test below.
    expect(SubscriptionPlan::where('name', 'Entry')->firstOrFail()->is_default)->toBeTrue()
        ->and(SubscriptionPlan::where('is_default', true)->count())->toBe(1);
});

test('each tier is sold annually at twenty per cent off twelve months of it', function () {
    // The owner's "auto computed, in case admin changes the plans in the
    // future", pinned as arithmetic rather than as three magic numbers: every
    // annual price is `AnnualPricing` applied to the monthly row beside it, so
    // an admin re-pricing a tier underneath moves both and this test still
    // holds. Asserting the literals instead would pass just as well today and
    // would fail in the one case that matters — a discounted annual row that
    // had drifted from its monthly twin.
    $this->seed(SubscriptionPlanSeeder::class);

    $monthlies = SubscriptionPlan::where('interval', 'monthly')
        ->where('is_trial', false)
        ->orderBy('tier')
        ->get();

    expect($monthlies)->toHaveCount(3);

    foreach ($monthlies as $monthly) {
        $annual = SubscriptionPlan::where('interval', 'yearly')
            ->where('tier', $monthly->tier)
            ->firstOrFail();

        expect(Naira::koboFromStrict($annual->amount))
            ->toBe(AnnualPricing::annualKobo($monthly->amount))
            // And the discount is real: strictly cheaper than paying monthly for
            // a year, which is the only reason a customer would pick it.
            ->toBeLessThan(Naira::koboFromStrict($monthly->amount) * 12);

        // The twin grants exactly what its monthly sibling grants. A cadence is
        // a billing decision and not a feature, so anything else would be a
        // second ladder to keep in step.
        expect($annual->entitlements)->toBe($monthly->entitlements)
            ->and($annual->name)->toBe($monthly->name);
    }

    // The three published prices, stated once so the page's figures are pinned
    // somewhere a human reads them. Read off models rather than with a query
    // `pluck`, which skips the `decimal:2` cast and hands back the column's
    // string form.
    $prices = fn (string $interval): array => SubscriptionPlan::where('interval', $interval)
        ->orderBy('sort_order')
        ->get()
        ->mapWithKeys(fn (SubscriptionPlan $plan): array => [$plan->name => (float) $plan->amount])
        ->all();

    expect($prices('monthly'))->toBe(['Entry' => 5000.0, 'Growth' => 15000.0, 'Enterprise' => 50000.0])
        ->and($prices('yearly'))->toBe(['Entry' => 48000.0, 'Growth' => 144000.0, 'Enterprise' => 480000.0]);
});

test('the plan seeder deletes a plan it does not recognise, and restores the tiers', function () {
    // The reversal, stated as a test. This seeder used to leave every existing
    // row exactly as it was, which meant a catalogue that had accumulated
    // `Vanilla` and `Start up` beside the three tiers could never be put right by
    // re-seeding — the one thing an owner reasonably does when the plans page
    // looks wrong. It owns the table outright now, and this pins what that costs:
    // the console's plan is gone, a renamed tier is back under its seeded name,
    // and the default has returned to the entry tier.
    $this->seed(SubscriptionPlanSeeder::class);

    $entry = SubscriptionPlan::where('name', 'Entry')->firstOrFail();

    // Console work of every kind the previous seeder went out of its way to
    // respect: a plan of the admin's own, a moved default, and a renamed and
    // re-priced tier.
    $entry->update(['is_default' => false]);

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

    $entry->update(['name' => 'Starter', 'amount' => 7000]);

    $this->seed(SubscriptionPlanSeeder::class);

    expect(SubscriptionPlan::where('name', 'Agency Retainer')->exists())->toBeFalse()
        ->and($custom->fresh())->toBeNull()
        ->and(SubscriptionPlan::count())->toBe(6)
        // A rename is a delete and a re-create: there is no code to match a
        // renamed row on, because rows from an older seeder have none. The tier
        // therefore comes back under its seeded name, at its seeded price.
        ->and(SubscriptionPlan::where('name', 'Starter')->exists())->toBeFalse()
        ->and((float) SubscriptionPlan::where('name', 'Entry')->where('interval', 'monthly')->firstOrFail()->amount)->toBe(5000.0)
        // And the flag is claimed rather than respected, because the row that
        // held it no longer exists to respect.
        ->and(SubscriptionPlan::where('is_default', true)->count())->toBe(1)
        ->and(SubscriptionPlan::where('is_default', true)->value('name'))->toBe('Entry');
});

test('the plan seeder deletes the legacy rows the plans page was advertising', function () {
    // The report that prompted all of this: the marketing page listing five
    // plans. It reads `active()->where('is_trial', false)`, so these two were
    // live and on sale, and the seeder as it was could not take them off.
    $this->seed(SubscriptionPlanSeeder::class);

    foreach ([['Vanilla', 75000], ['Start up', 100000]] as [$name, $amount]) {
        SubscriptionPlan::create([
            'name' => $name,
            'amount' => $amount,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'interval_count' => 1,
            'is_active' => true,
            'is_trial' => false,
            'tier' => 1,
            'sort_order' => 1,
        ]);
    }

    expect(SubscriptionPlan::count())->toBe(8);

    $this->seed(SubscriptionPlanSeeder::class);

    expect(SubscriptionPlan::count())->toBe(6)
        // Ordered by insertion and not just by rank, because the twins share a
        // tier: `orderBy('tier')` alone leaves their order to the engine. The
        // monthly pass runs first, so the repeat arrives second within a rank.
        ->and(SubscriptionPlan::orderBy('tier')->orderBy('id')->pluck('name')->all())
        ->toBe(['Entry', 'Entry', 'Growth', 'Growth', 'Enterprise', 'Enterprise']);
});

test('a business on a plan the seeder removes loses its subscription with it', function () {
    // The cost of the wipe, pinned here so it is never discovered in production
    // instead. `subscriptions.subscription_plan_id` is `onDelete('cascade')`, so
    // this is what deleting a plan does to whoever was on it: the seeder does
    // not move them to `Entry` first, does not refuse, and logs what it took.
    // The admin console refuses exactly this operation — it tells an admin to
    // deactivate instead — which is worth knowing when reading the assertion
    // below rather than assuming it is a bug.
    $this->seed(SubscriptionPlanSeeder::class);

    [$owner] = createBusinessOwner(['is_verified' => true]);

    $legacy = SubscriptionPlan::create([
        'name' => 'Vanilla',
        'amount' => 75000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'tier' => 1,
        'sort_order' => 1,
    ]);

    $subscription = ws52Subscribe($owner, $legacy);

    $this->seed(SubscriptionPlanSeeder::class);

    expect(SubscriptionPlan::where('name', 'Vanilla')->exists())->toBeFalse()
        ->and(Subscription::find($subscription->id))->toBeNull()
        // The business itself is untouched: it is the plan that went, not the
        // account.
        ->and(Business::find($owner->business_id))->not->toBeNull();
});

test('the top tier states every entitlement the registry knows', function () {
    // The owner asked for the last tier to grant full access, and the shape
    // matters beyond the page: `Entitlements::planGranting()` answers "which
    // plan would give me this?" with the cheapest plan that grants the toggle,
    // so a key the top tier does not state sends an owner to the most expensive
    // plan on the platform for something it does not actually include.
    $this->seed(SubscriptionPlanSeeder::class);

    $enterprise = SubscriptionPlan::where('name', 'Enterprise')->firstOrFail();

    // Key by key rather than by a count, so a key renamed in the registry reads
    // as a failure here rather than as a plan that quietly stopped granting
    // things.
    expect(array_keys($enterprise->entitlements))
        ->toEqualCanonicalizing(EntitlementRegistry::keys());

    foreach (EntitlementRegistry::keys() as $key) {
        if (EntitlementRegistry::type($key) === EntitlementRegistry::TYPE_TOGGLE) {
            expect($enterprise->entitlements[$key])->toBeTrue();

            continue;
        }

        // Every limit left open. `null` is unlimited, and it is deliberate that
        // only this tier has it.
        expect($enterprise->entitlements[$key])->toBeNull();
    }
});

test('the pricing reconciliation puts the ladder back and leaves unknown rows alone', function () {
    // The defect: `firstOrCreate` cannot price a row that already exists, so an
    // `Enterprise` row from an older seeder kept its old price — ₦5,000 a month,
    // the same as `Entry` — while its entitlements were filled in around it. The
    // gate was right and the page that sells the top tier was wrong.
    $this->seed(SubscriptionPlanSeeder::class);

    // The monthly row explicitly: the tier now has two, and the reconciliation
    // this test drives is scoped to the monthly ladder.
    $enterprise = SubscriptionPlan::where('name', 'Enterprise')->where('interval', 'monthly')->firstOrFail();
    $enterprise->update([
        'amount' => 5000,
        'description' => 'Monthly subscription — full access to all Storify features',
        'features' => ['Create and manage your store'],
    ]);

    $stranger = SubscriptionPlan::create([
        'name' => 'Agency Retainer',
        'amount' => 120000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => false,
        'is_trial' => false,
        'tier' => 2,
        'sort_order' => 8,
        'features' => ['Whatever the agency agreed'],
        'entitlements' => [EntitlementRegistry::STORES => 99],
    ]);

    // Run the migration the way the deploy does. It returns an anonymous class
    // and performs its work in `up()`, so requiring the file is the whole of it.
    $migration = require database_path(
        'migrations/2026_10_14_000005_apply_seeded_tier_pricing_and_copy.php'
    );
    $migration->up();

    // The tier is priced from the seeder, and its copy with it — the page was
    // describing the top tier as smaller than the entry tier.
    expect((float) $enterprise->fresh()->amount)->toBe(50000.0)
        ->and($enterprise->fresh()->description)->toBe(SubscriptionPlanSeeder::TIERS[3]['description'])
        ->and($enterprise->fresh()->features)->toBe(SubscriptionPlanSeeder::TIERS[3]['features']);

    // The ladder, and the property the owner asked for: the top tier is
    // magnitudes above the entry tier rather than equal to it.
    expect((float) SubscriptionPlan::where('name', 'Entry')->where('interval', 'monthly')->firstOrFail()->amount)->toBe(5000.0)
        ->and((float) SubscriptionPlan::where('name', 'Growth')->where('interval', 'monthly')->firstOrFail()->amount)->toBe(15000.0);

    // A plan no tier recognises is not the platform's to re-price — an agency
    // plan is a console decision and this migration has no opinion about it.
    $stranger->refresh();
    expect((float) $stranger->amount)->toBe(120000.0)
        ->and($stranger->description)->toBeNull();

    // And it is a reconciliation, not a reconciler: a second run changes nothing
    // because by then there is nothing to put right.
    $before = $enterprise->fresh()->updated_at;
    $fresh = require database_path(
        'migrations/2026_10_14_000005_apply_seeded_tier_pricing_and_copy.php'
    );
    $fresh->up();

    expect($enterprise->fresh()->updated_at->toIso8601String())->toBe($before->toIso8601String());
});

test('the seeder is idempotent against a business that already runs on it', function () {
    // End to end: seed, subscribe a business, seed again, and the business's
    // entitlement is the one it bought. This doubles as the guard on the
    // seeder's sharpest edge — it deletes plans, and
    // `subscriptions.subscription_plan_id` cascades, so a re-seed that stopped
    // recognising `Growth` would silently take this business's plan with it.
    $this->seed(SubscriptionPlanSeeder::class);

    [$owner, $business] = createBusinessOwner(['is_verified' => true]);
    $growth = SubscriptionPlan::where('name', 'Growth')->firstOrFail();
    $subscription = ws52Subscribe($owner, $growth);

    ws52Stores($owner, 20);

    $this->seed(SubscriptionPlanSeeder::class);

    $refusal = StoreQuota::refusal($business->fresh());

    expect($refusal)->not->toBeNull()
        ->and($refusal['message'])->toContain('Growth');

    // The tier survived the wipe, so the subscription did too, and it is still
    // the row the business was sold rather than a replacement that happens to
    // carry the same name.
    expect($subscription->fresh())->not->toBeNull()
        ->and($subscription->fresh()->subscription_plan_id)->toBe($growth->id);

    expect(Business::find($business->id))->not->toBeNull();
});
