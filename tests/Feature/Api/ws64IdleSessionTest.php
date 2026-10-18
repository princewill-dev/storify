<?php

use App\Models\Otp;
use App\Models\RefreshToken;
use App\Support\Auth\SessionPolicy;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Idle-expiring console sessions (WS-64).
 *
 * The refresh token's `expires_at` is the idle deadline. It must be the
 * configured window, move forward on every rotation, and admit nothing once
 * it has passed. The access token is capped at half that window so an active
 * session always rotates in time, and Sanctum's global cap still wins when it
 * is the smaller number.
 *
 * There is deliberately no assertion on a 30-day default here: these tests set
 * the config they are about, the same way the rest of the suite overrides
 * config, so they do not depend on a deploy's env.
 */
function ws64Otp(string $email, string $type): string
{
    return (string) Otp::where('identifier', $email)->where('type', $type)->latest('id')->value('code');
}

/**
 * @return array<string, string>
 */
function ws64Bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Log a management owner all the way in and return the token pair.
 *
 * @param  \Tests\TestCase  $test
 * @return array{access_token: string, refresh_token: string, expires_in: int, session: array<string, int>}
 */
function ws64ManagementLogin($test, string $email): array
{
    $test->postJson('/api/v1/management/auth/login', [
        'email' => $email,
        'password' => 'password',
    ])->assertOk();

    return $test->postJson('/api/v1/management/auth/verify-otp', [
        'email' => $email,
        'otp' => ws64Otp($email, 'business_login'),
    ])->assertOk()->json('data');
}

test('a management refresh token expires at the configured idle window', function () {
    $this->freezeTime();
    config(['api.apps.management.idle_ttl_minutes' => 60]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws64ManagementLogin($this, $owner->email);

    $refresh = RefreshToken::where('app', 'management')->latest('id')->sole();

    // The database keeps whole seconds, so compare instants, not sub-seconds.
    expect($refresh->expires_at->timestamp)->toBe(now()->addMinutes(60)->timestamp);
});

test('the access token is capped at half the idle window', function () {
    $this->freezeTime();
    config([
        'api.apps.management.access_ttl_minutes' => 240,
        'api.apps.management.idle_ttl_minutes' => 60,
        'sanctum.expiration' => 480,
    ]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $pair = ws64ManagementLogin($this, $owner->email);

    expect($pair['expires_in'])->toBe(1800); // 30 minutes, not the configured 240

    $tokenId = (int) explode('|', $pair['access_token'], 2)[0];
    $token = PersonalAccessToken::find($tokenId);

    expect($token->expires_at->timestamp)->toBe(now()->addMinutes(30)->timestamp);
});

test('sanctums global expiry wins when it is the smaller number', function () {
    $this->freezeTime();
    config([
        'api.apps.management.access_ttl_minutes' => 240,
        'api.apps.management.idle_ttl_minutes' => 60,
        'sanctum.expiration' => 20,
    ]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    expect(ws64ManagementLogin($this, $owner->email)['expires_in'])->toBe(1200); // 20 minutes
});

test('rotating a refresh token slides the idle deadline forward', function () {
    $this->freezeTime();
    config(['api.apps.management.idle_ttl_minutes' => 60]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $first = ws64ManagementLogin($this, $owner->email)['refresh_token'];

    $this->travel(20)->minutes();

    $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])
        ->assertOk()
        ->json('data');

    $new = RefreshToken::where('app', 'management')
        ->whereNull('revoked_at')
        ->latest('id')
        ->sole();

    expect($rotated['refresh_token'])->not->toBe($first)
        // The new deadline is 60 minutes from the rotation, not from login.
        ->and($new->expires_at->timestamp)->toBe(now()->addMinutes(60)->timestamp)
        ->and($new->expires_at->greaterThan(now()))->toBeTrue();
});

test('an expired refresh token is refused at the idle deadline', function () {
    $this->freezeTime();
    config(['api.apps.management.idle_ttl_minutes' => 60]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $token = ws64ManagementLogin($this, $owner->email)['refresh_token'];

    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $token])
        ->assertStatus(401);
});

test('management auth responses carry the idle policy', function () {
    config([
        'api.apps.management.idle_ttl_minutes' => 30,
        'api.apps.management.access_ttl_minutes' => 120,
        'api.apps.management.refresh_ttl_days' => 30,
        'sanctum.expiration' => 480,
        'api.idle_warning_seconds' => 60,
    ]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $pair = ws64ManagementLogin($this, $owner->email);

    expect($pair['session'])->toBe([
        'idle_timeout_minutes' => 30,
        'warning_seconds' => 60,
        'access_expires_in' => 900, // half of the 30-minute idle window
    ]);

    $this->getJson('/api/v1/management/auth/me', ws64Bearer($pair['access_token']))
        ->assertOk()
        ->assertJsonPath('data.session.idle_timeout_minutes', 30)
        ->assertJsonPath('data.session.warning_seconds', 60)
        ->assertJsonPath('data.session.access_expires_in', 900);
});

test('the admin console keeps its own idle window', function () {
    config([
        'api.apps.management.idle_ttl_minutes' => 30,
        'api.apps.admin.idle_ttl_minutes' => 120,
        'api.idle_warning_seconds' => 45,
        'sanctum.expiration' => 480,
    ]);

    $setup = $this->postJson('/api/v1/admin/auth/setup', [
        'name' => 'Idle Admin',
        'email' => 'idle-admin@example.test',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ])->assertCreated();

    expect($setup->json('data.session'))->toBe([
        'idle_timeout_minutes' => 120,
        'warning_seconds' => 45,
        // Half the idle window, which is smaller than the 120-minute access TTL.
        'access_expires_in' => 3600,
    ]);

    $this->getJson('/api/v1/admin/auth/me', ws64Bearer($setup->json('data.access_token')))
        ->assertOk()
        ->assertJsonPath('data.session.idle_timeout_minutes', 120);
});

test('an idle window of zero restores the day-based fallback', function () {
    $this->freezeTime();
    config([
        'api.apps.management.idle_ttl_minutes' => 0,
        'api.apps.management.refresh_ttl_days' => 2,
        'api.apps.management.access_ttl_minutes' => 120,
        'sanctum.expiration' => 480,
    ]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $pair = ws64ManagementLogin($this, $owner->email);

    $refresh = RefreshToken::where('app', 'management')->latest('id')->sole();

    expect($refresh->expires_at->timestamp)->toBe(now()->addDays(2)->timestamp)
        // No idle cap in fallback mode: the configured access TTL stands.
        ->and($pair['expires_in'])->toBe(7200)
        ->and($pair['session']['idle_timeout_minutes'])->toBe(2880);
});

test('the policy reports the configured numbers for both consoles', function () {
    config([
        'api.apps.management.idle_ttl_minutes' => 45,
        'api.apps.admin.idle_ttl_minutes' => 90,
        'api.idle_warning_seconds' => 30,
        'sanctum.expiration' => 480,
    ]);

    expect(SessionPolicy::idleMinutes('management'))->toBe(45)
        ->and(SessionPolicy::idleMinutes('admin'))->toBe(90)
        ->and(SessionPolicy::warningSeconds())->toBe(30)
        ->and(SessionPolicy::accessTtlMinutes('management'))->toBe(23) // ceil(45 / 2)
        ->and(SessionPolicy::accessTtlMinutes('admin'))->toBe(45);
});
