<?php

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\EarlyPass;
use App\Models\EarlyPassUsage;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;

/**
 * WS-11 — subscriptions, plans & early access (admin console).
 *
 * Covers the oversight list (filters, search, pagination, the revived trial
 * facet), the plan CRUD (trial fields that persist, exclusive default, the
 * kobo/naira money boundary, the guarded delete), the whole early-pass
 * lifecycle (create → redeem → exhaust → toggle → guarded delete → usage
 * history), the read-only contract legacy had, and the platform-admin /
 * permission boundaries.
 */
function ad11Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad11SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad11AdminWithRole(string $roleName): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole($roleName);

    return $user;
}

function ad11Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Starter',
        'amount' => '5000.00',
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => false,
        'is_trial' => false,
        'features' => ['1 store', 'POS'],
        'sort_order' => 0,
    ], $attributes));
}

function ad11Subscription(Business $business, ?SubscriptionPlan $plan = null, array $attributes = []): Subscription
{
    return Subscription::create(array_merge([
        'business_id' => $business->id,
        'user_id' => $business->user_id,
        'subscription_plan_id' => ($plan ?? ad11Plan())->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addMonth(),
    ], $attributes));
}

function ad11Pass(array $attributes = []): EarlyPass
{
    return EarlyPass::create(array_merge([
        'code' => strtoupper(fake()->unique()->bothify('EARLY####')),
        'description' => 'Launch cohort',
        'is_active' => true,
    ], $attributes));
}

function ad11Get(object $test, User $admin, string $path)
{
    return $test->getJson('/api/v1/admin/'.$path, ['Authorization' => 'Bearer '.ad11Token($admin)]);
}

function ad11Post(object $test, User $admin, string $path, array $payload = [])
{
    return $test->postJson('/api/v1/admin/'.$path, $payload, ['Authorization' => 'Bearer '.ad11Token($admin)]);
}

function ad11Put(object $test, User $admin, string $path, array $payload = [])
{
    return $test->putJson('/api/v1/admin/'.$path, $payload, ['Authorization' => 'Bearer '.ad11Token($admin)]);
}

function ad11Delete(object $test, User $admin, string $path)
{
    return $test->deleteJson('/api/v1/admin/'.$path, [], ['Authorization' => 'Bearer '.ad11Token($admin)]);
}

test('the subscription list is platform-wide, 15 a page, newest first, and searchable by business or plan', function () {
    $admin = ad11SuperAdmin();
    [$ownerA, $businessA] = createBusinessOwner(['name' => 'Ada Owner'], ['name' => 'Ada Stores']);
    [$ownerB, $businessB] = createBusinessOwner(['name' => 'Bola Owner'], ['name' => 'Bola Mart']);
    $planA = ad11Plan(['name' => 'Ada Plan']);
    $planB = ad11Plan(['name' => 'Bola Plan']);

    $older = ad11Subscription($businessA, $planA, ['starts_at' => now()->subDays(10)]);
    $newer = ad11Subscription($businessB, $planB, ['starts_at' => now()->subDay()]);

    $response = ad11Get($this, $admin, 'subscriptions');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 15)
        // latest('starts_at') first, like legacy.
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);

    $row = $response->json('data.0');
    expect($row['business']['name'])->toBe('Bola Mart')
        ->and($row['business']['business_code'])->toBe($businessB->business_code)
        ->and($row['plan']['name'])->toBe('Bola Plan')
        ->and($row['status'])->toBe('active')
        ->and($row['status_label'])->toBe('Active')
        ->and($row['is_active'])->toBeTrue()
        ->and($row['plan']['amount_kobo'])->toBe(500000)
        ->and($row['plan']['interval_label'])->toBe('monthly')
        ->and($row['started_with']['payment_skipped'])->toBeFalse();

    // q matches business name…
    $byBusiness = ad11Get($this, $admin, 'subscriptions?q=Ada%20Stores');
    $byBusiness->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $older->id);

    // …and plan name.
    $byPlan = ad11Get($this, $admin, 'subscriptions?q=Bola%20Plan');
    $byPlan->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $newer->id);

    // 16 rows pages 15 to a page.
    foreach (range(1, 14) as $index) {
        ad11Subscription($businessA, $planA, ['starts_at' => now()->subDays($index)]);
    }

    $paged = ad11Get($this, $admin, 'subscriptions');
    $paged->assertOk()->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2)->assertJsonCount(15, 'data');

    // Filters are validated, never passed through raw.
    ad11Get($this, $admin, 'subscriptions?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
    ad11Get($this, $admin, 'subscriptions?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

test('the status filter covers every row status and the legacy trial value is no longer dead', function () {
    $admin = ad11SuperAdmin();
    [, $business] = createBusinessOwner();
    $plan = ad11Plan();
    $trialPlan = ad11Plan(['name' => 'Free Trial', 'is_trial' => true, 'trial_days' => 14]);

    $active = ad11Subscription($business, $plan);
    $pending = ad11Subscription($business, $plan, ['status' => Subscription::STATUS_PENDING, 'starts_at' => null, 'expires_at' => null]);
    $suspended = ad11Subscription($business, $plan, ['status' => Subscription::STATUS_SUSPENDED]);
    $expired = ad11Subscription($business, $plan, ['status' => Subscription::STATUS_EXPIRED, 'expires_at' => now()->subMonth()]);
    $cancelled = ad11Subscription($business, $plan, ['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now()]);
    // Legacy's filter offered `trial`, but no row ever carried that status.
    // Here the value matches a trial-plan subscription or the metadata flag.
    $trial = ad11Subscription($business, $trialPlan);
    $flagged = ad11Subscription($business, $plan, ['metadata' => ['trial' => true]]);

    foreach (['active' => $active, 'pending' => $pending, 'suspended' => $suspended, 'expired' => $expired, 'cancelled' => $cancelled] as $status => $subscription) {
        $response = ad11Get($this, $admin, 'subscriptions?status='.$status);
        $response->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $subscription->id);
    }

    $trialResponse = ad11Get($this, $admin, 'subscriptions?status=trial');
    $trialResponse->assertOk()->assertJsonPath('meta.total', 2);
    expect(collect($trialResponse->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$trial->id, $flagged->id])->sort()->values()->all());

    $counts = ad11Get($this, $admin, 'subscriptions')->json('meta.status_counts');
    expect($counts['active'])->toBe(1)
        ->and($counts['pending'])->toBe(1)
        ->and($counts['suspended'])->toBe(1)
        ->and($counts['expired'])->toBe(1)
        ->and($counts['cancelled'])->toBe(1)
        ->and($counts['trial'])->toBe(2)
        ->and($counts['all'])->toBe(7)
        // Trial is a facet of its own — a trial row is never double-counted
        // under its row status, so every bucket sums to `all` and each pill
        // returns exactly the rows it advertises.
        ->and($counts['active'] + $counts['pending'] + $counts['suspended']
            + $counts['expired'] + $counts['cancelled'] + $counts['trial'])->toBe($counts['all']);
});

test('the subscription console is read-only, matching legacy', function () {
    $admin = ad11SuperAdmin();
    [, $business] = createBusinessOwner();
    $subscription = ad11Subscription($business);

    // No cancel/suspend/extend exists anywhere in legacy or here: the URI
    // only answers GET, and no per-subscription route exists at all.
    $this->postJson('/api/v1/admin/subscriptions', [], ['Authorization' => 'Bearer '.ad11Token($admin)])
        ->assertStatus(405);

    $this->deleteJson('/api/v1/admin/subscriptions/'.$subscription->id, [], ['Authorization' => 'Bearer '.ad11Token($admin)])
        ->assertStatus(404);

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

test('plans list exposes trial fields, subscription counters and delete-ability', function () {
    $admin = ad11SuperAdmin();
    [, $business] = createBusinessOwner();

    $busy = ad11Plan(['name' => 'Busy Plan', 'sort_order' => 1]);
    $free = ad11Plan(['name' => 'Idle Plan', 'sort_order' => 2, 'is_trial' => true, 'trial_days' => 7]);
    ad11Subscription($business, $busy, ['status' => Subscription::STATUS_ACTIVE]);
    ad11Subscription($business, $busy, ['status' => Subscription::STATUS_EXPIRED, 'expires_at' => now()->subMonth()]);

    $response = ad11Get($this, $admin, 'subscription-plans');
    $response->assertOk()->assertJsonPath('meta.total', 2);

    $busyRow = collect($response->json('data'))->firstWhere('name', 'Busy Plan');
    expect($busyRow['subscriptions_count'])->toBe(2)
        ->and($busyRow['active_subscriptions_count'])->toBe(1)
        ->and($busyRow['can_delete'])->toBeFalse()
        ->and($busyRow['plan_code'])->not->toBeNull();

    $freeRow = collect($response->json('data'))->firstWhere('name', 'Idle Plan');
    expect($freeRow['is_trial'])->toBeTrue()
        ->and($freeRow['trial_days'])->toBe(7)
        ->and($freeRow['can_delete'])->toBeTrue();

    // Legacy's trial fields were rendered but never persisted — they are
    // filterable and returned here.
    ad11Get($this, $admin, 'subscription-plans?q=Idle')->assertOk()->assertJsonPath('meta.total', 1);
    ad11Get($this, $admin, 'subscription-plans?status=inactive')->assertOk()->assertJsonPath('meta.total', 0);
});

test('a plan is created with kobo amounts, parsed features and persisted trial fields', function () {
    $admin = ad11SuperAdmin();

    $response = ad11Post($this, $admin, 'subscription-plans', [
        'name' => 'Growth',
        'description' => 'For growing teams',
        'amount_kobo' => 1500050,
        'currency' => 'ngn',
        'interval' => 'monthly',
        'interval_count' => 3,
        'is_active' => true,
        'is_default' => false,
        'is_trial' => true,
        'trial_days' => 14,
        'features' => [' 5 stores ', '', 'Priority support'],
        'sort_order' => 4,
    ]);

    $response->assertCreated()->assertJsonPath('data.plan.name', 'Growth');

    $plan = SubscriptionPlan::query()->where('name', 'Growth')->firstOrFail();
    expect((string) $plan->amount)->toBe('15000.50')
        ->and($plan->currency)->toBe('NGN')
        ->and($plan->interval_count)->toBe(3)
        ->and($plan->is_trial)->toBeTrue()
        ->and($plan->trial_days)->toBe(14)
        ->and($plan->features)->toBe(['5 stores', 'Priority support']);

    expect($response->json('data.plan.amount_kobo'))->toBe(1500050)
        ->and($response->json('data.plan.interval_label'))->toBe('monthly x3')
        ->and($response->json('data.plan.can_delete'))->toBeTrue();

    // Audit trail.
    expect(ActivityLog::query()->where('action', 'subscription_plan_created')->exists())->toBeTrue();

    // Validation failures.
    ad11Post($this, $admin, 'subscription-plans', ['currency' => 'NGN', 'interval' => 'monthly', 'interval_count' => 1, 'amount_kobo' => 100])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    ad11Post($this, $admin, 'subscription-plans', [
        'name' => 'Bad', 'amount' => 10, 'currency' => 'NGN', 'interval' => 'fortnightly', 'interval_count' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('interval');

    // is_trial without trial_days is refused — the legacy field must be real.
    ad11Post($this, $admin, 'subscription-plans', [
        'name' => 'Bad trial', 'amount' => 10, 'currency' => 'NGN', 'interval' => 'monthly', 'interval_count' => 1, 'is_trial' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('trial_days');

    // A trial template cannot become the platform default.
    ad11Post($this, $admin, 'subscription-plans', [
        'name' => 'Bad default', 'amount' => 10, 'currency' => 'NGN', 'interval' => 'monthly', 'interval_count' => 1,
        'is_trial' => true, 'trial_days' => 7, 'is_default' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('is_default');

    ad11Post($this, $admin, 'subscription-plans', [
        'name' => 'Priceless', 'currency' => 'NGN', 'interval' => 'monthly', 'interval_count' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors(['amount', 'amount_kobo']);
});

test('setting a plan default un-defaults every other plan and being default implies active', function () {
    $admin = ad11SuperAdmin();

    $first = ad11Plan(['name' => 'First', 'is_default' => true]);
    $second = ad11Plan(['name' => 'Second', 'is_default' => false]);

    $response = ad11Put($this, $admin, 'subscription-plans/'.$second->plan_code, [
        'name' => 'Second',
        'amount_kobo' => 900000,
        'currency' => 'NGN',
        'interval' => 'yearly',
        'interval_count' => 1,
        'is_active' => false,
        'is_default' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.plan.is_default', true)
        // A default plan that is inactive would break the active-default
        // consumers, so default implies active.
        ->assertJsonPath('data.plan.is_active', true)
        ->assertJsonPath('data.plan.amount_kobo', 900000);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and(SubscriptionPlan::query()->where('is_default', true)->count())->toBe(1);

    // A decimal naira amount normalises into the column; plan_code is immutable.
    $plain = ad11Plan(['name' => 'Plain']);
    $updated = ad11Put($this, $admin, 'subscription-plans/'.$plain->plan_code, [
        'name' => 'Plain renamed',
        'amount' => 99.5,
        'currency' => 'NGN',
        'interval' => 'weekly',
        'interval_count' => 2,
        'is_active' => true,
        'is_default' => false,
    ]);

    $updated->assertOk()->assertJsonPath('data.plan.name', 'Plain renamed');
    expect((string) $plain->fresh()->amount)->toBe('99.50')
        ->and($plain->fresh()->plan_code)->toBe($plain->plan_code)
        ->and($plain->fresh()->interval_count)->toBe(2);

    // The name stays required on update.
    ad11Put($this, $admin, 'subscription-plans/'.$plain->plan_code, [
        'amount' => 10, 'currency' => 'NGN', 'interval' => 'monthly', 'interval_count' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

test('deleting a plan is refused while live or in-flight subscriptions exist', function () {
    $admin = ad11SuperAdmin();
    [, $business] = createBusinessOwner();

    $activePlan = ad11Plan(['name' => 'Active sub plan']);
    ad11Subscription($business, $activePlan, ['status' => Subscription::STATUS_ACTIVE]);

    $pendingPlan = ad11Plan(['name' => 'Pending sub plan']);
    ad11Subscription($business, $pendingPlan, ['status' => Subscription::STATUS_PENDING, 'starts_at' => null, 'expires_at' => null]);

    ad11Delete($this, $admin, 'subscription-plans/'.$activePlan->plan_code)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete a plan with active subscriptions. Deactivate it instead.');

    ad11Delete($this, $admin, 'subscription-plans/'.$pendingPlan->plan_code)->assertStatus(422);

    expect(SubscriptionPlan::query()->whereKey($activePlan->id)->exists())->toBeTrue();

    // Historical (expired) subscriptions do not block deletion, matching the
    // legacy guard.
    $expiredPlan = ad11Plan(['name' => 'Historical plan']);
    ad11Subscription($business, $expiredPlan, ['status' => Subscription::STATUS_EXPIRED, 'expires_at' => now()->subMonth()]);

    ad11Delete($this, $admin, 'subscription-plans/'.$expiredPlan->plan_code)
        ->assertOk()
        ->assertJsonPath('message', 'Plan "Historical plan" deleted successfully.');

    expect(SubscriptionPlan::query()->whereKey($expiredPlan->id)->exists())->toBeFalse();
    expect(ActivityLog::query()->where('action', 'subscription_plan_deleted')->exists())->toBeTrue();
});

test('an early-access pass is created uppercased and duplicates are refused case-insensitively', function () {
    $admin = ad11SuperAdmin();

    $response = ad11Post($this, $admin, 'early-access', [
        'code' => ' earlybird2025 ',
        'description' => 'Launch cohort',
        'max_uses' => 25,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.pass.code', 'EARLYBIRD2025')
        ->assertJsonPath('data.pass.is_active', true)
        ->assertJsonPath('data.pass.is_available', true)
        ->assertJsonPath('data.pass.usage_label', '0 / 25')
        ->assertJsonPath('data.pass.can_delete', true);

    // The unique check runs on the stored (uppercase) shape.
    ad11Post($this, $admin, 'early-access', ['code' => 'EarlyBird2025'])
        ->assertStatus(422)->assertJsonValidationErrors('code');

    // Blank max_uses means unlimited.
    $unlimited = ad11Post($this, $admin, 'early-access', ['code' => 'FOREVER', 'max_uses' => null]);
    $unlimited->assertCreated()->assertJsonPath('data.pass.max_uses', null)->assertJsonPath('data.pass.remaining_uses', null);

    ad11Post($this, $admin, 'early-access', ['code' => 'ab'])->assertStatus(422)->assertJsonValidationErrors('code');
    ad11Post($this, $admin, 'early-access', ['code' => str_repeat('A', 51)])->assertStatus(422)->assertJsonValidationErrors('code');
    ad11Post($this, $admin, 'early-access', ['code' => 'VALID', 'max_uses' => 0])->assertStatus(422)->assertJsonValidationErrors('max_uses');

    expect(ActivityLog::query()->where('action', 'early_pass_created')->exists())->toBeTrue();
});

test('the pass list carries usage progress, filters and delete-ability', function () {
    $admin = ad11SuperAdmin();
    [$owner] = createBusinessOwner();

    $used = ad11Pass(['code' => 'USEDPASS', 'max_uses' => 3, 'description' => 'First cohort']);
    $unused = ad11Pass(['code' => 'FRESHPASS', 'description' => 'Second batch']);
    EarlyPassUsage::create(['early_pass_id' => $used->id, 'user_id' => $owner->id, 'used_at' => now()]);

    $response = ad11Get($this, $admin, 'early-access');
    $response->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 20);

    $usedRow = collect($response->json('data'))->firstWhere('code', 'USEDPASS');
    expect($usedRow['usage_count'])->toBe(1)
        ->and($usedRow['remaining_uses'])->toBe(2)
        ->and($usedRow['is_exhausted'])->toBeFalse()
        // Used passes cannot be deleted — the UI disables it up front instead
        // of round-tripping the server error (legacy quirk).
        ->and($usedRow['can_delete'])->toBeFalse();

    $freshRow = collect($response->json('data'))->firstWhere('code', 'FRESHPASS');
    expect($freshRow['can_delete'])->toBeTrue();

    ad11Get($this, $admin, 'early-access?q=cohort')->assertOk()->assertJsonPath('meta.total', 1);
    ad11Get($this, $admin, 'early-access?status=inactive')->assertOk()->assertJsonPath('meta.total', 0);
    ad11Get($this, $admin, 'early-access?status=active')->assertOk()->assertJsonPath('meta.total', 2);
});

test('the toggle encodes the exhausted semantics the legacy menu hid', function () {
    $admin = ad11SuperAdmin();
    [$owner] = createBusinessOwner();
    $pass = ad11Pass(['code' => 'ONEUSE', 'max_uses' => 1]);

    // Redeeming the only slot auto-deactivates the pass (model markAsUsed).
    $pass->markAsUsed($owner->id);
    expect($pass->fresh()->is_active)->toBeFalse();

    // An operator can flip it back on, but it stays unredeemable — the
    // response says so explicitly (legacy's active-but-dead state).
    $response = ad11Post($this, $admin, 'early-access/ONEUSE/toggle-status');
    $response->assertOk()
        ->assertJsonPath('data.pass.is_active', true)
        ->assertJsonPath('data.pass.is_available', false)
        ->assertJsonPath('data.pass.is_exhausted', true);
    expect($response->json('message'))->toContain('exhausted');

    $off = ad11Post($this, $admin, 'early-access/ONEUSE/toggle-status');
    $off->assertOk()->assertJsonPath('data.pass.is_active', false)->assertJsonPath('data.pass.is_available', false);
    expect($off->json('message'))->toBe('Pass has been deactivated.');

    $on = ad11Pass(['code' => 'PLENTY', 'max_uses' => 5, 'is_active' => false]);
    $activated = ad11Post($this, $admin, 'early-access/'.$on->code.'/toggle-status');
    $activated->assertOk()->assertJsonPath('data.pass.is_active', true)->assertJsonPath('data.pass.is_available', true);
    expect($activated->json('message'))->toBe('Pass has been activated.');

    expect(ActivityLog::query()->where('action', 'early_pass_status_toggled')->exists())->toBeTrue();
});

test('a pass edit changes description and cap but never the code, and lowering the cap deactivates', function () {
    $admin = ad11SuperAdmin();
    [$owner] = createBusinessOwner();
    $pass = ad11Pass(['code' => 'EDITABLE', 'max_uses' => 5]);
    EarlyPassUsage::create(['early_pass_id' => $pass->id, 'user_id' => $owner->id, 'used_at' => now()]);

    $response = ad11Put($this, $admin, 'early-access/EDITABLE', [
        'code' => 'EDITABLE',
        'description' => 'Updated note',
        'max_uses' => 1,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.pass.code', 'EDITABLE')
        ->assertJsonPath('data.pass.description', 'Updated note')
        ->assertJsonPath('data.pass.max_uses', 1)
        // usage (1) now meets the cap, so the pass deactivates rather than
        // sitting active-but-unredeemable.
        ->assertJsonPath('data.pass.is_active', false)
        ->assertJsonPath('data.pass.is_exhausted', true);

    // The code is immutable; a changed value is refused, not silently ignored.
    ad11Put($this, $admin, 'early-access/EDITABLE', ['code' => 'RENAMED'])
        ->assertStatus(422)->assertJsonValidationErrors('code');

    // Raising the cap on an auto-deactivated pass does not silently reactivate it.
    $raised = ad11Put($this, $admin, 'early-access/EDITABLE', ['description' => 'Updated note', 'max_uses' => 10]);
    $raised->assertOk()->assertJsonPath('data.pass.is_active', false)->assertJsonPath('data.pass.is_available', false);
});

test('a used pass cannot be deleted and an unused one can', function () {
    $admin = ad11SuperAdmin();
    [$owner] = createBusinessOwner();

    $used = ad11Pass(['code' => 'KEEPIT']);
    EarlyPassUsage::create(['early_pass_id' => $used->id, 'user_id' => $owner->id, 'used_at' => now()]);

    ad11Delete($this, $admin, 'early-access/KEEPIT')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete a used pass. Deactivate it instead.');

    expect(EarlyPass::query()->where('code', 'KEEPIT')->exists())->toBeTrue();

    $unused = ad11Pass(['code' => 'GONE']);
    ad11Delete($this, $admin, 'early-access/GONE')
        ->assertOk()
        ->assertJsonPath('message', 'Pass deleted successfully.');

    expect(EarlyPass::query()->where('code', 'GONE')->exists())->toBeFalse();
    expect(ActivityLog::query()->where('action', 'early_pass_deleted')->exists())->toBeTrue();
});

test('the pass detail returns who redeemed it, on which store and when', function () {
    $admin = ad11SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = Store::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'name' => 'Main Branch']);

    $pass = ad11Pass(['code' => 'TRACKME', 'max_uses' => 10, 'description' => 'Tracked']);
    EarlyPassUsage::create([
        'early_pass_id' => $pass->id,
        'user_id' => $owner->id,
        'store_id' => $store->id,
        'used_at' => now()->subHour(),
    ]);

    $response = ad11Get($this, $admin, 'early-access/TRACKME');

    $response->assertOk()
        ->assertJsonPath('data.pass.code', 'TRACKME')
        ->assertJsonPath('data.pass.usage_count', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.usages.0.user.name', $owner->name)
        ->assertJsonPath('data.usages.0.user.email', $owner->email)
        ->assertJsonPath('data.usages.0.business.name', $business->name)
        ->assertJsonPath('data.usages.0.business.business_code', $business->business_code)
        ->assertJsonPath('data.usages.0.store.name', 'Main Branch')
        ->assertJsonPath('data.usages.0.store.store_id', $store->store_id);

    expect($response->json('data.usages.0.used_at'))->not->toBeNull();

    // A redemption by a user with no business renders an explicit null for
    // the business rather than a crash.
    EarlyPassUsage::create(['early_pass_id' => $pass->id, 'user_id' => User::factory()->create(['account_code' => 'UNKNOWN01'])->id, 'used_at' => now()]);
    ad11Get($this, $admin, 'early-access/TRACKME')->assertOk()->assertJsonPath('data.pass.usage_count', 2);

    ad11Get($this, $admin, 'early-access/NOSUCHPASS')->assertStatus(404);
});

test('only platform accounts can reach the subscription and early-access consoles', function () {
    // A business-scoped owner: its in-business "Super Admin" role carries the
    // full permission bundle, admin.* names included, so the platform guard —
    // not the route permission — is what stops a leaked admin-audience token.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);
    expect($owner->can('admin.subscriptions'))->toBeTrue();

    $this->getJson('/api/v1/admin/subscriptions', [
        'Authorization' => 'Bearer '.$owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);

    $this->getJson('/api/v1/admin/early-access', [
        'Authorization' => 'Bearer '.$owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);

    // A management token cannot cross audiences.
    $this->getJson('/api/v1/admin/subscriptions', [
        'Authorization' => 'Bearer '.$owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);

    // An admin account without the permission is refused by the route gate.
    $permissionless = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    ad11Get($this, $permissionless, 'subscriptions')->assertStatus(403);
    ad11Get($this, $permissionless, 'early-access')->assertStatus(403);

    // Finance Admin carries admin.subscriptions but not admin.businesses.
    $finance = ad11AdminWithRole('Finance Admin');
    ad11Get($this, $finance, 'subscriptions')->assertOk();
    ad11Get($this, $finance, 'subscription-plans')->assertOk();
    ad11Get($this, $finance, 'early-access')->assertStatus(403);

    // Platform Admin carries both.
    $platform = ad11AdminWithRole('Platform Admin');
    ad11Get($this, $platform, 'subscriptions')->assertOk();
    ad11Get($this, $platform, 'early-access')->assertOk();

    $this->getJson('/api/v1/admin/subscriptions')->assertStatus(401);
});

test('opening the subscription console is itself audited', function () {
    $admin = ad11SuperAdmin();

    ad11Get($this, $admin, 'subscriptions')->assertOk();

    $entry = ActivityLog::query()->where('action', 'admin_route_accessed')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($admin->id)
        ->and($entry->metadata['route'] ?? null)->toBe('api.admin.subscriptions.index');
});
