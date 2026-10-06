<?php

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\User;
use App\Services\ActivityRecorder;
use Database\Seeders\SpatiePermissionSeeder;

/**
 * WS-1 — Activity log & audit trail (admin console).
 *
 * Covers the list payload with the columns legacy captured but never showed,
 * the combining filters + their validation, the distinct-value filter
 * dropdowns, CSV export, the permission boundary (Platform Admin yes,
 * permissionless admin no, business-scoped account no even though its
 * in-business Super Admin role bundles admin.* names), audience refusal, and
 * the route-access trail written by AdminApiActivityLogger.
 */
function ad01AdminToken(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad01SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad01PlatformAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole('Platform Admin');

    return $user;
}

test('the activity log returns the full audit payload', function () {
    $admin = ad01SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    ActivityLog::create([
        'user_id' => $admin->id,
        'business_id' => $business->id,
        'action' => 'business_suspended',
        'subject_type' => Business::class,
        'subject_id' => $business->id,
        'description' => 'Business suspended for policy review',
        'old_values' => ['status' => 'active'],
        'new_values' => ['status' => 'suspended'],
        'metadata' => ['reason' => 'Policy review'],
        'ip_address' => '10.0.0.9',
        'user_agent' => 'Pest/TestAgent',
    ]);

    $response = $this->getJson('/api/v1/admin/activity-logs', ['Authorization' => 'Bearer '.ad01AdminToken($admin)]);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('data.0.action', 'business_suspended')
        ->assertJsonPath('data.0.description', 'Business suspended for policy review')
        ->assertJsonPath('data.0.subject_type', Business::class)
        ->assertJsonPath('data.0.subject_id', $business->id)
        ->assertJsonPath('data.0.old_values.status', 'active')
        ->assertJsonPath('data.0.new_values.status', 'suspended')
        ->assertJsonPath('data.0.metadata.reason', 'Policy review')
        ->assertJsonPath('data.0.business_id', $business->id)
        ->assertJsonPath('data.0.business', $business->name)
        ->assertJsonPath('data.0.user.id', $admin->id)
        ->assertJsonPath('data.0.ip_address', '10.0.0.9')
        ->assertJsonPath('data.0.user_agent', 'Pest/TestAgent');

    expect($response->json('data.0.created_at'))->not->toBeNull();
});

test('the activity log filters combine', function () {
    $admin = ad01SuperAdmin();
    [$owner] = createBusinessOwner();

    $adminLog = ActivityLog::create([
        'user_id' => $admin->id,
        'action' => 'business_suspended',
        'description' => 'Alpha note',
        'ip_address' => '10.1.1.1',
        'user_agent' => 'Agent/One',
    ]);

    $ownerLog = ActivityLog::create([
        'user_id' => $owner->id,
        'action' => 'user_password_reset',
        'description' => 'Beta note',
        'ip_address' => '10.2.2.2',
        'user_agent' => 'Agent/Two',
    ]);

    // Ten days old so the from/to range can exclude it.
    $ownerLog->forceFill(['created_at' => now()->subDays(10)])->save();

    $token = ad01AdminToken($admin);

    $ids = function (string $query) use ($token) {
        $response = $this->getJson('/api/v1/admin/activity-logs'.($query !== '' ? '?'.$query : ''), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk();

        return collect($response->json('data'))->pluck('id');
    };

    // user_id
    expect($ids('user_id='.$owner->id))->toContain($ownerLog->id)->not->toContain($adminLog->id);

    // action
    expect($ids('action=business_suspended'))->toContain($adminLog->id)->not->toContain($ownerLog->id);

    // free text searches description…
    expect($ids('q=Alpha'))->toContain($adminLog->id)->not->toContain($ownerLog->id);

    // …and IP / user agent, like legacy.
    expect($ids('q=10.2.2.2'))->toContain($ownerLog->id)->not->toContain($adminLog->id);
    expect($ids('q=Agent%2FOne'))->toContain($adminLog->id)->not->toContain($ownerLog->id);

    // date range excludes the backdated row.
    $today = $ids('from='.now()->toDateString().'&to='.now()->toDateString());
    expect($today)->toContain($adminLog->id)->not->toContain($ownerLog->id);

    // filters combine (this pair can never match).
    expect($ids('user_id='.$owner->id.'&action=business_suspended'))->not->toContain($adminLog->id)->not->toContain($ownerLog->id);
});

test('the filter dropdowns only list values that exist', function () {
    $admin = ad01SuperAdmin();
    [$owner] = createBusinessOwner();

    ActivityLog::create([
        'user_id' => $admin->id,
        'action' => 'business_suspended',
        'description' => 'First',
    ]);

    ActivityLog::create([
        'user_id' => $owner->id,
        'action' => 'user_activated',
        'description' => 'Second',
    ]);

    $response = $this->getJson('/api/v1/admin/activity-logs', ['Authorization' => 'Bearer '.ad01AdminToken($admin)])
        ->assertOk();

    // Legacy listed every user; the dropdown now lists only users the filter
    // can actually match, and every action present in the table.
    expect($response->json('meta.filters.actions'))->toBe(['business_suspended', 'user_activated'])
        ->and(collect($response->json('meta.filters.users'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$admin->id, $owner->id])->sort()->values()->all());
});

test('activity log filters are validated', function () {
    $admin = ad01SuperAdmin();
    $token = ad01AdminToken($admin);

    $this->getJson('/api/v1/admin/activity-logs?from=not-a-date', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    $this->getJson('/api/v1/admin/activity-logs?to=2020-01-01&from=2021-01-01', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/admin/activity-logs?per_page=500', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');

    $this->getJson('/api/v1/admin/activity-logs?sort=user_id', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');

    $this->getJson('/api/v1/admin/activity-logs?user_id=99999', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');
});

test('a platform admin with the permission can read but a permissionless admin cannot', function () {
    $platformAdmin = ad01PlatformAdmin();

    $this->getJson('/api/v1/admin/activity-logs', ['Authorization' => 'Bearer '.ad01AdminToken($platformAdmin)])
        ->assertOk();

    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    // Sanctum's guard caches the resolved user for the whole test, so it has
    // to be forgotten before a request made as a different identity.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/activity-logs', ['Authorization' => 'Bearer '.ad01AdminToken($plainAdmin)])
        ->assertStatus(403);
});

test('a business-scoped account cannot read the platform audit trail', function () {
    [$owner, $business] = createBusinessOwner();

    // The in-business "Super Admin" role is seeded with the full permission
    // bundle, admin.* names included, so the permission gate alone would let
    // this account through. The controller's platform guard is what stops it.
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.activity-logs'))->toBeTrue();

    $this->getJson('/api/v1/admin/activity-logs', [
        'Authorization' => 'Bearer '.$owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);
});

test('management tokens and guests are refused', function () {
    [$owner] = createBusinessOwner();

    $managementToken = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/activity-logs', ['Authorization' => 'Bearer '.$managementToken])
        ->assertStatus(403);

    // The guard still holds the owner from the request above; forget it so the
    // token-less request is genuinely unauthenticated.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/activity-logs')
        ->assertStatus(401);
});

test('viewing the activity log is itself audited', function () {
    $admin = ad01SuperAdmin();

    $this->getJson('/api/v1/admin/activity-logs', ['Authorization' => 'Bearer '.ad01AdminToken($admin)])
        ->assertOk();

    $rows = ActivityLog::where('action', 'admin_route_accessed')->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->user_id)->toBe($admin->id)
        ->and($row->metadata['route'])->toBe('api.admin.activity-logs.index')
        ->and($row->metadata['method'])->toBe('GET')
        ->and($row->metadata['status'])->toBe(200)
        ->and($row->metadata['path'])->toBe('api/v1/admin/activity-logs');

    // A second view appends a second row rather than being deduped away.
    $this->getJson('/api/v1/admin/activity-logs', ['Authorization' => 'Bearer '.ad01AdminToken($admin)])
        ->assertOk();

    expect(ActivityLog::where('action', 'admin_route_accessed')->count())->toBe(2);
});

test('the same route exports the filtered trail as csv', function () {
    $admin = ad01SuperAdmin();

    ActivityLog::create([
        'user_id' => $admin->id,
        'action' => 'business_suspended',
        'description' => 'Export me',
        // A legacy-style row written before ActivityRecorder existed: the
        // export must redact it on read exactly like the JSON payload does.
        'old_values' => ['status' => 'active', 'api_keys' => ['paystack_secret' => 'sk_live_legacy']],
        'metadata' => ['password' => 'hunter2'],
        'ip_address' => '10.3.3.3',
    ]);

    ActivityLog::create([
        'user_id' => $admin->id,
        'action' => 'user_activated',
        'description' => 'Leave me out',
    ]);

    $response = $this->get('/api/v1/admin/activity-logs?export=csv&action=business_suspended', [
        'Authorization' => 'Bearer '.ad01AdminToken($admin),
    ]);

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('activity-logs-');

    $csv = $response->streamedContent();

    expect($csv)->toContain('When,User,Action,Description,Subject')
        ->and($csv)->toContain('Export me')
        ->and($csv)->not->toContain('Leave me out');

    // Secrets captured on legacy rows never leave through the export.
    expect($csv)->toContain(ActivityRecorder::REDACTED)
        ->and($csv)->not->toContain('sk_live_legacy')
        ->and($csv)->not->toContain('hunter2');
});

test('the recorder stores subject values and redacts secrets', function () {
    $admin = ad01SuperAdmin();
    [$owner, $business] = createBusinessOwner();

    $log = ActivityRecorder::record(
        action: 'settings_updated',
        description: 'Platform settings updated',
        subject: $business,
        old: ['name' => 'Old name', 'api_keys' => ['paystack_secret' => 'sk_live_123']],
        new: ['name' => 'New name', 'api_keys' => ['paystack_secret' => 'sk_live_456']],
        metadata: ['password' => 'hunter2', 'changed_keys' => ['name', 'api_keys']],
        actor: $admin,
    );

    expect($log->subject_type)->toBe(Business::class)
        ->and($log->subject_id)->toBe($business->id)
        ->and($log->business_id)->toBe($business->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->old_values['name'])->toBe('Old name')
        // `api_keys` contains a guarded fragment, so the whole nested blob is
        // replaced rather than persisting any of its contents.
        ->and($log->old_values['api_keys'])->toBe(ActivityRecorder::REDACTED)
        ->and($log->new_values['api_keys'])->toBe(ActivityRecorder::REDACTED)
        ->and($log->metadata['password'])->toBe(ActivityRecorder::REDACTED)
        ->and($log->metadata['changed_keys'])->toBe(['name', 'api_keys']);
});
