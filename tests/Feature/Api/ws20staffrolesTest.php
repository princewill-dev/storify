<?php

use App\Mail\StaffInvitationSpaMail;
use App\Models\Business;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| WS-20 — Staff & Roles Parity
|--------------------------------------------------------------------------
| Covers the repaired behaviours the legacy audit named: multi-role
| reassignment, soft-deactivation on removal, resend-invite, the owner row
| and store filter, documents CRUD, role validation guards and the
| cross-tenant refusals.
*/

function ws20Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * @return array{0: User, 1: Business, 2: Store, 3: Warehouse}
 */
function ws20Context(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS20 Store',
        'slug' => 'ws20-store-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
    ]);

    $warehouse = Warehouse::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS20 Warehouse',
        'status' => Warehouse::STATUS_ACTIVE,
    ]);

    return [$owner, $business, $store, $warehouse];
}

function ws20Staff(Business $business, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ], $attributes));
}

test('the staff directory includes the owner row and hides deleted and foreign members', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws20Staff($business, ['name' => 'Ada Active']);
    ws20Staff($business, ['name' => 'Bola Invited', 'status' => 'invited', 'invitation_token' => 'invite-token']);
    ws20Staff($business, ['name' => 'Chidi Gone', 'status' => 'deleted']);

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws20Staff($otherBusiness, ['name' => 'Foreign Member']);

    $response = $this->withToken(ws20Token($owner))->getJson('/api/v1/management/staff');

    $response->assertOk();

    $names = array_column($response->json('data'), 'name');

    expect($names)->toContain('Ada Active', 'Bola Invited', $owner->name)
        ->not->toContain('Chidi Gone')
        ->not->toContain('Foreign Member');

    // The owner leads the directory with the badge flag the SPA reads.
    expect($response->json('data.0.is_owner'))->toBeTrue()
        ->and($response->json('meta.stats'))->toMatchArray(['total' => 3, 'active' => 2, 'invited' => 1, 'suspended' => 0]);
});

test('the staff directory narrows to one store and refuses a foreign store', function () {
    [$owner, $business, $store] = ws20Context();

    $assigned = ws20Staff($business, ['name' => 'Assigned Staff']);
    $assigned->assignedStores()->sync([$store->id]);
    ws20Staff($business, ['name' => 'Unassigned Staff']);

    $response = $this->withToken(ws20Token($owner))
        ->getJson('/api/v1/management/staff?store_id='.$store->store_id);

    $response->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Assigned Staff'])
        ->and($response->json('meta.store.name'))->toBe('WS20 Store');

    // A store code the business does not own is a 404, not a silent empty list.
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $otherStore = Store::create([
        'user_id' => $otherOwner->id,
        'business_id' => $otherOwner->business_id,
        'name' => 'Other Store',
        'slug' => 'other-store-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
    ]);

    $this->withToken(ws20Token($owner))
        ->getJson('/api/v1/management/staff?store_id='.$otherStore->store_id)
        ->assertStatus(404);
});

test('inviting a staff member stores roles, assignments and tagged documents', function () {
    Mail::fake();
    Storage::fake('public');

    [$owner, $business, $store, $warehouse] = ws20Context();

    $response = $this->withToken(ws20Token($owner))->post('/api/v1/management/staff', [
        'name' => 'New Hire',
        'email' => 'new-hire@example.test',
        'phone' => '08030000000',
        'role' => 'Cashier',
        'pin' => '123456',
        'store_ids' => [$store->id],
        'warehouse_ids' => [$warehouse->id],
        'documents' => [UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')],
        'document_tags' => ['CV'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.staff.status', 'invited')
        ->assertJsonPath('data.staff.stores_count', 1)
        ->assertJsonPath('data.staff.warehouses_count', 1);

    expect($response->json('data.staff.roles'))->toContain('Cashier')
        ->and($response->json('data.staff.documents.0.tag'))->toBe('CV')
        ->and($response->json('data.staff.documents.0.original_name'))->toBe('cv.pdf')
        ->and($response->json('data.staff.has_pin'))->toBeTrue();

    setPermissionsTeamId($business->id);
    $staff = User::where('email', 'new-hire@example.test')->firstOrFail();

    expect($staff->getRoleNames()->all())->toBe(['Cashier'])
        ->and($staff->assignedStores()->pluck('stores.id')->all())->toBe([$store->id])
        ->and($staff->assignedWarehouses()->pluck('warehouses.id')->all())->toBe([$warehouse->id])
        ->and($staff->documents()->count())->toBe(1);

    Mail::assertQueued(StaffInvitationSpaMail::class, function (StaffInvitationSpaMail $mail) use ($staff) {
        // The invite link must land on the SPA accept screen, not legacy Blade.
        return $mail->user->is($staff) && str_contains($mail->acceptUrl(), '/invitations/'.$staff->invitation_token);
    });
});

test('an unknown or foreign role name is a validation error instead of a 500', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    setPermissionsTeamId($otherBusiness->id);
    Role::create(['name' => 'Shadow Role', 'business_id' => $otherBusiness->id, 'guard_name' => 'web']);

    $this->withToken(ws20Token($owner))->postJson('/api/v1/management/staff', [
        'name' => 'Ghost',
        'email' => 'ghost@example.test',
        'role' => 'Not A Real Role',
    ])->assertStatus(422)->assertJsonValidationErrors('role');

    // A role that exists, but in someone else's business, is equally invalid.
    $this->withToken(ws20Token($owner))->postJson('/api/v1/management/staff', [
        'name' => 'Ghost Two',
        'email' => 'ghost-two@example.test',
        'role' => 'Shadow Role',
    ])->assertStatus(422)->assertJsonValidationErrors('role');
});

test('a pre-set invite password is confirmed, forces a change and travels in the mail', function () {
    Mail::fake();

    [$owner] = ws20Context();

    $this->withToken(ws20Token($owner))->postJson('/api/v1/management/staff', [
        'name' => 'Password Hire',
        'email' => 'password-hire@example.test',
        'role' => 'Cashier',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $this->withToken(ws20Token($owner))->postJson('/api/v1/management/staff', [
        'name' => 'Password Hire',
        'email' => 'password-hire@example.test',
        'role' => 'Cashier',
        'password' => 'chosen-password',
        'password_confirmation' => 'chosen-password',
    ])->assertCreated();

    $staff = User::where('email', 'password-hire@example.test')->firstOrFail();

    expect((bool) $staff->force_password_change)->toBeTrue();

    Mail::assertQueued(
        StaffInvitationSpaMail::class,
        fn (StaffInvitationSpaMail $mail) => $mail->plainPassword === 'chosen-password',
    );
});

test('roles can be reassigned on an existing staff member', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = ws20Staff($business, ['name' => 'Promotable']);
    setPermissionsTeamId($business->id);
    $staff->assignRole('Cashier');

    $response = $this->withToken(ws20Token($owner))->putJson('/api/v1/management/staff/'.$staff->account_code, [
        'roles' => ['Manager', 'Store Associate', 'Cashier'],
    ]);

    $response->assertOk();

    expect($response->json('data.staff.roles'))->toHaveCount(3)
        ->and($staff->fresh()->hasRole('Manager'))->toBeTrue()
        ->and($staff->fresh()->hasRole('Cashier'))->toBeTrue();

    // Sync, not attach: dropping a role removes it.
    $this->withToken(ws20Token($owner))->putJson('/api/v1/management/staff/'.$staff->account_code, [
        'roles' => ['Manager'],
    ])->assertOk();

    expect($staff->fresh()->getRoleNames()->all())->toBe(['Manager']);
});

test('the edit form can clear the POS pin, replace or remove the photo and sync warehouses', function () {
    Storage::fake('public');

    [$owner, $business, , $warehouse] = ws20Context();

    $staff = ws20Staff($business, ['pos_pin' => '123456']);
    $uri = '/api/v1/management/staff/'.$staff->account_code;

    $this->withToken(ws20Token($owner))->post($uri, [
        '_method' => 'PUT',
        'name' => 'Renamed Member',
        'photo' => UploadedFile::fake()->image('avatar.jpg'),
        'warehouse_ids' => [$warehouse->id],
    ])->assertOk()->assertJsonPath('data.staff.name', 'Renamed Member');

    $staff->refresh();

    expect($staff->photo_path)->not->toBeNull()
        ->and($staff->assignedWarehouses()->pluck('warehouses.id')->all())->toBe([$warehouse->id]);

    $previousPhoto = $staff->photo_path;

    // An explicitly empty pin clears it — legacy's "leave empty to clear".
    $this->withToken(ws20Token($owner))->putJson($uri, ['pin' => ''])->assertOk();

    expect($staff->fresh()->pos_pin)->toBeNull();

    $this->withToken(ws20Token($owner))->putJson($uri, ['remove_photo' => true])->assertOk();

    expect($staff->fresh()->photo_path)->toBeNull();
    Storage::disk('public')->assertMissing($previousPhoto);
});

test('an invitation can be resent while pending but not after acceptance', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $pending = ws20Staff($business, [
        'status' => 'invited',
        'invitation_token' => 'old-token',
        'invited_at' => now()->subWeek(),
    ]);

    $this->withToken(ws20Token($owner))
        ->postJson('/api/v1/management/staff/'.$pending->account_code.'/resend-invite')
        ->assertOk();

    $pending->refresh();

    expect($pending->invitation_token)->not->toBe('old-token')
        ->and($pending->invited_at->isToday())->toBeTrue();

    Mail::assertQueued(StaffInvitationSpaMail::class, fn ($mail) => $mail->user->is($pending));

    $active = ws20Staff($business, ['status' => 'active']);

    $this->withToken(ws20Token($owner))
        ->postJson('/api/v1/management/staff/'.$active->account_code.'/resend-invite')
        ->assertStatus(409);
});

test('removing a staff member deactivates them without destroying history', function () {
    [$owner, $business, $store] = ws20Context();

    $staff = ws20Staff($business, ['name' => 'Leaver']);

    $session = PosSession::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'staff_id' => $staff->id,
        'opening_balance' => 0,
    ]);

    $this->withToken(ws20Token($owner))
        ->deleteJson('/api/v1/management/staff/'.$staff->account_code)
        ->assertOk();

    // The row survives (soft deactivation) and the POS session keeps its FK.
    $staff->refresh();

    expect($staff->status)->toBe('deleted')
        ->and($staff->invitation_token)->toBeNull()
        ->and(PosSession::find($session->id))->not->toBeNull();

    $listed = $this->withToken(ws20Token($owner))->getJson('/api/v1/management/staff');

    expect(array_column($listed->json('data'), 'name'))->not->toContain('Leaver');
});

test('suspend and activate enforce the staff status transitions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $active = ws20Staff($business, ['name' => 'Toggle Me']);
    $invited = ws20Staff($business, ['name' => 'Never Accepted', 'status' => 'invited']);

    // An invited account must accept the invitation before it can be activated.
    $this->withToken(ws20Token($owner))
        ->postJson('/api/v1/management/staff/'.$invited->account_code.'/activate')
        ->assertStatus(409);

    $this->withToken(ws20Token($owner))
        ->postJson('/api/v1/management/staff/'.$invited->account_code.'/suspend')
        ->assertStatus(409);

    $this->withToken(ws20Token($owner))
        ->postJson('/api/v1/management/staff/'.$active->account_code.'/suspend')
        ->assertOk();

    expect($active->fresh()->status)->toBe('suspended');

    $this->withToken(ws20Token($owner))
        ->postJson('/api/v1/management/staff/'.$active->account_code.'/activate')
        ->assertOk();

    expect($active->fresh()->status)->toBe('active');

    $this->withToken(ws20Token($owner))
        ->postJson('/api/v1/management/staff/'.$active->account_code.'/activate')
        ->assertStatus(409);
});

test('staff records and assignments are refused across businesses', function () {
    [$owner, $business, $store] = ws20Context();

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $foreign = ws20Staff($otherBusiness, ['name' => 'Foreign Staff']);

    $uri = '/api/v1/management/staff/'.$foreign->account_code;

    $this->withToken(ws20Token($owner))->getJson($uri)->assertStatus(404);
    $this->withToken(ws20Token($owner))->putJson($uri, ['name' => 'Hijacked'])->assertStatus(404);
    $this->withToken(ws20Token($owner))->deleteJson($uri)->assertStatus(404);

    expect($foreign->fresh()->name)->toBe('Foreign Staff');

    // Foreign store ids are dropped from the sync rather than attached.
    $own = ws20Staff($business, ['name' => 'Own Staff']);
    $foreignStore = Store::create([
        'user_id' => $otherOwner->id,
        'business_id' => $otherBusiness->id,
        'name' => 'Foreign Store',
        'slug' => 'foreign-store-'.uniqid(),
        'status' => Store::STATUS_ACTIVE,
    ]);

    $this->withToken(ws20Token($owner))->putJson('/api/v1/management/staff/'.$own->account_code, [
        'store_ids' => [$store->id, $foreignStore->id],
    ])->assertOk();

    expect($own->fresh()->assignedStores()->pluck('stores.id')->all())->toBe([$store->id]);
});

test('roles are created and edited with a validated permission matrix', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $created = $this->withToken(ws20Token($owner))->postJson('/api/v1/management/roles', [
        'name' => 'Shift Lead',
        'permissions' => ['staff view', 'staff edit'],
    ]);

    $created->assertCreated()->assertJsonPath('data.role.name', 'Shift Lead');

    $roleId = $created->json('data.role.id');

    // Bogus permission names are refused instead of blowing up in Spatie.
    $this->withToken(ws20Token($owner))->postJson('/api/v1/management/roles', [
        'name' => 'Broken Role',
        'permissions' => ['not a permission'],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions.0');

    // Duplicate names are refused on create and on rename.
    $this->withToken(ws20Token($owner))->postJson('/api/v1/management/roles', [
        'name' => 'Shift Lead',
        'permissions' => ['staff view'],
    ])->assertStatus(422)->assertJsonValidationErrors('name');

    $this->withToken(ws20Token($owner))->putJson('/api/v1/management/roles/'.$roleId, [
        'name' => 'Manager',
    ])->assertStatus(422)->assertJsonValidationErrors('name');

    $this->withToken(ws20Token($owner))->putJson('/api/v1/management/roles/'.$roleId, [
        'name' => 'Shift Supervisor',
        'permissions' => ['staff view', 'staff suspend'],
    ])->assertOk()->assertJsonPath('data.role.name', 'Shift Supervisor');

    expect(Role::find($roleId)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['staff suspend', 'staff view']);

    // And the business catalog hides the platform admin.* noise.
    $catalog = $this->withToken(ws20Token($owner))->getJson('/api/v1/management/roles');

    $catalog->assertOk();
    expect($catalog->json('data.permissions'))->not->toContain('admin.users')
        ->and(collect($catalog->json('data.roles'))->firstWhere('id', $roleId)['protected'])->toBeFalse();
});

test('protected system roles refuse renaming and deletion, and used roles refuse deletion', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    setPermissionsTeamId($business->id);

    foreach (['Super Admin', 'Developer', 'Store Associate'] as $name) {
        $role = Role::where('business_id', $business->id)->where('name', $name)->firstOrFail();

        $this->withToken(ws20Token($owner))
            ->deleteJson('/api/v1/management/roles/'.$role->id)
            ->assertStatus(409);

        $this->withToken(ws20Token($owner))
            ->putJson('/api/v1/management/roles/'.$role->id, ['name' => 'Renamed '.$name])
            ->assertStatus(409);

        expect(Role::find($role->id)->name)->toBe($name);
    }

    // A normal role still assigned to someone cannot be deleted.
    $staff = ws20Staff($business);
    $staff->assignRole('Auditor');
    $auditor = Role::where('business_id', $business->id)->where('name', 'Auditor')->firstOrFail();

    $this->withToken(ws20Token($owner))
        ->deleteJson('/api/v1/management/roles/'.$auditor->id)
        ->assertStatus(409);

    expect(Role::find($auditor->id))->not->toBeNull();

    // Roles of another business are invisible to this one.
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $foreignRole = Role::where('business_id', $otherBusiness->id)->where('name', 'Auditor')->firstOrFail();

    $this->withToken(ws20Token($owner))
        ->deleteJson('/api/v1/management/roles/'.$foreignRole->id)
        ->assertStatus(404);
});

test('staff documents can be removed from a profile', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = ws20Staff($business);
    $other = ws20Staff($business);

    $path = UploadedFile::fake()->create('certificate.pdf', 40, 'application/pdf')->store('staff-documents', 'public');

    $document = $staff->documents()->create([
        'file_name' => 'certificate.pdf',
        'file_path' => $path,
        'original_name' => 'certificate.pdf',
        'mime_type' => 'application/pdf',
        'size' => 40,
        'tag' => 'Certificate',
    ]);

    // A document that belongs to a different staff member is a 404.
    $this->withToken(ws20Token($owner))
        ->deleteJson('/api/v1/management/staff/'.$other->account_code.'/documents/'.$document->id)
        ->assertStatus(404);

    $this->withToken(ws20Token($owner))
        ->deleteJson('/api/v1/management/staff/'.$staff->account_code.'/documents/'.$document->id)
        ->assertOk();

    expect($staff->documents()->count())->toBe(0);
    Storage::disk('public')->assertMissing($path);
});

test('staff options feeds the invite form roles, stores and warehouses', function () {
    [$owner, $business, $store, $warehouse] = ws20Context();

    $response = $this->withToken(ws20Token($owner))->getJson('/api/v1/management/staff-options');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['roles' => [['id', 'name', 'permissions']], 'stores', 'warehouses']]);

    expect(array_column($response->json('data.stores'), 'id'))->toContain($store->id)
        ->and(array_column($response->json('data.warehouses'), 'id'))->toContain($warehouse->id)
        ->and(array_column($response->json('data.roles'), 'name'))->toContain('Cashier', 'Manager');
});

test('staff endpoints are gated by staff permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $cashier = ws20Staff($business);
    setPermissionsTeamId($business->id);
    $cashier->assignRole('Cashier');

    $this->withToken(ws20Token($cashier))->getJson('/api/v1/management/staff')->assertStatus(403);
    $this->withToken(ws20Token($cashier))->postJson('/api/v1/management/staff', [
        'name' => 'Nope',
        'email' => 'nope@example.test',
        'role' => 'Cashier',
    ])->assertStatus(403);

    // The test application keeps the Sanctum guard's resolved user between
    // requests, so drop the cached staff identity before switching to the owner.
    $this->app['auth']->forgetGuards();

    $this->withToken(ws20Token($owner))->getJson('/api/v1/management/staff')->assertOk();
});
