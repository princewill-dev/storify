<?php

use App\Mail\AdminInvitationSpaMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| WS-10 — Admin accounts & invitations (admin console)
|--------------------------------------------------------------------------
| Covers the directory (roles, pending/active status, stats, search + status
| filters, self/protected flags), the assignable-platform-roles endpoint, the
| invite (cross-table email uniqueness, platform-role-only assignment, mail
| queue, audit row), resend (token rotation + already-accepted no-op), role
| change (syncRoles + guards), removal (hard delete, pivot + token cleanup,
| guards), the permission/platform-role/audience refusals, and the public
| accept flow: pending preview, accepted vs invalid distinction, validation,
| activation with a live admin token pair, and the 409 on a re-click.
*/

function ad10Token(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad10SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

/**
 * A platform admin carrying the seeded Platform Admin role — full platform
 * access *except* managing admins, which is the point of the gate.
 */
function ad10PlatformAdmin(): User
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

/**
 * A manageable platform admin: active or invited, with an assignable role.
 */
function ad10ManageableAdmin(string $roleName = 'Support Admin', array $attributes = []): User
{
    (new SpatiePermissionSeeder)->run();

    $admin = User::factory()->create(array_merge([
        'role' => User::ROLE_ADMIN,
        'status' => 'invited',
        'is_verified' => false,
        'business_id' => null,
        'invitation_token' => Str::random(64),
        'invited_at' => now()->subDay(),
        'force_password_change' => true,
    ], $attributes));

    setPermissionsTeamId(null);
    $admin->assignRole($roleName);

    return $admin;
}

function ad10Get(object $test, User $admin, string $path)
{
    return $test->getJson('/api/v1/admin/'.$path, ['Authorization' => 'Bearer '.ad10Token($admin)]);
}

function ad10Post(object $test, User $admin, string $path, array $payload = [])
{
    return $test->postJson('/api/v1/admin/'.$path, $payload, ['Authorization' => 'Bearer '.ad10Token($admin)]);
}

function ad10Put(object $test, User $admin, string $path, array $payload = [])
{
    return $test->putJson('/api/v1/admin/'.$path, $payload, ['Authorization' => 'Bearer '.ad10Token($admin)]);
}

function ad10Delete(object $test, User $admin, string $path)
{
    return $test->deleteJson('/api/v1/admin/'.$path, [], ['Authorization' => 'Bearer '.ad10Token($admin)]);
}

test('the admins directory lists platform accounts with roles, status and stats', function () {
    $super = ad10SuperAdmin();
    $invited = ad10ManageableAdmin('Support Admin', ['name' => '']);
    $active = ad10ManageableAdmin('Finance Admin', ['status' => 'active', 'name' => 'Funmi Finance', 'invitation_token' => null, 'invited_at' => null]);

    $response = ad10Get($this, $super, 'admins')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 20);

    // Newest first, matching the legacy `orderBy('created_at', 'desc')`.
    expect($response->json('data.0.email'))->toBe($active->email);

    expect($response->json('meta.stats'))->toBe(['total' => 3, 'pending' => 1, 'active' => 2]);

    $invitedRow = collect($response->json('data'))->firstWhere('email', $invited->email);
    expect($invitedRow['display_name'])->toBe('Pending setup');
    expect($invitedRow['status'])->toBe('invited');
    expect($invitedRow['role_name'])->toBe('Support Admin');
    expect($invitedRow['roles'])->toBe(['Support Admin']);
    expect($invitedRow['force_password_change'])->toBeTrue();
    expect($invitedRow['can_manage'])->toBeTrue();
    expect($invitedRow['is_protected'])->toBeFalse();
    expect($invitedRow['is_self'])->toBeFalse();
    expect($invitedRow['invited_at'])->not->toBeNull();

    $superRow = collect($response->json('data'))->firstWhere('email', $super->email);
    expect($superRow['is_protected'])->toBeTrue();
    expect($superRow['is_superadmin'])->toBeTrue();
    expect($superRow['role_name'])->toBe('Super Admin');
    expect($superRow['is_self'])->toBeTrue();
    expect($superRow['can_manage'])->toBeFalse();
});

test('the admins directory search and status filters combine', function () {
    $super = ad10SuperAdmin();
    ad10ManageableAdmin('Support Admin', ['name' => 'Searchable Invite', 'email' => 'searchable@example.test']);
    ad10ManageableAdmin('Finance Admin', ['status' => 'active', 'name' => 'Other Person', 'email' => 'other@example.test', 'invitation_token' => null]);

    ad10Get($this, $super, 'admins?q=searchable')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'searchable@example.test');

    ad10Get($this, $super, 'admins?status=invited')->assertOk()->assertJsonPath('meta.total', 1);
    ad10Get($this, $super, 'admins?status=active&q=other')->assertOk()->assertJsonPath('meta.total', 1);
    ad10Get($this, $super, 'admins?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
});

test('the roles endpoint returns assignable platform roles only', function () {
    $super = ad10SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    // A tenant role with the same shape but a business id must never be
    // offered (or accepted) as a platform role.
    Role::create(['name' => 'Tenant Custom Role', 'business_id' => $business->id, 'guard_name' => 'web']);

    $response = ad10Get($this, $super, 'admins/roles')->assertOk();

    expect(collect($response->json('data.roles'))->pluck('name')->all())
        ->toBe(['Finance Admin', 'Platform Admin', 'Support Admin']);
});

test('inviting an admin creates an invited platform account, assigns the role and queues the mail', function () {
    Mail::fake();

    $super = ad10SuperAdmin();

    $response = ad10Post($this, $super, 'admins', [
        'email' => 'invited-admin@example.test',
        'role' => 'Support Admin',
    ])
        ->assertCreated()
        ->assertJsonPath('data.emailed', true)
        ->assertJsonPath('data.admin.status', 'invited')
        ->assertJsonPath('data.admin.role_name', 'Support Admin')
        ->assertJsonPath('data.admin.display_name', 'Pending setup');

    $admin = User::where('email', 'invited-admin@example.test')->firstOrFail();

    expect($response->json('data.admin.account_code'))->toBe($admin->account_code);
    expect($admin->role)->toBe('admin');
    expect($admin->business_id)->toBeNull();
    expect($admin->status)->toBe('invited');
    expect(strlen($admin->invitation_token))->toBe(64);
    expect($admin->invited_at)->not->toBeNull();
    expect($admin->force_password_change)->toBeTrue();
    expect($admin->is_verified)->toBeFalse();

    // The stored password is an unusable random hash, never the raw string.
    expect(Hash::check('anything', $admin->password))->toBeFalse();

    setPermissionsTeamId(null);
    expect($admin->fresh()->getRoleNames()->all())->toBe(['Support Admin']);

    Mail::assertQueued(AdminInvitationSpaMail::class, fn ($mail) => $mail->user->id === $admin->id);

    $log = ActivityLog::where('action', 'admin.invited')->where('subject_id', $admin->id)->first();
    expect($log)->not->toBeNull();
    expect($log->new_values['role'])->toBe('Support Admin');
    expect($log->metadata['invited_by'])->toBe($super->id);
});

test('inviting refuses duplicate emails across users and customers, and non-platform roles', function () {
    Mail::fake();

    $super = ad10SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    User::factory()->create(['email' => 'taken-user@example.test']);
    Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Customer',
        'email' => 'taken-customer@example.test',
        // `phone` is NOT NULL on `customers` (see the create migration).
        'phone' => '08031112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ]);

    ad10Post($this, $super, 'admins', ['email' => 'taken-user@example.test', 'role' => 'Support Admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    ad10Post($this, $super, 'admins', ['email' => 'taken-customer@example.test', 'role' => 'Support Admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    ad10Post($this, $super, 'admins', ['email' => 'fresh@example.test', 'role' => 'Nope Role'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    // A role that really exists but belongs to a business is not a platform
    // role, so it passes `exists` and still has to be refused.
    Role::create(['name' => 'Tenant Custom Role', 'business_id' => $business->id, 'guard_name' => 'web']);

    ad10Post($this, $super, 'admins', ['email' => 'fresh@example.test', 'role' => 'Tenant Custom Role'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    expect(User::where('email', 'fresh@example.test')->exists())->toBeFalse();

    Mail::assertNothingQueued();
});

test('resending an invitation rotates the token, refreshes invited_at and audits', function () {
    Mail::fake();

    $super = ad10SuperAdmin();
    $admin = ad10ManageableAdmin();
    $oldToken = $admin->invitation_token;
    $oldInvitedAt = $admin->invited_at;

    ad10Post($this, $super, 'admins/'.$admin->account_code.'/resend')
        ->assertOk()
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.emailed', true);

    $admin->refresh();

    expect($admin->invitation_token)->not->toBe($oldToken);
    expect(strlen($admin->invitation_token))->toBe(64);
    expect($admin->invited_at->greaterThan($oldInvitedAt))->toBeTrue();

    Mail::assertQueued(AdminInvitationSpaMail::class, fn ($mail) => $mail->user->id === $admin->id);
    expect(ActivityLog::where('action', 'admin.invitation_resent')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

test('resending an accepted invitation is an honest no-op', function () {
    Mail::fake();

    $super = ad10SuperAdmin();
    $admin = ad10ManageableAdmin('Support Admin', ['status' => 'active', 'accepted_at' => now()]);
    $token = $admin->invitation_token;

    ad10Post($this, $super, 'admins/'.$admin->account_code.'/resend')
        ->assertOk()
        ->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.emailed', false)
        ->assertJsonPath('data.warning', 'This admin has already accepted their invitation.');

    expect($admin->fresh()->invitation_token)->toBe($token);
    Mail::assertNothingQueued();
});

test('changing a role syncs the platform role and writes an old/new audit row', function () {
    $super = ad10SuperAdmin();
    $admin = ad10ManageableAdmin('Support Admin');

    ad10Put($this, $super, 'admins/'.$admin->account_code, ['role' => 'Finance Admin'])
        ->assertOk()
        ->assertJsonPath('data.admin.role_name', 'Finance Admin');

    setPermissionsTeamId(null);
    expect($admin->fresh()->getRoleNames()->all())->toBe(['Finance Admin']);

    $log = ActivityLog::where('action', 'admin.role_changed')->where('subject_id', $admin->id)->first();
    expect($log)->not->toBeNull();
    expect($log->old_values['roles'])->toBe(['Support Admin']);
    expect($log->new_values['roles'])->toBe(['Finance Admin']);
    expect($log->metadata['changed_by'])->toBe($super->id);
});

test('role changes are refused for self, the superadmin, invalid roles and tenant accounts', function () {
    $super = ad10SuperAdmin();
    $otherSuper = User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'business_id' => null,
    ]);
    $admin = ad10ManageableAdmin('Support Admin');
    [$owner] = createBusinessOwner();

    ad10Put($this, $super, 'admins/'.$super->account_code, ['role' => 'Finance Admin'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot change your own role.');

    ad10Put($this, $super, 'admins/'.$otherSuper->account_code, ['role' => 'Finance Admin'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The superadmin role cannot be changed.');

    ad10Put($this, $super, 'admins/'.$admin->account_code, ['role' => 'Platform Admin'])
        ->assertOk(); // sanity: a valid change still works

    ad10Put($this, $super, 'admins/'.$admin->account_code, ['role' => 'Nope Role'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    ad10Put($this, $super, 'admins/'.$admin->account_code, ['role' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    // A tenant account is simply not on this console.
    ad10Put($this, $super, 'admins/'.$owner->account_code, ['role' => 'Finance Admin'])
        ->assertStatus(404);
});

test('removing an admin hard-deletes the account, its roles and its tokens', function () {
    $super = ad10SuperAdmin();
    $admin = ad10ManageableAdmin('Support Admin', ['status' => 'active', 'invitation_token' => null]);
    $admin->createToken('admin-access', ['admin'], now()->addHour());

    expect($admin->tokens()->count())->toBe(1);

    ad10Delete($this, $super, 'admins/'.$admin->account_code)
        ->assertOk()
        ->assertJsonPath('message', $admin->email.' has been removed.');

    expect(User::find($admin->id))->toBeNull();
    expect(DB::table('model_has_roles')->where('model_id', $admin->id)->where('model_type', User::class)->count())->toBe(0);
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $admin->id)->count())->toBe(0);

    $log = ActivityLog::where('action', 'admin.removed')->where('subject_id', $admin->id)->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['removed_by'])->toBe($super->id);
    expect($log->old_values['email'])->toBe($admin->email);
});

test('removal is refused for self, the superadmin and tenant accounts', function () {
    $super = ad10SuperAdmin();
    $otherSuper = User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'business_id' => null,
    ]);
    [$owner] = createBusinessOwner();

    ad10Delete($this, $super, 'admins/'.$super->account_code)
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot remove your own account.');

    ad10Delete($this, $super, 'admins/'.$otherSuper->account_code)
        ->assertStatus(422)
        ->assertJsonPath('message', 'The superadmin account cannot be removed.');

    ad10Delete($this, $super, 'admins/'.$owner->account_code)->assertStatus(404);

    expect(User::find($super->id))->not->toBeNull();
    expect(User::find($otherSuper->id))->not->toBeNull();
});

test('the admins console refuses tenant, unpermitted, wrong-audience and guest callers', function () {
    // A business-scoped account whose in-business Super Admin role bundles the
    // admin.* permission names still cannot manage platform accounts.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.admins'))->toBeTrue();

    ad10Get($this, $owner, 'admins')->assertStatus(403);
    ad10Post($this, $owner, 'admins', ['email' => 'nope@example.test', 'role' => 'Support Admin'])->assertStatus(403);

    // Sanctum's guard caches the resolved user for the whole test, so it must
    // be forgotten before each request made as a different identity — without
    // it every assertion below would really be checked against $owner.
    app('auth')->forgetGuards();

    // The seeded Platform Admin role deliberately excludes admin.admins.
    $platformAdmin = ad10PlatformAdmin();
    ad10Get($this, $platformAdmin, 'admins')->assertStatus(403);
    ad10Get($this, $platformAdmin, 'admins/roles')->assertStatus(403);

    // A management-audience token never reaches an admin route.
    app('auth')->forgetGuards();

    $managementToken = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
    $this->getJson('/api/v1/admin/admins', ['Authorization' => 'Bearer '.$managementToken])->assertStatus(403);

    // Guests are unauthenticated.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/admins')->assertUnauthorized();
});

test('the invitation preview reports a pending invitation without a session', function () {
    $admin = ad10ManageableAdmin('Support Admin', ['name' => 'Grace Invitee']);

    $this->getJson('/api/v1/admin/invitations/'.$admin->invitation_token)
        ->assertOk()
        ->assertJsonPath('data.already_accepted', false)
        ->assertJsonPath('data.email', $admin->email)
        ->assertJsonPath('data.name', 'Grace Invitee');
});

test('the invitation preview distinguishes an accepted invitation from an invalid token', function () {
    $admin = ad10ManageableAdmin();
    $accepted = ad10ManageableAdmin('Finance Admin', ['status' => 'active', 'accepted_at' => now()]);
    [$owner, $business] = createBusinessOwner();

    // A staff invitation token is not an admin invitation.
    $staff = User::factory()->create([
        'role' => 'staff',
        'business_id' => $business->id,
        'status' => 'invited',
        'invitation_token' => Str::random(64),
    ]);

    $this->getJson('/api/v1/admin/invitations/'.$accepted->invitation_token)
        ->assertOk()
        ->assertJsonPath('data.already_accepted', true)
        ->assertJsonPath('data.email', $accepted->email);

    $this->getJson('/api/v1/admin/invitations/not-a-real-token')
        ->assertNotFound()
        ->assertJsonPath('message', 'This invitation link is invalid or has expired.');

    $this->getJson('/api/v1/admin/invitations/'.$staff->invitation_token)->assertNotFound();

    // The pending invitee stays pending through all of that.
    expect($admin->fresh()->status)->toBe('invited');
});

test('accepting an invitation activates the account and returns a working admin token pair', function () {
    $admin = ad10ManageableAdmin('Support Admin', ['name' => '']);

    $response = $this->postJson('/api/v1/admin/invitations/'.$admin->invitation_token, [
        'name' => 'Ada Admin',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.user.status', 'active')
        ->assertJsonPath('data.user.role', 'admin')
        ->assertJsonPath('data.user.name', 'Ada Admin');

    expect($response->json('data.access_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.refresh_token'))->toBeString()->not->toBeEmpty();

    $fresh = $admin->fresh();

    expect($fresh->status)->toBe('active');
    expect($fresh->is_verified)->toBeTrue();
    expect($fresh->email_verified_at)->not->toBeNull();
    expect($fresh->accepted_at)->not->toBeNull();
    expect($fresh->force_password_change)->toBeFalse();
    expect(Hash::check('super-secret-1', $fresh->password))->toBeTrue();

    // The token is retained (inert) so a re-click can be told apart from a
    // forged link — see AdminInvitationController's header.
    expect($fresh->invitation_token)->toBe($admin->invitation_token);

    // The issued pair is a real admin session.
    $this->getJson('/api/v1/admin/auth/me', [
        'Authorization' => 'Bearer '.$response->json('data.access_token'),
    ])->assertOk()->assertJsonPath('data.user.status', 'active');

    expect(ActivityLog::where('action', 'admin.invitation_accepted')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

test('accepting an invitation validates the name and password', function () {
    $admin = ad10ManageableAdmin();

    $this->postJson('/api/v1/admin/invitations/'.$admin->invitation_token, [
        'name' => '',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'password']);

    $this->postJson('/api/v1/admin/invitations/'.$admin->invitation_token, [
        'password' => 'long-enough-password',
        'password_confirmation' => 'long-enough-password',
    ])->assertStatus(422)->assertJsonValidationErrors('name');

    $fresh = $admin->fresh();
    expect($fresh->status)->toBe('invited');
    expect($fresh->force_password_change)->toBeTrue();
    expect($fresh->name)->toBe($admin->name);
});

test('accepting an already accepted invitation is a 409 and mutates nothing', function () {
    $admin = ad10ManageableAdmin('Support Admin', ['status' => 'active', 'accepted_at' => now(), 'name' => 'Already In']);
    $password = $admin->password;

    $this->postJson('/api/v1/admin/invitations/'.$admin->invitation_token, [
        'name' => 'Impostor',
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])
        ->assertStatus(409)
        ->assertJsonPath('already_accepted', true)
        ->assertJsonPath('email', $admin->email);

    $fresh = $admin->fresh();
    expect($fresh->name)->toBe('Already In');
    expect($fresh->password)->toBe($password);
});

test('accepting an unknown token is a 404', function () {
    $this->postJson('/api/v1/admin/invitations/not-a-real-token', [
        'name' => 'Nobody',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
    ])->assertNotFound();
});

test('the invitation mail links to the admin SPA accept screen', function () {
    $admin = ad10ManageableAdmin();

    $mailable = new AdminInvitationSpaMail($admin);

    expect($mailable->acceptUrl())->toContain('/accept-invitation/'.$admin->invitation_token);
    expect($mailable->envelope()->subject)->toBe('You have been invited to join the Storify admin team');
});
