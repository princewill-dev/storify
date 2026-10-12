<?php

use App\Models\Business;
use App\Models\Impersonation;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Facades\DB;

/*
| WS-40 — the step a business cannot get past until it says where its money goes.
|
| `nextStep()` is the single authority the management SPA obeys: whatever it
| returns is where the router sends the user, and `payout_account` is a screen
| that cannot be dismissed. That makes every branch here load-bearing, and the
| branches that *return false* more than the one that returns true — a false
| positive traps somebody on a form they cannot submit, with the sign-out link
| as the only way out.
|
| So this suite is mostly about who is deliberately not asked: staff, an admin
| impersonating the business, a member without the permission the endpoint
| demands, a business that still owes Storify money, and a platform whose
| selected processor this build cannot even run.
*/

function ps40Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function ps40Switch(?string $code): void
{
    ps40Settings(['system_payment_processor' => $code]);
}

function ps40PlatformBachs(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function ps40Token(User $user, array $abilities = ['management']): string
{
    return $user->createToken('management-access', $abilities, now()->addHour())->plainTextToken;
}

function ps40Next(User $user, ?string $token = null): string
{
    $response = test()->withToken($token ?? ps40Token($user))
        ->getJson('/api/v1/management/auth/me')
        ->assertOk();

    return (string) $response->json('data.next');
}

/**
 * The bank account the business named, without any account at a provider.
 */
function ps40PayoutBank(Business $business): StoreBank
{
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $business->forceFill(['payout_bank_id' => $bank->id, 'payout_entity_type' => 'company'])->save();

    return $bank;
}

/**
 * A usable account at the enforced processor — the state that ends the step.
 */
function ps40Managed(Business $business, string $code = 'bachs', string $accountId = 'acct_ps40'): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => true,
            'gateway_account_id' => $accountId,
            'config' => json_encode(['managed' => [
                'status' => 'active',
                'capabilities' => [
                    'card_collection' => 'active',
                    'ngn_card_collection' => 'active',
                    'bank_transfer' => 'active',
                    'payouts' => 'active',
                ],
                'requirements' => [],
                'payout_destination' => [
                    'id' => 'pd_ps40',
                    'is_usable' => true,
                    'currency' => 'NGN',
                    'bank_name' => 'Guaranty Trust Bank',
                    'account_name' => 'ADA TRADING LTD',
                    'last4' => '6789',
                ],
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function ps40Store(User $owner): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ps40-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('a business that has named no bank account is sent to the form', function () {
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect($business->payout_bank_id)->toBeNull()
        ->and(ps40Next($owner))->toBe('payout_account');
});

test('naming the bank account ends the step once the account is usable', function () {
    // Both halves, in the order they happen: naming the bank account alone is
    // not enough, because the business may have named one and have no account at
    // the processor now in force. What ends the step is the same fact the
    // resolver calls "can be charged and can be paid out".
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40PayoutBank($business);

    expect(ps40Next($owner))->toBe('payout_account');

    ps40Managed($business);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('a business that can sell but never named a bank account is asked once', function () {
    // Deliberately *not* decided by whether the business can sell. The two
    // questions have different owners: the admin's list of who cannot sell is
    // decided by capability, so an account that works is never reported broken
    // there — but the platform still does not know which of the business's bank
    // accounts its money lands in, and that is what this step collects.
    //
    // The migration backfills the pointer where the stored destination could be
    // matched to a `store_banks` row, so this is the remainder: a business whose
    // payouts were set up before the pointer existed. It is asked once, and
    // because the account at the provider already exists, what it submits
    // re-registers that same account rather than opening a second one.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40Managed($business);

    expect($business->payout_bank_id)->toBeNull()
        ->and(ps40Next($owner))->toBe('payout_account');

    ps40PayoutBank($business);

    // Naming one it already had is all it took — no second account, no waiting.
    expect(ps40Next($owner))->toBe('dashboard');
});

test('switching the processor re-prompts a business that had registered', function () {
    // The gap this design closes. A business registered on Bachs, switched to
    // Paystack, cannot take a payment and would previously have discovered that
    // by trying — with its bank account already named, so the form comes back
    // pre-filled and asks for one confirmation rather than a second account.
    config([
        'services.paystack.secret_key' => 'sk_live_platform1234567890',
        'services.paystack.public_key' => 'pk_live_platform1234567890',
    ]);
    ps40PlatformBachs();
    ps40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);
    ps40PayoutBank($business);
    ps40Managed($business, 'bachs');

    expect(ps40Next($owner))->toBe('payout_account');
});

test('with the switch off nobody is sent to the form', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('a stored processor this build cannot run is not a business\'s problem', function () {
    // Enforced in the database, unusable in this build. Nothing can be charged
    // through anything, and no submission to the form could succeed — so it is
    // an admin's to fix. Sending the business there would be a dead end with a
    // sign-out link at the bottom of it.
    ps40Switch('stripe');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('dashboard');
});

test('staff are never asked, whatever their business is missing', function () {
    ps40PlatformBachs();
    ps40Switch('bachs');

    [, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    expect(ps40Next($staff))->toBe('dashboard');
});

test('a member who could not submit the form is not locked in it', function () {
    // The screen is forced and cannot be dismissed, and the endpoint behind it
    // needs `settings payment`. Showing it to somebody the endpoint would refuse
    // is not a nudge — it is a trap. Money safety does not depend on this step:
    // the resolver offers nothing until an account can actually be charged, so
    // skipping them costs a notification rather than a payment.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $till = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
        // A paid-up business, so `plans` cannot be what answers this and the
        // permission check is genuinely the branch under test.
        'trial_ends_at' => now()->addWeek(),
    ]);

    setPermissionsTeamId($business->id);

    // Not staff, so it reaches the payout branch — and holds no permission there.
    $till->forceFill(['role' => User::ROLE_BUSINESS_OWNER])->save();

    expect($till->fresh()->can('settings payment'))->toBeFalse()
        ->and(ps40Next($till->fresh()))->toBe('dashboard');
});

test('an impersonated session is never intercepted', function () {
    // An admin debugging a business is not its owner: it cannot open a bank
    // account in the business's name and must not accept a provider's terms on
    // its behalf. It is also the only way an admin can see, from inside, that a
    // business is in the state the console reported.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ps40Store($owner);

    [$admin] = createBusinessOwner();

    $impersonation = Impersonation::create([
        'impersonator_id' => $admin->id,
        'impersonated_id' => $owner->id,
        'started_at' => now(),
    ]);

    $token = ps40Token($owner, ['management', Impersonation::ABILITY_PREFIX.$impersonation->id]);

    expect(ps40Next($owner, $token))->toBe('dashboard')
        // And the owner's own session is still asked, so the exemption is the
        // impersonation and not the business.
        ->and(ps40Next($owner))->toBe('payout_account');
});

test('a lapsed subscription is paid for before the business is asked anything', function () {
    // `plans` first, and that ordering is a fact about money rather than
    // politeness: subscription billing runs on the platform's own provider
    // credentials, so a business that cannot take payments can still pay Storify.
    // Asking it where its money should go first would leave it unable to do
    // either.
    ps40PlatformBachs();
    ps40Switch('bachs');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->subDay()]);
    ps40Store($owner);

    expect(ps40Next($owner))->toBe('plans');
});
