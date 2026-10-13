<?php

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\StoreBank;
use App\Models\User;
use App\Support\Payments\GatewayGrant;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-39 — who may connect a payment provider of their own.
|
| The platform's default is that a business's payments are processed for it:
| the owner registers a payout account, and Storify charges through its own
| provider account on the business's behalf. Connecting your own keys is the
| exception, granted per business by an admin — an enterprise that already has a
| merchant account.
|
| The rule is enforced server-side rather than in the screen, because the screen
| is not the only caller: WS-39 and the older WS-11 settings surface both write
| these connections, and a rule enforced on one of them is a detour rather than
| a rule.
*/

function gg39Token(User $user, string $audience = 'management'): string
{
    return $user->createToken($audience.'-access', [$audience], now()->addHour())->plainTextToken;
}

function gg39Payload(): array
{
    return [
        'is_enabled' => true,
        'config' => ['public_key' => 'pk_live_gg39abcdefgh', 'secret_key' => 'sk_live_gg39abcdefgh'],
    ];
}

function gg39KeyedRowCount(int $businessId): int
{
    return DB::table('business_payment_method')->where('business_id', $businessId)->count();
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('a new business cannot connect its own keys, and is told why', function () {
    // The platform default, asserted rather than assumed. The refusal names the
    // way out — support can enable it — because "forbidden" with no route
    // forward is how a business starts looking for a workaround.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    expect((bool) $business->can_connect_own_gateway)->toBeFalse();

    $this->withToken(gg39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', gg39Payload())
        ->assertStatus(422)
        ->assertJsonPath('errors.config.0', 'This business takes payments through Storify. Ask support to enable connecting your own payment provider.');

    expect(gg39KeyedRowCount((int) $business->id))->toBe(0);
});

test('the legacy settings surface refuses it too', function () {
    // WS-11 writes the same rows through a different service. If the rule lived
    // only in the WS-39 controller, this is the way round it.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(gg39Token($owner))
        ->postJson('/api/v1/management/payment-settings/gateways', [
            'public_key' => 'pk_live_gg39abcdefgh',
            'secret_key' => 'sk_live_gg39abcdefgh',
        ])
        ->assertStatus(422);

    expect(gg39KeyedRowCount((int) $business->id))->toBe(0);
});

test('the business that was refused can still name a bank to be paid into', function () {
    // The other half of the same refusal: being told to have Storify process
    // your payments is only reasonable if the route that says where the money
    // goes is open, and open to everybody rather than gated behind a grant.
    Http::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // An account the business already holds, named by id — the path the screen
    // posts. Typed details are resolved back to one of these and are not a way to
    // add a new one, so naming a bank means choosing from the business's own.
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(gg39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.success', true);

    // Held on the business rather than registered at a provider. There is no
    // account to open and no destination to create: the payout sweep reads this
    // pointer and sends from Storify's own balance, which is the whole reason a
    // business with a bank and no grant can still be paid.
    expect($business->fresh()->payout_bank_id)->toBe($bank->id);

    // And nothing was sent anywhere to achieve it. Pinned because the surest way
    // to reintroduce the deleted model is a helpful-looking call to open the
    // business an account, and this is where it would be noticed.
    Http::assertNothingSent();
});

test('a business granted the ability connects its own keys', function () {
    // The enterprise case, and the one that must not regress: an admin grants
    // it, and the connect flow is byte-for-byte what it was before the grant
    // existed.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    app(SpatiePermissionSeeder::class)->run();

    $admin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $admin->assignRole('Platform Admin');

    $this->withToken(gg39Token($admin, 'admin'))
        ->putJson('/api/v1/admin/businesses/'.$business->business_code.'/gateway-access', [
            'can_connect_own_gateway' => true,
            'reason' => 'Existing merchant account with Paystack.',
        ])
        ->assertOk()
        ->assertJsonPath('data.business.can_connect_own_gateway', true);

    expect(GatewayGrant::isGranted((int) $business->id))->toBeTrue()
        ->and(ActivityLog::where('action', 'business_gateway_access_granted')->exists())->toBeTrue();

    $this->withToken(gg39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', gg39Payload())
        ->assertOk();

    expect(gg39KeyedRowCount((int) $business->id))->toBe(1);
});

test('an admin can take the ability away again, and it is audited', function () {
    // Revoking does not delete the connection — the business keeps its keys and
    // stops being able to *change* them. Both directions write an activity row,
    // because "who turned this on for this business" is a question that arrives
    // months later.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->update(['can_connect_own_gateway' => true]);

    (new SpatiePermissionSeeder)->run();

    $admin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $admin->assignRole('Platform Admin');

    $this->withToken(gg39Token($admin, 'admin'))
        ->putJson('/api/v1/admin/businesses/'.$business->business_code.'/gateway-access', [
            'can_connect_own_gateway' => false,
            'reason' => 'Payment volumes moved to the platform.',
        ])
        ->assertOk()
        ->assertJsonPath('data.business.can_connect_own_gateway', false);

    expect(GatewayGrant::isGranted((int) $business->id))->toBeFalse()
        ->and(ActivityLog::where('action', 'business_gateway_access_revoked')->exists())->toBeTrue();

    // And the grant is now refused at the connect flow.
    $this->withToken(gg39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', gg39Payload())
        ->assertStatus(422);
});

test('an admin of another tenant cannot grant it', function () {
    // `admin.businesses` is a platform permission. A business owner holds no
    // role with it, so the route is closed to them even for their own business —
    // this is a permission the platform gives, not one a tenant takes.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(gg39Token($owner, 'admin'))
        ->putJson('/api/v1/admin/businesses/'.$business->business_code.'/gateway-access', [
            'can_connect_own_gateway' => true,
        ])
        ->assertForbidden();

    expect(GatewayGrant::isGranted((int) $business->id))->toBeFalse();
});

test('the grant is refused for a provider that has no keys to connect', function () {
    // Manual bank transfer's "credentials" are the business's own bank
    // accounts — there is no other kind. Gating it would remove a payment
    // method rather than protect anything, so it is deliberately exempt.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    expect(GatewayGrant::usesOwnKeys('bank_transfer'))->toBeFalse();

    GatewayGrant::assertMayConnectOwnKeys((int) $business->id, 'bank_transfer');

    expect(GatewayGrant::usesOwnKeys('bachs'))->toBeTrue();
});

test('a business created under the new default starts unable to connect keys', function () {
    // The policy the column's default carries. It is what the migration's
    // backfill exists to *not* take away from businesses that were already
    // charging through their own keys — those are set to true at deploy time by
    // the migration itself, which cannot be re-run here because the column it
    // adds already exists in this database.
    $business = Business::create([
        'user_id' => createBusinessOwner(['trial_ends_at' => now()->addWeek()])[0]->id,
        'name' => 'Fresh Business Ltd',
        'status' => 'active',
    ]);

    expect((bool) $business->fresh()->can_connect_own_gateway)->toBeFalse();
});
