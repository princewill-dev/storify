<?php

use App\Models\User;

/*
| The log viewer is the single surface in this API reachable with a session
| cookie rather than a token, so its gate is worth pinning down. The factory
| password is "password" (see UserFactory), and every user below is created
| active unless the test is specifically about an inactive one.
*/

function superadmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
    ], $attributes));
}

test('the viewer answers on /logs and nowhere else', function () {
    expect(config('log-viewer.route_path'))->toBe('logs');

    $this->actingAs(superadmin(), 'web')->get('/logs')->assertOk();
    $this->actingAs(superadmin(), 'web')->get('/log-viewer')->assertNotFound();
});

test('a guest asking for the logs is sent to the login page', function () {
    $this->get('/logs')->assertRedirect(route('login'));
});

test('the login page renders', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Log viewer');
});

test('a superadmin can sign in and open the log viewer', function () {
    $admin = superadmin();

    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('log-viewer.index'));

    $this->assertAuthenticatedAs($admin, 'web');

    $this->get('/logs')->assertOk();
});

test('bad credentials are rejected', function () {
    $admin = superadmin();

    $this->from('/login')
        ->post('/login', ['email' => $admin->email, 'password' => 'not-the-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

test('a non-superadmin cannot sign in even with the right password', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
    ]);

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    // The refusal has to leave no session behind: a cookie that survives the
    // error would be one the viewer's own gate is the only thing standing
    // between, and that is one check too few.
    $this->assertGuest('web');
    $this->get('/logs')->assertRedirect(route('login'));
});

test('an inactive superadmin cannot sign in', function () {
    $admin = superadmin(['status' => 'invited']);

    $this->from('/login')
        ->post('/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

test('a session that is not a superadmin is refused by the viewer itself', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
    ]);

    // Bypasses the login controller on purpose: this is the package's own gate
    // catching a session the controller never issued.
    $this->actingAs($user, 'web')->get('/logs')->assertForbidden();
});

test('the login and the gate read the same allow-list', function () {
    // Widening access in one place has to widen it in both, so the roles live
    // in config rather than being spelled out in the controller and the
    // provider separately.
    config(['log-viewer.allowed_roles' => [User::ROLE_ADMIN]]);

    $user = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
    ]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('log-viewer.index'));

    $this->get('/logs')->assertOk();
});

test('signing out ends the session', function () {
    $this->actingAs(superadmin(), 'web')
        ->post('/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest('web');
    $this->get('/logs')->assertRedirect(route('login'));
});

test('a signed-in visitor to the login page lands on the viewer', function () {
    // Authenticate::redirectTo() resolves route('login') by name to get guests
    // here, so the reverse direction has to be deliberate too.
    $this->actingAs(superadmin(), 'web')
        ->get('/login')
        ->assertRedirect(route('log-viewer.index'));
});
