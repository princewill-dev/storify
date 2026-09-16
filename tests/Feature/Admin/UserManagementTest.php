<?php

use App\Mail\UserPasswordResetMail;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

function platformAdmin(string $spatieRole = 'Platform Admin'): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole($spatieRole);

    return $user;
}

function superAdmin(): User
{
    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function managedOwner(): User
{
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    return $owner;
}

test('admin users pages render', function () {
    $admin = superAdmin();
    $owner = managedOwner();

    actingAs($admin);

    $this->get(route('admin.users.index'))->assertOk();
    $this->get(route('admin.users.index', ['role' => 'staff']))->assertOk();
    $this->get(route('admin.users.index', ['status' => 'active', 'verified' => 'yes']))->assertOk();
    $this->get(route('admin.users.show', $owner))->assertOk();
});

test('admin accounts cannot be managed as users', function () {
    $admin = superAdmin();
    $otherAdmin = superAdmin();

    actingAs($admin);

    $this->get(route('admin.users.show', $otherAdmin))->assertNotFound();
});

test('an admin can edit a user profile', function () {
    $admin = superAdmin();
    $owner = managedOwner();

    actingAs($admin)->put(route('admin.users.update', $owner), [
        'name' => 'Renamed Owner',
        'email' => 'renamed-owner@example.test',
        'phone' => '08012345678',
    ])->assertSessionHasNoErrors();

    $owner->refresh();

    expect($owner->name)->toBe('Renamed Owner')
        ->and($owner->email)->toBe('renamed-owner@example.test')
        ->and($owner->phone)->toBe('08012345678');
});

test('an admin can verify and unverify a user', function () {
    $admin = superAdmin();
    $owner = managedOwner();

    $owner->update(['is_verified' => false, 'email_verified_at' => null]);

    actingAs($admin)->post(route('admin.users.verify', $owner))->assertSessionHasNoErrors();
    expect($owner->fresh()->is_verified)->toBeTrue()
        ->and($owner->fresh()->email_verified_at)->not->toBeNull();

    actingAs($admin)->post(route('admin.users.unverify', $owner))->assertSessionHasNoErrors();
    expect($owner->fresh()->is_verified)->toBeFalse()
        ->and($owner->fresh()->email_verified_at)->toBeNull();
});

test('an admin can suspend and activate a user', function () {
    Mail::fake();

    $admin = superAdmin();
    $owner = managedOwner();

    actingAs($admin)->post(route('admin.users.suspend', $owner), ['reason' => 'Policy violation'])
        ->assertSessionHasNoErrors();

    expect($owner->fresh()->status)->toBe('suspended');

    actingAs($admin)->post(route('admin.users.activate', $owner), ['reason' => 'Appeal accepted'])
        ->assertSessionHasNoErrors();

    expect($owner->fresh()->status)->toBe('active');
});

test('an admin can reset a user password with a forced change', function () {
    Mail::fake();

    $admin = superAdmin();
    $owner = managedOwner();

    actingAs($admin)->post(route('admin.users.reset-password', $owner))
        ->assertSessionHasNoErrors();

    $owner->refresh();

    expect($owner->force_password_change)->toBeTrue();

    Mail::assertQueued(UserPasswordResetMail::class, function (UserPasswordResetMail $mail) use ($owner) {
        return $mail->user->is($owner)
            && Hash::check($mail->temporaryPassword, $owner->fresh()->password);
    });
});

test('an admin can delete and restore a user', function () {
    $admin = superAdmin();
    $owner = managedOwner();

    actingAs($admin)->delete(route('admin.users.destroy', $owner))
        ->assertRedirect(route('admin.users.index'));

    expect($owner->fresh()->status)->toBe('deleted');

    actingAs($admin)->post(route('admin.users.restore', $owner))->assertSessionHasNoErrors();

    expect($owner->fresh()->status)->toBe('active');
});

test('an admin can impersonate a user and return to admin', function () {
    $admin = superAdmin();
    $owner = managedOwner();

    actingAs($admin)->post(route('admin.users.impersonate', $owner))
        ->assertRedirect(route('management.dashboard'));

    expect(auth()->id())->toBe($owner->id)
        ->and(session('impersonator_id'))->toBe($admin->id);

    actingAs($owner)->post(route('admin.impersonate.stop'))
        ->assertRedirect(route('admin.users.show', $owner));

    expect(auth()->id())->toBe($admin->id)
        ->and(session('impersonator_id'))->toBeNull();
});

test('a support admin can view users but cannot impersonate', function () {
    $support = platformAdmin('Support Admin');
    $owner = managedOwner();

    actingAs($support)->get(route('admin.users.index'))->assertOk();

    actingAs($support)->post(route('admin.users.impersonate', $owner))->assertForbidden();
});

test('a forced password change blocks management access until completed', function () {
    $owner = managedOwner();
    $owner->update(['force_password_change' => true]);

    actingAs($owner)->get(route('management.dashboard'))
        ->assertRedirect(route('management.password.change'));

    actingAs($owner)->get(route('management.password.change'))->assertOk();

    actingAs($owner)->post(route('management.password.change.update'), [
        'password' => 'brand-new-password-123',
        'password_confirmation' => 'brand-new-password-123',
    ])->assertRedirect(route('management.dashboard'));

    expect($owner->fresh()->force_password_change)->toBeFalse();

    actingAs($owner->fresh())->get(route('management.dashboard'))->assertOk();
});
