<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| WS-51 — the console's theme, saved against the user
|--------------------------------------------------------------------------
| A theme is client state, and localStorage would hold it well enough on the
| machine it was chosen on. It is saved here because it has to survive a
| *different* machine — which is the whole feature — and only the account can do
| that.
|
| Three things are worth pinning: that each of the three positions round-trips,
| that an unknown one is refused rather than stored, and that it is the caller's
| own row and nobody else's.
*/

function th51Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

test('each of the three positions is saved and read back', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $token = th51Token($owner);

    // The default, which is the machine's own preference rather than the app's.
    $this->withToken($token)
        ->getJson('/api/v1/management/profile/theme')
        ->assertOk()
        ->assertJsonPath('data.theme', 'system');

    foreach (['dark', 'light', 'system'] as $theme) {
        $this->withToken($token)
            ->putJson('/api/v1/management/profile/theme', ['theme' => $theme])
            ->assertOk()
            ->assertJsonPath('data.theme', $theme);

        expect($owner->fresh()->management_theme)->toBe($theme);
    }
});

test('the choice rides on the auth payload, which is where the console reads it', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $token = th51Token($owner);

    $this->withToken($token)->putJson('/api/v1/management/profile/theme', ['theme' => 'dark'])->assertOk();

    // The console paints before this request lands — the local mirror covers the
    // first frame — but this is the one that follows the reader to another
    // browser, so it has to be on the payload the SPA already fetches.
    $this->withToken($token)
        ->getJson('/api/v1/management/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.theme', 'dark');
});

test('an unknown theme is refused rather than stored', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(th51Token($owner))
        ->putJson('/api/v1/management/profile/theme', ['theme' => 'midnight'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('theme');

    $this->withToken(th51Token($owner))
        ->putJson('/api/v1/management/profile/theme', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('theme');

    // Untouched — the default is still what a reader who has not chosen gets.
    expect($owner->fresh()->management_theme)->toBe('system');
});

test('the theme is the caller own and cannot be aimed at another user', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$other] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // No id in the payload, and none accepted — the row is always the caller's.
    $this->withToken(th51Token($owner))
        ->putJson('/api/v1/management/profile/theme', ['theme' => 'dark', 'user_id' => $other->id])
        ->assertOk();

    expect($owner->fresh()->management_theme)->toBe('dark')
        ->and($other->fresh()->management_theme)->toBe('system');
});

test('the console theme is not the terminal theme', function () {
    // Two columns on purpose: a dark till and a light back office are an
    // ordinary pair, and one column cannot hold both.
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $owner->forceFill(['theme_preference' => 'dark'])->save();

    $this->withToken(th51Token($owner))
        ->putJson('/api/v1/management/profile/theme', ['theme' => 'light'])
        ->assertOk();

    expect($owner->fresh()->management_theme)->toBe('light')
        ->and($owner->fresh()->theme_preference)->toBe('dark');
});

test('a lapsed owner can still change their own appearance', function () {
    // `profile.` is exempt from the subscription gate, so this is reachable
    // while everything else is refused — which is right: a reader held on the
    // plans screen should not be stuck on a theme they chose not to have.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->subDay()]);

    $owner->forceFill(['trial_ends_at' => now()->subDay()])->save();
    $business->forceFill(['welcome_seen_at' => now()])->save();

    $this->withToken(th51Token($owner->fresh()))
        ->putJson('/api/v1/management/profile/theme', ['theme' => 'dark'])
        ->assertOk();

    expect($owner->fresh()->management_theme)->toBe('dark');
});

test('the theme endpoints require authentication', function () {
    $this->getJson('/api/v1/management/profile/theme')->assertUnauthorized();
    $this->putJson('/api/v1/management/profile/theme', ['theme' => 'dark'])->assertUnauthorized();
});
