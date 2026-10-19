<?php

use App\Models\User;
use App\Support\SpaUrls;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| The log viewer is the single surface in this API reachable with a session
| cookie rather than a token. It used to have its own Blade password form; now
| the admin console mints a one-minute signed URL through its authenticated API
| and the browser redeems it. The checks that matter are therefore: who may
| mint a link, who may redeem one, and that both answers come from the same
| allow-list.
*/

function superadmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
    ], $attributes));
}

/** Named uniquely: Pest loads every test file into one process. */
function logViewerToken(User $user, array $abilities = ['admin']): string
{
    return $user->createToken('admin-access', $abilities, now()->addHour())->plainTextToken;
}

/**
 * @param  TestCase  $test
 */
function logViewerMint($test, string $token, string $query = ''): TestResponse
{
    return $test->getJson('/api/v1/admin/auth/log-viewer-link'.$query, [
        'Authorization' => 'Bearer '.$token,
    ]);
}

/**
 * The browser half of the handoff: no bearer token, and the default guard put
 * back to `web` (the API request above used Sanctum and leaves it changed).
 *
 * @param  TestCase  $test
 */
function logViewerRedeem($test, string $url): TestResponse
{
    $test->flushHeaders();
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return $test->get($url);
}

function logViewerUrl(User $user): string
{
    return URL::temporarySignedRoute('log-viewer.enter', now()->addMinute(), [
        'user' => $user->getKey(),
    ]);
}

test('the viewer answers on /logs and nowhere else', function () {
    expect(config('log-viewer.route_path'))->toBe('logs');

    $this->actingAs(superadmin(), 'web')->get('/logs')->assertOk();
    $this->actingAs(superadmin(), 'web')->get('/log-viewer')->assertNotFound();
});

test('a guest asking for the logs is sent to the login route', function () {
    $this->get('/logs')->assertRedirect(route('login'));
});

test('the login route hands the browser to the admin console', function () {
    config(['frontend.admin_url' => 'https://office.example.test']);

    $this->get('/login')->assertRedirect('https://office.example.test/login');
});

test('the old password login endpoint is gone', function () {
    $this->post('/login')->assertStatus(405);
});

test('an unauthenticated request cannot mint a link', function () {
    $this->getJson('/api/v1/admin/auth/log-viewer-link')->assertUnauthorized();
});

test('a management token cannot mint a link', function () {
    $token = logViewerToken(User::factory()->create(), ['management']);

    logViewerMint($this, $token)->assertForbidden();
});

test('a regular admin cannot mint a link under the default allow-list', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
    ]);

    logViewerMint($this, logViewerToken($admin))
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to the server logs.');
});

test('an inactive superadmin cannot mint a link', function () {
    logViewerMint($this, logViewerToken(superadmin(['status' => 'invited'])))
        ->assertForbidden();
});

test('a disabled viewer cannot mint a link or be opened', function () {
    config(['log-viewer.enabled' => false]);

    logViewerMint($this, logViewerToken(superadmin()))->assertForbidden();

    $this->actingAs(superadmin(), 'web')->get('/logs')->assertForbidden();
});

test('a superadmin can mint and redeem a link and open the viewer', function () {
    $admin = superadmin();

    $response = logViewerMint($this, logViewerToken($admin))->assertOk();
    $url = $response->json('data.url');

    expect($url)->toContain('user='.$admin->id)
        ->and($url)->toContain('signature=')
        ->and($response->json('data.expires_at'))->toBeString();

    logViewerRedeem($this, $url)->assertRedirect(route('log-viewer.index'));

    $this->assertAuthenticatedAs($admin, 'web');
    $this->get('/logs')->assertOk();
});

test('the minted link is bound to the token user, not a request parameter', function () {
    $admin = superadmin();
    $other = superadmin();

    // The endpoint takes no input, but a caller can always append a query
    // string. It must not be able to mint a link for somebody else.
    $url = logViewerMint($this, logViewerToken($admin), '?user='.$other->id)
        ->assertOk()
        ->json('data.url');

    expect($url)->toContain('user='.$admin->id)
        ->and($url)->not->toContain('user='.$other->id);

    logViewerRedeem($this, $url)->assertRedirect(route('log-viewer.index'));
    $this->assertAuthenticatedAs($admin, 'web');
});

test('a tampered signature is refused', function () {
    $url = logViewerUrl(superadmin());

    logViewerRedeem($this, preg_replace('/signature=[^&]+/', 'signature=not-a-signature', $url))
        ->assertForbidden();
});

test('a tampered user id is refused by the signature, not the route table', function () {
    $url = logViewerUrl(superadmin());
    $other = superadmin();

    $tampered = preg_replace('/([?&]user=)\d+/', '${1}'.$other->id, $url, 1);

    // 403, not 404: the signature covers the query string.
    logViewerRedeem($this, $tampered)->assertForbidden();
});

test('an expired link is refused', function () {
    $admin = superadmin();

    $url = URL::temporarySignedRoute('log-viewer.enter', now()->subMinute(), [
        'user' => $admin->getKey(),
    ]);

    logViewerRedeem($this, $url)->assertForbidden();
});

test('eligibility is re-checked when the link is redeemed', function () {
    $admin = superadmin();
    $url = logViewerUrl($admin);

    $admin->update(['status' => 'suspended']);

    logViewerRedeem($this, $url)->assertForbidden();
    $this->assertGuest('web');
});

test('a signed link for a non-superadmin is refused at entry', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
    ]);

    logViewerRedeem($this, logViewerUrl($admin))->assertForbidden();
});

test('the mint and the gate read the same allow-list', function () {
    config(['log-viewer.allowed_roles' => [User::ROLE_ADMIN]]);

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
    ]);

    $url = logViewerMint($this, logViewerToken($admin))->assertOk()->json('data.url');

    logViewerRedeem($this, $url)->assertRedirect(route('log-viewer.index'));
    $this->get('/logs')->assertOk();
});

test('the admin me payload reports the log viewer capability', function () {
    $admin = superadmin();

    $this->getJson('/api/v1/admin/auth/me', [
        'Authorization' => 'Bearer '.logViewerToken($admin),
    ])->assertOk()->assertJsonPath('data.capabilities.log_viewer', true);

    $regular = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
    ]);

    // The guard caches the user it resolved for the first request, so a second
    // call in the same test would otherwise still answer as the superadmin.
    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->getJson('/api/v1/admin/auth/me', [
        'Authorization' => 'Bearer '.logViewerToken($regular),
    ])->assertOk()
        ->assertJsonPath('data.user.role', User::ROLE_ADMIN)
        ->assertJsonPath('data.capabilities.log_viewer', false);
});

test('the capability is false when the viewer is disabled', function () {
    config(['log-viewer.enabled' => false]);

    $this->getJson('/api/v1/admin/auth/me', [
        'Authorization' => 'Bearer '.logViewerToken(superadmin()),
    ])->assertOk()->assertJsonPath('data.capabilities.log_viewer', false);
});

test('the mint response and the handoff redirect are not cacheable', function () {
    $admin = superadmin();

    $mint = logViewerMint($this, logViewerToken($admin))->assertOk();
    expect($mint->headers->get('Cache-Control'))->toContain('no-store');

    $handoff = logViewerRedeem($this, $mint->json('data.url'))
        ->assertRedirect(route('log-viewer.index'));

    expect($handoff->headers->get('Cache-Control'))->toContain('no-store');
});

test('signing out ends the session', function () {
    config(['frontend.admin_url' => 'https://office.example.test']);

    $this->actingAs(superadmin(), 'web')
        ->post('/logout')
        ->assertRedirect('https://office.example.test/login');

    $this->assertGuest('web');
    $this->get('/logs')->assertRedirect(route('login'));
});

test('a signed-in visitor to /login still lands on the admin console', function () {
    config(['frontend.admin_url' => 'https://office.example.test']);

    $this->actingAs(superadmin(), 'web')
        ->get('/login')
        ->assertRedirect('https://office.example.test/login');
});

test('the handoff route is registered outside the viewer catch-all', function () {
    expect(route('log-viewer.enter', [], false))->toBe('/log-viewer/enter');
    expect(SpaUrls::admin('/login'))->toBeString();
});
