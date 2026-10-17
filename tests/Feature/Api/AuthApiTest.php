<?php

use App\Models\Customer;
use App\Models\Otp;
use App\Models\RefreshToken;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

function apiOtp(string $email, string $type): string
{
    return (string) Otp::where('identifier', $email)->where('type', $type)->latest('id')->value('code');
}

/**
 * @return array<string, string>
 */
function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * A storefront to hang customer auth off.
 *
 * `has_website` is what `resolveStore()` gates on and it defaults to false, so
 * a store built without it is switched off and every storefront route answers
 * 404 — which, in a suite about tenant resolution, looks exactly like the bug
 * these tests exist to catch.
 */
function authStorefront(string $slug = 'auth-shop'): Store
{
    [, $business] = createBusinessOwner();

    return Store::create([
        'business_id' => $business->id,
        'name' => 'Auth Shop',
        'slug' => $slug,
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);
}

/**
 * The header the deployed storefront sends without knowing it does anything.
 *
 * The transitional `storefront/auth/*` routes name no store, and the SPA that
 * calls them has no way to — so the server reads the tenant off `Origin`, which
 * every browser request carries because the API is its own origin.
 *
 * @return array<string, string>
 */
function authOriginHeaders(Store $store): array
{
    return ['Origin' => 'https://'.$store->slug.'.'.config('frontend.storefront_main_domain')];
}

test('a business owner can register and verify their email via the api', function () {
    $this->postJson('/api/v1/management/auth/register', [
        'name' => 'Ada Writer',
        'email' => 'ada@example.test',
        'phone' => '08011112222',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ])->assertCreated()
        ->assertJsonPath('data.otp_required', true)
        ->assertJsonPath('data.email', 'ada@example.test');

    $user = User::where('email', 'ada@example.test')->sole();
    expect($user->is_verified)->toBeFalse()
        ->and($user->role)->toBe(User::ROLE_BUSINESS_OWNER);

    $code = apiOtp('ada@example.test', 'business_email_verification');

    $this->postJson('/api/v1/management/auth/verify-otp', [
        'email' => 'ada@example.test',
        'otp' => $code,
    ])->assertOk()
        ->assertJsonPath('data.context', 'business_email_verification')
        ->assertJsonStructure([
            'data' => ['access_token', 'refresh_token', 'expires_in', 'token_type', 'user' => ['id', 'email', 'role'], 'next'],
        ]);

    expect($user->fresh()->is_verified)->toBeTrue();
});

test('a verified business owner logs in through an otp challenge', function () {
    [$owner] = createBusinessOwner([
        'email' => 'owner-login@example.test',
        'is_verified' => true,
        'trial_ends_at' => now()->addWeek(),
    ]);

    // Wrong password
    $this->postJson('/api/v1/management/auth/login', [
        'email' => 'owner-login@example.test',
        'password' => 'wrong-password',
    ])->assertStatus(422);

    // Correct password → OTP challenge
    $this->postJson('/api/v1/management/auth/login', [
        'email' => 'owner-login@example.test',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.otp_required', true)
        ->assertJsonPath('data.context', 'business_login');

    $code = apiOtp('owner-login@example.test', 'business_login');

    $response = $this->postJson('/api/v1/management/auth/verify-otp', [
        'email' => 'owner-login@example.test',
        'otp' => $code,
    ])->assertOk();

    expect($response->json('data.access_token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('data.refresh_token'))->toBeString()
        // Every auth response carries the setup checklist, and the top-level
        // `next` is read off it rather than computed a second time.
        ->and($response->json('data.next'))->toBe('payment_setup')
        ->and($response->json('data.user.onboarding.next'))->toBe('payment_setup')
        ->and($response->json('data.user.onboarding.steps.kyc.done'))->toBeTrue()
        ->and($response->json('data.user.onboarding.steps.payouts.done'))->toBeFalse();

    // Access token works on /me
    $this->getJson('/api/v1/management/auth/me', bearer($response->json('data.access_token')))
        ->assertOk()
        ->assertJsonPath('data.user.email', 'owner-login@example.test')
        ->assertJsonPath('data.user.onboarding.next', 'payment_setup');
});

test('staff sign in directly without an otp challenge', function () {
    [$owner, $business] = createBusinessOwner();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
        'email' => 'cashier@example.test',
        'password' => 'secret-pass-123',
    ]);

    $this->postJson('/api/v1/management/auth/login', [
        'email' => 'cashier@example.test',
        'password' => 'secret-pass-123',
    ])->assertOk()
        ->assertJsonPath('data.user.role', 'staff')
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user' => ['stores']]]);
});

test('suspended accounts cannot sign in', function () {
    createBusinessOwner([
        'email' => 'suspended@example.test',
        'status' => 'suspended',
        'is_verified' => true,
    ]);

    $this->postJson('/api/v1/management/auth/login', [
        'email' => 'suspended@example.test',
        'password' => 'password',
    ])->assertStatus(403);
});

test('tokens are audience scoped', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // A user token carrying the wrong audience cannot access the management app.
    $wrongAudience = $owner->createToken('wrong', ['pos'], now()->addHour())->plainTextToken;

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/management/auth/me', bearer($wrongAudience))
        ->assertStatus(403);

    // A customer token is not a valid user token for user routes.
    $customer = Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.test',
        'phone' => '08000000000',
        'password' => 'secret-pass-123',
        'status' => 'active',
    ]);

    $customerToken = $customer->createToken('customer-access', ['customer'], now()->addHour())->plainTextToken;

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/management/auth/me', bearer($customerToken))
        ->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/storefront/auth/me', bearer($customerToken))
        ->assertOk()
        ->assertJsonPath('data.user.email', 'jane@example.test');
});

test('refresh tokens rotate and reused tokens revoke the family', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $login = $this->postJson('/api/v1/management/auth/login', [
        'email' => $owner->email,
        'password' => 'password',
    ]);

    $code = apiOtp($owner->email, 'business_login');

    $verify = $this->postJson('/api/v1/management/auth/verify-otp', [
        'email' => $owner->email,
        'otp' => $code,
    ])->assertOk();

    $refresh = $verify->json('data.refresh_token');

    // Rotate
    $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in']]);

    $newRefresh = $rotated->json('data.refresh_token');
    expect($newRefresh)->not->toBe($refresh);

    // Reusing the old refresh token must fail and revoke the whole family
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
        ->assertStatus(401);

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $newRefresh])
        ->assertStatus(401);

    expect(RefreshToken::where('revoked_at', '!=', null)->count())->toBeGreaterThan(0);
});

test('logout revokes the access token', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->postJson('/api/v1/management/auth/login', [
        'email' => $owner->email,
        'password' => 'password',
    ]);

    $verify = $this->postJson('/api/v1/management/auth/verify-otp', [
        'email' => $owner->email,
        'otp' => apiOtp($owner->email, 'business_login'),
    ])->assertOk();

    $token = $verify->json('data.access_token');
    $refresh = $verify->json('data.refresh_token');
    $tokenId = (int) explode('|', $token, 2)[0];

    $this->getJson('/api/v1/management/auth/me', bearer($token))->assertOk();

    $this->postJson('/api/v1/management/auth/logout', ['refresh_token' => $refresh], bearer($token))
        ->assertOk();

    expect(PersonalAccessToken::find($tokenId))->toBeNull();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/management/auth/me', bearer($token))->assertUnauthorized();
});

test('an admin can set up, log in and impersonate a user', function () {
    $setup = $this->postJson('/api/v1/admin/auth/setup', [
        'name' => 'Platform Admin',
        'email' => 'root@example.test',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ])->assertCreated();

    $adminToken = $setup->json('data.access_token');

    $this->getJson('/api/v1/admin/auth/me', bearer($adminToken))
        ->assertOk()
        ->assertJsonPath('data.user.role', 'superadmin');

    [$owner] = createBusinessOwner();

    // Impersonate the business owner
    $impersonated = $this->postJson(
        '/api/v1/admin/users/'.$owner->account_code.'/impersonate',
        [],
        bearer($adminToken)
    )->assertOk()
        ->assertJsonPath('data.user.id', $owner->id)
        ->assertJsonPath('data.impersonation.impersonator.name', 'Platform Admin');

    $impersonatedToken = $impersonated->json('data.access_token');

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/management/auth/me', bearer($impersonatedToken))
        ->assertOk()
        ->assertJsonPath('data.user.email', $owner->email);

    // Stop impersonation → admin token again
    $stopped = $this->postJson('/api/v1/management/auth/stop-impersonation', [], bearer($impersonatedToken))
        ->assertOk();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/admin/auth/me', bearer($stopped->json('data.access_token')))
        ->assertOk()
        ->assertJsonPath('data.user.role', 'superadmin');
});

test('customers can register, verify and log in', function () {
    // The transitional tenant-less routes, driven exactly as the storefront
    // already deployed drives them: no store anywhere, just the `Origin` a
    // browser sends anyway. This is what has to keep working until that bundle
    // is replaced, so it is asserted here rather than only in the WS-56 suite.
    $store = authStorefront();
    $headers = authOriginHeaders($store);

    $this->postJson('/api/v1/storefront/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Shopper',
        'email' => 'shopper@example.test',
        'phone' => '08012345678',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ], $headers)->assertCreated()
        ->assertJsonPath('data.otp_required', true);

    $verify = $this->postJson('/api/v1/storefront/auth/verify-otp', [
        'email' => 'shopper@example.test',
        'otp' => apiOtp('shopper@example.test', 'account'),
    ], $headers)->assertOk();

    $token = $verify->json('data.access_token');

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/storefront/auth/me', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.user.email', 'shopper@example.test');

    // Subsequent login uses password directly
    $this->postJson('/api/v1/storefront/auth/login', [
        'email' => 'shopper@example.test',
        'password' => 'secret-pass-123',
    ], $headers)->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user' => ['id', 'email']]]);
});

test('a customer account registered through a storefront belongs to that store', function () {
    // The canonical shape: the store is in the path, as it is for catalog and
    // cart, and the account it creates is scoped to that store's business.
    $store = authStorefront('canonical-shop');

    $this->postJson('/api/v1/storefront/'.$store->slug.'/auth/register', [
        'first_name' => 'Ada',
        'last_name' => 'Buyer',
        'email' => 'canonical@example.test',
        'phone' => '08012345678',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ])->assertCreated()
        ->assertJsonPath('data.otp_required', true);

    $customer = Customer::where('email', 'canonical@example.test')->sole();

    expect($customer->business_id)->toBe($store->business_id)
        ->and($customer->registered_at)->not->toBeNull();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/auth/verify-otp', [
        'email' => 'canonical@example.test',
        'otp' => apiOtp('canonical@example.test', 'account'),
    ])->assertOk()
        ->assertJsonPath('data.user.business_id', $store->business_id);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/auth/login', [
        'email' => 'canonical@example.test',
        'password' => 'secret-pass-123',
    ])->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user' => ['id', 'email']]]);
});

test('storefront auth refuses a request that names no store', function () {
    // Nothing in the body, no header, no Origin. This used to resolve a row by
    // email alone across every business on the platform, which is the defect
    // the scoping closes; the honest answer is now to say so.
    $this->postJson('/api/v1/storefront/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Shopper',
        'email' => 'nowhere@example.test',
        'phone' => '08012345678',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ])->assertStatus(422)
        ->assertJsonPath('errors.store.0', 'store_unresolved');

    expect(Customer::where('email', 'nowhere@example.test')->exists())->toBeFalse();
});

test('the storefront auth route names resolve to the canonical routes', function () {
    // Both route sets are registered and the transitional one is name-infixed,
    // so the canonical name is the one the application resolves. Without the
    // infix the later registration silently wins and this is the assertion that
    // notices.
    expect(route('api.storefront.auth.register', ['store' => 'swift-one']))
        ->toEndWith('/api/v1/storefront/swift-one/auth/register')
        ->and(route('api.storefront.auth.legacy.register'))
        ->toEndWith('/api/v1/storefront/auth/register');
});

test('a staff invitation can be inspected and accepted through the api', function () {
    [$owner, $business] = createBusinessOwner();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'invited',
        'is_verified' => true,
        'business_id' => $business->id,
        'email' => 'invited@example.test',
        'invitation_token' => str_repeat('i', 64),
        'invited_at' => now(),
    ]);

    $this->getJson('/api/v1/management/invitations/'.str_repeat('i', 64))
        ->assertOk()
        ->assertJsonPath('data.email', 'invited@example.test');

    $this->postJson('/api/v1/management/invitations/'.str_repeat('i', 64), [
        'name' => 'Invited Staff',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ])->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user']]);

    $fresh = $staff->fresh();
    expect($fresh->status)->toBe('active')
        ->and($fresh->invitation_token)->toBeNull()
        ->and(Hash::check('secret-pass-123', $fresh->password))->toBeTrue();
});

test('auth endpoints are rate limited', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/management/auth/login', [
            'email' => 'ratelimit@example.test',
            'password' => 'whatever',
        ]);
    }

    $this->postJson('/api/v1/management/auth/login', [
        'email' => 'ratelimit@example.test',
        'password' => 'whatever',
    ])->assertStatus(429);
});
