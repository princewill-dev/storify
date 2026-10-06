<?php

use App\Mail\UserPasswordResetMail;
use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Admin user management — legacy parity gaps
|--------------------------------------------------------------------------
| The rest of the legacy admin/UserManagementTest behaviour is already on the
| API in ad08usermoderationTest (directory, show console, edit, verify,
| suspend/activate, reset, delete/restore, impersonate + stop) and
| ManagementOnboardingApiTest (forced change without the current password).
| This file ports the three behaviours those suites do not assert:
|
|  - the Support Admin split: the seeded role carries `admin.users` but not
|    `admin.users.impersonate`, so viewing users is allowed and impersonation
|    is refused;
|  - `role=staff` and the status+verified directory filters;
|  - a forced password change is signalled via `next` until completed, and the
|    temporary password emailed by an admin reset is the one actually stored.
|
| Plus the edit persistence the ad08 suite only checked through the audit
| payload: the legacy test asserted the new name, email AND phone all landed
| on the model.
*/

function aumAdminToken(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function aumManagementToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function aumPlatformAdmin(string $spatieRole = 'Support Admin'): User
{
    (new SpatiePermissionSeeder)->run();

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $admin->assignRole($spatieRole);

    return $admin;
}

test('a support admin can view users but cannot impersonate', function () {
    $support = aumPlatformAdmin('Support Admin');
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // `admin.users` is in the Support Admin role...
    $this->withToken(aumAdminToken($support))
        ->getJson('/api/v1/admin/users')
        ->assertOk();

    // ...but `admin.users.impersonate` is not.
    $this->withToken(aumAdminToken($support))
        ->postJson('/api/v1/admin/users/'.$owner->account_code.'/impersonate')
        ->assertForbidden();

    expect(Impersonation::count())->toBe(0);
});

test('the user directory filters to staff and narrows by status and verified state', function () {
    $admin = aumPlatformAdmin('Support Admin');

    [$activeStaff] = createBusinessOwner([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
    ]);
    [$suspendedStaff] = createBusinessOwner([
        'role' => 'staff',
        'status' => 'suspended',
        'is_verified' => false,
    ]);

    $staffOnly = $this->withToken(aumAdminToken($admin))
        ->getJson('/api/v1/admin/users?role=staff')
        ->assertOk();

    expect(collect($staffOnly->json('data'))->pluck('role')->unique()->values()->all())->toBe(['staff'])
        ->and(collect($staffOnly->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$activeStaff->id, $suspendedStaff->id])->sort()->values()->all());

    $narrowed = $this->withToken(aumAdminToken($admin))
        ->getJson('/api/v1/admin/users?role=staff&status=active&verified=yes')
        ->assertOk();

    expect(collect($narrowed->json('data'))->pluck('id')->all())->toBe([$activeStaff->id]);
});

test('a forced password change keeps steering the app to the password screen until completed', function () {
    [$owner] = createBusinessOwner([
        'force_password_change' => true,
        'trial_ends_at' => now()->addWeek(),
    ]);

    // The SPA is told where to go instead of being hard-redirected.
    $this->withToken(aumManagementToken($owner))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.force_password_change', true)
        ->assertJsonPath('data.next', 'change_password');

    $this->withToken(aumManagementToken($owner))
        ->postJson('/api/v1/management/auth/change-password', [
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
        ])->assertOk();

    expect($owner->fresh()->force_password_change)->toBeFalse();

    // The guard cached the flagged owner for the first request; drop it so
    // /me reads the account as it stands after the change.
    app('auth')->forgetGuards();

    $this->withToken(aumManagementToken($owner->fresh()))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.next', 'dashboard');
});

test('an admin reset emails the temporary password that was actually stored', function () {
    Mail::fake();

    $admin = aumPlatformAdmin('Support Admin');
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(aumAdminToken($admin))
        ->postJson('/api/v1/admin/users/'.$owner->account_code.'/reset-password')
        ->assertOk()
        ->assertJsonPath('data.emailed', true);

    expect($owner->fresh()->force_password_change)->toBeTrue();

    Mail::assertQueued(UserPasswordResetMail::class, function (UserPasswordResetMail $mail) use ($owner) {
        return $mail->hasTo($owner->email)
            && Hash::check($mail->temporaryPassword, $owner->fresh()->password);
    });
});

test('an admin edit persists the new email and phone, not just the name', function () {
    $admin = aumPlatformAdmin('Platform Admin');
    [$owner] = createBusinessOwner([
        'name' => 'Before Name',
        'email' => 'before-owner@example.test',
        'phone' => '08011111111',
        'trial_ends_at' => now()->addWeek(),
    ]);

    $this->withToken(aumAdminToken($admin))
        ->putJson('/api/v1/admin/users/'.$owner->account_code, [
            'name' => 'Renamed Owner',
            'email' => 'renamed-owner@example.test',
            'phone' => '08012345678',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Renamed Owner')
        ->assertJsonPath('data.user.email', 'renamed-owner@example.test')
        ->assertJsonPath('data.user.phone', '08012345678');

    // The ad08 edit test only asserts `name` on the model; its audit
    // new_values echo the request payload, so they do not prove the email or
    // phone were actually persisted. The legacy test asserted all three.
    $fresh = $owner->fresh();
    expect($fresh->name)->toBe('Renamed Owner')
        ->and($fresh->email)->toBe('renamed-owner@example.test')
        ->and($fresh->phone)->toBe('08012345678');

    $log = ActivityLog::query()
        ->where('action', 'user_updated')
        ->where('subject_id', $owner->id)
        ->firstOrFail();

    expect($log->old_values['email'])->toBe('before-owner@example.test')
        ->and($log->old_values['phone'])->toBe('08011111111');
});
