<?php

use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Mail\UserPasswordResetMail;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Impersonation;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-8 — User moderation completion (admin console)
|--------------------------------------------------------------------------
| Covers the directory (legacy default role, has_business / subscription /
| status / verified / search filters, plan column and stats block), the detail
| console (business metrics, subscription + payments, last IP, activity feed),
| the edit with its audit row, suspend (required reason, main-store guard,
| already-suspended warning, email), activate (legacy default reason, KYC
| auto-approval, reactivation email), verify/unverify auditing, the admin
| password reset with its mail-failure fallback, the guarded soft delete and
| restore, the impersonation hand-off contract and stop, and the
| platform-role / audience / permission refusals.
*/

function ad08Token(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad08SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad08PlatformAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $admin->assignRole('Platform Admin');

    return $admin;
}

function ad08Store(User $owner, Business $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'AD08 Store',
        'slug' => 'ad08-store-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad08Order(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'AD08-ORD-'.$sequence,
        'subtotal' => 1000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ad08Transaction(Order $order, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'AD08-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => 1000,
        'status' => 'pending',
    ], $attributes));
}

function ad08Get(object $test, User $admin, string $path)
{
    return $test->getJson('/api/v1/admin/'.$path, ['Authorization' => 'Bearer '.ad08Token($admin)]);
}

function ad08Post(object $test, User $admin, string $path, array $payload = [])
{
    return $test->postJson('/api/v1/admin/'.$path, $payload, ['Authorization' => 'Bearer '.ad08Token($admin)]);
}

test('the user directory defaults to owners and exposes the legacy filters and stats', function () {
    $admin = ad08SuperAdmin();
    [$owner, $business] = createBusinessOwner(['name' => 'Owner One', 'phone' => '08030000001']);
    [$staff] = createBusinessOwner(['role' => 'staff', 'name' => 'Staff One', 'business_id' => $business->id]);
    [$soloOwner] = createBusinessOwner(['name' => 'Solo Owner']);
    $soloOwner->update(['business_id' => null]);

    $suspended = User::factory()->create(['role' => 'staff', 'status' => 'suspended', 'is_verified' => false]);

    // No role given → legacy defaulted the list to owners.
    $response = ad08Get($this, $admin, 'users')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 20);

    $roles = collect($response->json('data'))->pluck('role')->unique()->values()->all();
    expect($roles)->toBe(['business_owner'])
        ->and($response->json('meta.stats'))->toMatchArray([
            'owners' => 2,
            'staff' => 2,
            'suspended' => 1,
            'unverified' => 1,
        ]);

    // `role=all` hands back every managed role; platform admins stay out.
    $all = ad08Get($this, $admin, 'users?role=all')->assertOk();
    expect(collect($all->json('data'))->pluck('role')->unique()->sort()->values()->all())
        ->toBe(['business_owner', 'staff']);

    // has_business=no finds the owner whose setup never completed.
    $noBusiness = ad08Get($this, $admin, 'users?role=all&has_business=no')->assertOk();
    expect(collect($noBusiness->json('data'))->pluck('account_code')->all())->toBe([$soloOwner->account_code]);

    // Free text matches phone and account_code, not just name/email.
    $byPhone = ad08Get($this, $admin, 'users?role=all&q=08030000001')->assertOk();
    expect(collect($byPhone->json('data'))->pluck('email')->all())->toBe([$owner->email]);
    expect(collect($byPhone->json('data'))->pluck('email')->all())->toBe([$owner->email]);

    // A search with no role chosen spans every managed role, so the command
    // palette and dashboard deep links cannot hide a staff member.
    $searchAll = ad08Get($this, $admin, 'users?q=Staff%20One')->assertOk();
    expect(collect($searchAll->json('data'))->pluck('role')->all())->toBe(['staff']);

    $byCode = ad08Get($this, $admin, 'users?role=all&q='.$suspended->account_code)->assertOk();
    expect(collect($byCode->json('data'))->pluck('id')->all())->toBe([$suspended->id]);

    // Status + verified filters.
    $suspendedOnly = ad08Get($this, $admin, 'users?role=all&status=suspended')->assertOk();
    expect(collect($suspendedOnly->json('data'))->pluck('id')->all())->toBe([$suspended->id]);

    $unverified = ad08Get($this, $admin, 'users?role=all&verified=0')->assertOk();
    expect(collect($unverified->json('data'))->pluck('id')->all())->toBe([$suspended->id]);
});

test('the user directory filters by subscription state and shows a plan column', function () {
    $admin = ad08SuperAdmin();
    [$onPlan, $planBusiness] = createBusinessOwner();
    [$trial] = createBusinessOwner();
    [$none] = createBusinessOwner();

    $trial->update(['trial_ends_at' => now()->addDays(5)]);

    $plan = SubscriptionPlan::create([
        'name' => 'Growth Monthly',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
    ]);

    Subscription::create([
        'business_id' => $planBusiness->id,
        'user_id' => $onPlan->id,
        'subscription_plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addMonth(),
    ]);

    $onPlanRow = collect(ad08Get($this, $admin, 'users?role=all&subscription=active')->json('data'));
    expect($onPlanRow->pluck('id')->all())->toBe([$onPlan->id])
        ->and($onPlanRow->first()['plan'])->toBe('Growth Monthly');

    $trialRow = collect(ad08Get($this, $admin, 'users?role=all&subscription=trial')->json('data'));
    expect($trialRow->pluck('id')->all())->toBe([$trial->id])
        ->and($trialRow->first()['plan'])->toBe('Trial');

    $noPlanRows = collect(ad08Get($this, $admin, 'users?role=all&subscription=none')->json('data'));
    expect($noPlanRows->pluck('id')->sort()->values()->all())
        ->toBe(collect([$none->id])->sort()->values()->all())
        ->and($noPlanRows->first()['plan'])->toBe('None');

    // Filters are validated, not passed through.
    ad08Get($this, $admin, 'users?subscription=bogus')->assertStatus(422)->assertJsonValidationErrors('subscription');
    ad08Get($this, $admin, 'users?role=superadmin')->assertStatus(422)->assertJsonValidationErrors('role');
    ad08Get($this, $admin, 'users?sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
});

test('the user console payload carries the account, business, subscription and activity blocks', function () {
    $admin = ad08SuperAdmin();
    [$owner, $business] = createBusinessOwner(['location' => 'Lagos', 'ip_address' => '10.0.0.9']);
    $store = ad08Store($owner, $business, ['name' => 'Main Outlet']);
    ad08Store($owner, $business, ['name' => 'Retired', 'status' => Store::STATUS_DELETED]);

    $order = ad08Order($store, ['status' => 'completed']);
    Payment::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'reference' => 'AD08-PAY-1',
        'amount' => 5000,
        'currency' => 'NGN',
        'status' => 'success',
    ]);

    ActivityLog::create([
        'user_id' => $admin->id,
        'business_id' => $business->id,
        'action' => 'user_updated',
        'subject_type' => User::class,
        'subject_id' => $owner->id,
        'description' => 'Updated user',
    ]);

    $response = ad08Get($this, $admin, 'users/'.$owner->account_code)->assertOk();

    $response
        ->assertJsonPath('data.user.location', 'Lagos')
        ->assertJsonPath('data.user.ip_address', '10.0.0.9')
        ->assertJsonPath('data.user.force_password_change', false)
        ->assertJsonPath('data.user.business_detail.name', $business->name)
        ->assertJsonPath('data.user.business_detail.business_code', $business->business_code)
        ->assertJsonPath('data.user.business_detail.stores_count', 1)
        ->assertJsonPath('data.user.business_detail.orders_count', 1)
        ->assertJsonPath('data.user.business_detail.team_count', 1)
        ->assertJsonPath('data.user.business_detail.stores.0.name', 'Main Outlet')
        ->assertJsonPath('data.user.payments.0.reference', 'AD08-PAY-1')
        ->assertJsonPath('data.user.activity.0.action', 'user_updated')
        ->assertJsonPath('data.user.active_impersonation', null);

    // The deleted store is not part of what the business runs today.
    expect(collect($response->json('data.user.business_detail.stores'))->pluck('name')->all())->toBe(['Main Outlet'])
        ->and($business->name !== null)->toBeTrue();

    // The console is not reachable for platform-admin accounts (legacy 404s).
    $platform = ad08PlatformAdmin();
    ad08Get($this, $admin, 'users/'.$platform->account_code)->assertStatus(404);
});

test('editing a user requires the legacy fields and writes an audit row with old and new values', function () {
    $admin = ad08SuperAdmin();
    [$owner] = createBusinessOwner(['name' => 'Before Name', 'phone' => '0801']);

    $this->putJson('/api/v1/admin/users/'.$owner->account_code, ['phone' => '0802'], [
        'Authorization' => 'Bearer '.ad08Token($admin),
    ])->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);

    $this->putJson('/api/v1/admin/users/'.$owner->account_code, [
        'name' => 'After Name',
        'email' => $owner->email,
        'phone' => '0803',
    ], ['Authorization' => 'Bearer '.ad08Token($admin)])->assertOk();

    expect($owner->fresh()->name)->toBe('After Name');

    $log = ActivityLog::query()->where('action', 'user_updated')->where('subject_id', $owner->id)->firstOrFail();
    expect($log->old_values['name'])->toBe('Before Name')
        ->and($log->new_values['name'])->toBe('After Name')
        ->and($log->new_values['phone'])->toBe('0803');
});

test('suspending a user needs a reason, refuses the main store and warns when already suspended', function () {
    Mail::fake();

    $admin = ad08SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad08Store($owner, $business);
    Setting::create(['main_store_id' => $store->id]);

    // Reason is required again (the previous API accepted none).
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/suspend', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    // The main-store owner is protected.
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/suspend', ['reason' => 'Policy review'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This user owns the main store and cannot be suspended.');

    expect($owner->fresh()->status)->toBe('active')
        ->and(ActivityLog::query()->where('action', 'user_suspend_blocked')->exists())->toBeTrue();

    // A different owner suspends with the reason emailed and audited.
    [$other, $otherBusiness] = createBusinessOwner();
    $response = ad08Post($this, $admin, 'users/'.$other->account_code.'/suspend', ['reason' => 'Chargeback abuse'])
        ->assertOk()
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.notified', true);

    expect($other->fresh()->status)->toBe('suspended');

    Mail::assertQueued(BusinessSuspended::class, fn ($mail) => $mail->hasTo($other->email) && $mail->reason === 'Chargeback abuse');

    $log = ActivityLog::query()->where('action', 'user_suspended')->where('subject_id', $other->id)->firstOrFail();
    expect($log->old_values['status'])->toBe('active')
        ->and($log->new_values['status'])->toBe('suspended')
        ->and($log->new_values['reason'])->toBe('Chargeback abuse');

    // Already suspended is a warning, not a second write.
    ad08Post($this, $admin, 'users/'.$other->account_code.'/suspend', ['reason' => 'Again'])
        ->assertOk()
        ->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.warning', 'User is already suspended.');

    Mail::assertQueuedCount(1);
});

test('activating a user keeps the legacy default reason, auto-approves KYC and emails the user', function () {
    Mail::fake();

    $admin = ad08SuperAdmin();
    [$owner, $business] = createBusinessOwner(['status' => 'suspended']);

    $application = new KycApplication;
    $application->forceFill([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'status' => KycApplication::STATUS_SUBMITTED,
        'legal_name' => 'Ada Obi',
        'submitted_at' => now()->subDay(),
    ])->save();

    $response = ad08Post($this, $admin, 'users/'.$owner->account_code.'/activate')->assertOk();

    $response->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.kyc_approved', true)
        ->assertJsonPath('data.notified', true);

    expect($owner->fresh()->status)->toBe('active');

    $application->refresh();
    expect($application->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($application->reviewed_by)->toBe($admin->id)
        ->and($application->review_notes)->toBe('Auto-approved during user activation: Reactivated by admin')
        ->and($application->approved_at)->not->toBeNull();

    Mail::assertQueued(BusinessReactivated::class, fn ($mail) => $mail->reason === 'Reactivated by admin');

    $log = ActivityLog::query()->where('action', 'user_activated')->where('subject_id', $owner->id)->firstOrFail();
    expect($log->old_values['status'])->toBe('suspended')
        ->and($log->new_values['status'])->toBe('active')
        ->and($log->new_values['reason'])->toBe('Reactivated by admin');

    // Already active is a warning; deleted users go through restore instead.
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/activate')
        ->assertOk()
        ->assertJsonPath('data.changed', false);

    $owner->update(['status' => 'deleted']);
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/activate')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This user is deleted. Restore the account instead.');
});

test('verify and unverify write their audit rows', function () {
    $admin = ad08SuperAdmin();
    [$owner] = createBusinessOwner(['is_verified' => false]);
    $owner->forceFill(['email_verified_at' => null])->save();

    ad08Post($this, $admin, 'users/'.$owner->account_code.'/verify')->assertOk();
    expect($owner->fresh()->is_verified)->toBeTrue()
        ->and($owner->fresh()->email_verified_at)->not->toBeNull();

    ad08Post($this, $admin, 'users/'.$owner->account_code.'/unverify')->assertOk();
    expect($owner->fresh()->is_verified)->toBeFalse()
        ->and($owner->fresh()->email_verified_at)->toBeNull();

    expect(ActivityLog::query()->where('action', 'user_verified')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'user_unverified')->exists())->toBeTrue();
});

test('resetting a password emails a temporary password and forces a change', function () {
    Mail::fake();

    $admin = ad08SuperAdmin();
    [$owner] = createBusinessOwner();

    $response = ad08Post($this, $admin, 'users/'.$owner->account_code.'/reset-password')->assertOk();

    $response->assertJsonPath('data.emailed', true);

    $fresh = $owner->fresh();
    expect($fresh->force_password_change)->toBeTrue()
        ->and($fresh->password)->not->toBe($owner->password);

    Mail::assertQueued(UserPasswordResetMail::class, function (UserPasswordResetMail $mail) use ($owner) {
        return $mail->hasTo($owner->email)
            && preg_match('/^[A-Z0-9]{4}-[a-z0-9]{4}-\d{4}$/', $mail->temporaryPassword) === 1;
    });

    // The temporary password never lands in the audit row.
    $log = ActivityLog::query()->where('action', 'user_password_reset')->firstOrFail();
    expect(json_encode($log->metadata).json_encode($log->new_values))->not->toContain('temporary');
    expect($response->json('data'))->not->toHaveKey('temporary_password');
});

test('a reset whose mail cannot be queued hands the temporary password back to the admin', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $admin = ad08SuperAdmin();
    [$owner] = createBusinessOwner();

    $response = ad08Post($this, $admin, 'users/'.$owner->account_code.'/reset-password')->assertOk();

    $response->assertJsonPath('data.emailed', false);
    expect($response->json('data.temporary_password'))->toMatch('/^[A-Z0-9]{4}-[a-z0-9]{4}-\d{4}$/');
    expect($response->json('message'))->toContain('could not be queued');
});

test('deleting a user is refused while the account holds the main store or open work', function () {
    $admin = ad08SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $store = ad08Store($owner, $business);

    // Main store guard.
    Setting::create(['main_store_id' => $store->id]);
    $this->deleteJson('/api/v1/admin/users/'.$owner->account_code, [], ['Authorization' => 'Bearer '.ad08Token($admin)])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This user owns the main store and cannot be deleted.');

    // Open order guard.
    Setting::query()->delete();
    $order = ad08Order($store, ['status' => 'processing']);
    $this->deleteJson('/api/v1/admin/users/'.$owner->account_code, [], ['Authorization' => 'Bearer '.ad08Token($admin)])
        ->assertStatus(422)
        ->assertJsonPath('message', "Deletion rejected: {$owner->name} has stores with incomplete orders.");

    // Open transaction guard.
    $order->update(['status' => 'completed']);
    ad08Transaction($order, ['status' => 'pending']);
    $this->deleteJson('/api/v1/admin/users/'.$owner->account_code, [], ['Authorization' => 'Bearer '.ad08Token($admin)])
        ->assertStatus(422)
        ->assertJsonPath('message', "Deletion rejected: {$owner->name} has stores with incomplete transactions.");

    // With everything settled the delete is a soft status change plus audit.
    Transaction::query()->update(['status' => 'confirmed']);
    $this->deleteJson('/api/v1/admin/users/'.$owner->account_code, [], ['Authorization' => 'Bearer '.ad08Token($admin)])
        ->assertOk();

    expect($owner->fresh()->status)->toBe('deleted')
        ->and(User::query()->whereKey($owner->id)->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'user_deleted')->exists())->toBeTrue();
});

test('a deleted user can be listed, filtered and restored', function () {
    $admin = ad08SuperAdmin();
    [$owner] = createBusinessOwner(['status' => 'deleted']);

    $listed = ad08Get($this, $admin, 'users?role=all&status=deleted')->assertOk();
    expect(collect($listed->json('data'))->pluck('id')->all())->toBe([$owner->id]);

    ad08Post($this, $admin, 'users/'.$owner->account_code.'/restore')->assertOk();
    expect($owner->fresh()->status)->toBe('active');

    $log = ActivityLog::query()->where('action', 'user_restored')->firstOrFail();
    expect($log->old_values['status'])->toBe('deleted')
        ->and($log->new_values['status'])->toBe('active');

    // Restoring an active user is a warning, not a second transition.
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/restore')
        ->assertOk()
        ->assertJsonPath('data.changed', false);
});

test('impersonation issues a hand-off contract, logs it, and can be stopped from the console', function () {
    $admin = ad08SuperAdmin();
    [$owner] = createBusinessOwner();

    app('auth')->forgetGuards();

    $response = ad08Post($this, $admin, 'users/'.$owner->account_code.'/impersonate')->assertOk();

    $response->assertJsonPath('data.user.id', $owner->id)
        ->assertJsonPath('data.impersonation.impersonator.name', $admin->name);

    $impersonationId = $response->json('data.impersonation.id');
    $accessToken = $response->json('data.access_token');

    // The hand-off payload decodes to the same pair the response carries.
    $decoded = json_decode(base64_decode(strtr($response->json('data.handoff.encoded'), '-_', '+/')), true);
    expect($response->json('data.handoff.fragment'))->toBe('impersonation')
        ->and($decoded['access_token'])->toBe($accessToken)
        ->and($decoded['impersonation_id'])->toBe($impersonationId);

    $log = ActivityLog::query()->where('action', 'user_impersonated')->firstOrFail();
    expect($log->subject_id)->toBe($owner->id)
        ->and($log->metadata['impersonation_id'])->toBe($impersonationId);

    // The issued pair lands in the management app as the impersonated user.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/management/auth/me', ['Authorization' => 'Bearer '.$accessToken])
        ->assertOk()
        ->assertJsonPath('data.impersonator.name', $admin->name);

    // The detail console surfaces the live session.
    ad08Get($this, $admin, 'users/'.$owner->account_code)
        ->assertOk()
        ->assertJsonPath('data.user.active_impersonation.id', $impersonationId);

    // Stopping ends it, revokes the handed-off token and audits the stop.
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/stop-impersonation', ['impersonation_id' => $impersonationId])
        ->assertOk();

    expect(Impersonation::query()->find($impersonationId)->ended_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('action', 'user_impersonation_stopped')->exists())->toBeTrue();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/management/auth/me', ['Authorization' => 'Bearer '.$accessToken])->assertUnauthorized();

    // Nothing left to stop.
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/stop-impersonation')->assertStatus(404);
});

test('the management app stop endpoint writes the user_impersonation_stopped audit row', function () {
    $admin = ad08SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    app('auth')->forgetGuards();

    $response = ad08Post($this, $admin, 'users/'.$owner->account_code.'/impersonate')->assertOk();
    $accessToken = $response->json('data.access_token');
    $impersonationId = $response->json('data.impersonation.id');

    // Legacy wrote this row from the stop control, and the management banner's
    // "Return to Admin" is the primary exit path — the row lives on the route
    // the banner calls.
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/management/auth/stop-impersonation', [], ['Authorization' => 'Bearer '.$accessToken])
        ->assertOk()
        ->assertJsonPath('data.user.role', User::ROLE_SUPERADMIN);

    $log = ActivityLog::query()->where('action', 'user_impersonation_stopped')->firstOrFail();
    expect($log->user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($owner->id)
        ->and($log->business_id)->toBe($business->id)
        ->and($log->metadata['impersonation_id'])->toBe($impersonationId);

    // The handed-off token is revoked, so the ended session cannot act.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/management/auth/me', ['Authorization' => 'Bearer '.$accessToken])->assertUnauthorized();

    // Nothing left to stop.
    $this->postJson('/api/v1/management/auth/stop-impersonation', [], ['Authorization' => 'Bearer '.$accessToken])
        ->assertUnauthorized();
});

test('impersonation refuses yourself, deleted users and admin accounts', function () {
    $admin = ad08SuperAdmin();
    [$owner] = createBusinessOwner(['status' => 'deleted']);

    // Platform admin accounts are outside the managed roles entirely (404),
    // which is also what makes "impersonate yourself" unreachable.
    ad08Post($this, $admin, 'users/'.$admin->account_code.'/impersonate')->assertStatus(404);
    ad08Post($this, $admin, 'users/'.$owner->account_code.'/impersonate')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Deleted users cannot be impersonated.');

    $platform = ad08PlatformAdmin();
    ad08Post($this, $admin, 'users/'.$platform->account_code.'/impersonate')->assertStatus(404);
});

test('staff rows reach the same moderation actions as owners', function () {
    Mail::fake();

    $admin = ad08SuperAdmin();
    [$staff] = createBusinessOwner(['role' => 'staff']);

    ad08Post($this, $admin, 'users/'.$staff->account_code.'/suspend', ['reason' => 'Policy breach'])->assertOk();
    expect($staff->fresh()->status)->toBe('suspended');

    ad08Post($this, $admin, 'users/'.$staff->account_code.'/activate')->assertOk();
    expect($staff->fresh()->status)->toBe('active');

    ad08Post($this, $admin, 'users/'.$staff->account_code.'/reset-password')->assertOk();
    expect($staff->fresh()->force_password_change)->toBeTrue();

    ad08Post($this, $admin, 'users/'.$staff->account_code.'/impersonate')->assertOk();

    $this->deleteJson('/api/v1/admin/users/'.$staff->account_code, [], ['Authorization' => 'Bearer '.ad08Token($admin)])
        ->assertOk();
    expect($staff->fresh()->status)->toBe('deleted');

    ad08Post($this, $admin, 'users/'.$staff->account_code.'/restore')->assertOk();
    expect($staff->fresh()->status)->toBe('active');
});

test('user moderation refuses business-scoped, unpermitted, wrong-audience and guest callers', function () {
    // A business-scoped account whose in-business role bundles the admin.*
    // permission names still cannot moderate platform users.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.users'))->toBeTrue();

    ad08Get($this, $owner, 'users')->assertStatus(403);
    ad08Post($this, $owner, 'users/'.$owner->account_code.'/suspend', ['reason' => 'nope'])->assertStatus(403);
    ad08Post($this, $owner, 'users/'.$owner->account_code.'/impersonate')->assertStatus(403);

    // An admin account without the permission is stopped by the gate.
    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
    ad08Get($this, $plainAdmin, 'users')->assertStatus(403);

    // A management-audience token never reaches an admin route.
    [$other] = createBusinessOwner();
    $managementToken = $other->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
    $this->getJson('/api/v1/admin/users', ['Authorization' => 'Bearer '.$managementToken])->assertStatus(403);

    // A platform admin holding the seeded role passes.
    $platformAdmin = ad08PlatformAdmin();
    ad08Get($this, $platformAdmin, 'users')->assertOk();

    // Guests are unauthenticated.
    $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    $this->postJson('/api/v1/admin/users/'.$other->account_code.'/suspend', ['reason' => 'x'])->assertUnauthorized();
});
