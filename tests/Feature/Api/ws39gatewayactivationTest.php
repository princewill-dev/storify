<?php

use App\Models\Business;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use App\Services\Payments\PaymentGatewayResolver;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Facades\DB;

/*
| WS-39 — stopping and resuming the payments Storify collects.
|
| A business being collected for has no keys of its own to switch off and nothing
| at the provider to delete, so when this endpoint did not exist there was no way
| for it to stop. What it writes is a single switch, and the file is here rather
| than beside the rest of the gateway tests because the switch is the *only*
| surviving thing a per-business payment-method row does: under collect-and-pay-out
| a business needs no row at all to be charged, so a row exists to say "no" and for
| nothing else.
|
| ## No row is the same answer as a row that says on
|
| This is the part worth pinning. The resolver reads an absent row as "nobody
| objected", which is what makes a business that has never opened this screen
| chargeable the moment an admin enforces a processor. So switching *on* creates
| no row, and the tests below assert that rather than a row with `is_active = true`
| — the two are not equivalent, because only one of them can be read as a
| statement somebody made.
|
| ## It stops new charges and nothing else
|
| Money already collected keeps being paid out. Holding it back over a decision
| about new sales would take the business's own money hostage to it, so the
| payout sweep deliberately does not consult this switch.
*/

function ga39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ga39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function ga39Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * The processor in force, written the way the admin screen writes it: a column
 * on the single settings row rather than a key/value pair. Null is "no processor
 * enforced", which is the state the refusal test needs.
 */
function ga39Processor(?string $code): void
{
    $settings = Setting::query()->first() ?? Setting::query()->create(['company_name' => 'Storify']);

    $settings->update(['system_payment_processor' => $code]);
}

/**
 * A business the platform is actually collecting for: under custody, on the
 * processor in force, with keys on the server. Without all three the switch
 * refuses, so this is the precondition every test below starts from.
 */
function ga39Collected(string $provider = 'bachs', array $attributes = []): array
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);

    ga39Processor($provider);

    [$owner, $business] = createBusinessOwner(array_merge([
        'trial_ends_at' => now()->addWeek(),
    ], $attributes));

    $business->forceFill(['custody_enabled' => true])->save();

    return [$owner, $business->refresh()];
}

function ga39Switch(int $businessId, ?int $storeId): mixed
{
    return DB::table($storeId === null ? 'business_payment_method' : 'store_payment_method')
        ->when($storeId === null,
            fn ($q) => $q->where('business_id', $businessId),
            fn ($q) => $q->where('store_id', $storeId))
        ->where('payment_method_id', function ($q) {
            $q->select('id')->from('payment_methods')->where('code', 'bachs');
        })
        ->first();
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('a collected business can stop taking payments without giving up its payout bank', function () {
    [$owner, $business] = ga39Collected();

    // Named before the switch, because the question this answers is whether
    // stopping costs it. It must not: the destination is where money already
    // collected is going, and that is a different decision from "sell no more".
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);
    $business->forceFill(['payout_bank_id' => $bank->id])->save();

    $this->withToken(ga39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => false])
        ->assertOk();

    $row = ga39Switch((int) $business->id, null);

    expect($row)->not->toBeNull()
        ->and((bool) $row->is_active)->toBeFalse()
        // The bank survives the switch. If these two were ever coupled, stopping
        // sales would also strand the money already owed.
        ->and((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id);
});

test('switching back on writes no row, because a missing row already means on', function () {
    // The shape of the whole thing. A row saying `is_active = true` and no row
    // at all resolve to the same answer, so writing one would be recording a
    // statement nobody made — and would give `carriesConnection()` a row to
    // speculate about.
    [$owner, $business] = ga39Collected();
    $token = ga39Token($owner);

    $this->withToken($token)
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => false])
        ->assertOk();

    expect(ga39Switch((int) $business->id, null))->not->toBeNull();

    $this->withToken($token)
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => true])
        ->assertOk();

    $row = ga39Switch((int) $business->id, null);

    expect((bool) $row->is_active)->toBeTrue()
        ->and($row->config)->toBeNull();
});

test('a store can stop on its own without stopping the business', function () {
    // The effective row is the store's when it has one, so a store's switch
    // stops that store and leaves its siblings trading — which is the whole
    // reason the precedence rule exists.
    [$owner, $business] = ga39Collected();
    $store = ga39Store($owner);

    $this->withToken(ga39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', [
            'store_id' => $store->id,
            'is_active' => false,
        ])
        ->assertOk();

    $storeRow = ga39Switch((int) $business->id, (int) $store->id);

    expect($storeRow)->not->toBeNull()
        ->and((bool) $storeRow->is_active)->toBeFalse()
        // The business's own row was not written on the store's behalf, so its
        // other stores still inherit "on".
        ->and(ga39Switch((int) $business->id, null))->toBeNull();
});

test('stopping is refused when there is nothing to stop, and says so', function () {
    // A business that is not being collected for has no charges of ours to stop.
    // The refusal matters more than the status: a row saying off written here
    // would lie in wait for the day an admin flips the business, blocking its
    // sales over a decision taken about a rail it was not on.
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);

    ga39Processor(null);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->forceFill(['custody_enabled' => false])->save();

    $this->withToken(ga39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('provider');

    expect(ga39Switch((int) $business->id, null))->toBeNull();
});

test('is_active is required', function () {
    [$owner] = ga39Collected();

    $this->withToken(ga39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_active');
});

test('the connection cannot be removed while Storify is collecting through it', function () {
    // Deleting the row would delete the only place the switch-off is written and
    // silently resume the business's sales. Switching off is the way to stop, and
    // it is reversible; this is the guard that makes sure of it.
    [$owner, $business] = ga39Collected();

    $this->withToken(ga39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => false])
        ->assertOk();

    $this->withToken(ga39Token($owner))
        ->deleteJson('/api/v1/management/payment-gateways/bachs')
        ->assertStatus(422)
        ->assertJsonValidationErrors('provider');

    expect(ga39Switch((int) $business->id, null))->not->toBeNull()
        ->and((bool) ga39Switch((int) $business->id, null)->is_active)->toBeFalse();
});

test('a business on its own keys can still remove a connection', function () {
    // The guard above is about the processor Storify collects through, not about
    // connections in general. A business charging through its own keys has
    // nothing of ours attached to the row, so removing it is an ordinary act and
    // has to keep working.
    config(['services.bachs.api_key' => null, 'services.bachs.secret_key' => null]);

    ga39Processor(null);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->forceFill(['custody_enabled' => false])->save();

    DB::table('business_payment_method')->insert([
        'business_id' => $business->id,
        'payment_method_id' => DB::table('payment_methods')->where('code', 'bachs')->value('id'),
        'is_active' => true,
        'config' => json_encode(['secret_key' => 'sk_live_own']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken(ga39Token($owner))
        ->deleteJson('/api/v1/management/payment-gateways/bachs')
        ->assertOk();

    expect(ga39Switch((int) $business->id, null))->toBeNull();
});

test('a business with no row at all is still chargeable', function () {
    // The regression this whole file exists to prevent. `businesses
    // .custody_enabled` is where the collected fact lives and the row is only a
    // switch, so a business that has never opened the screen must resolve as
    // chargeable — for a rail it was never given a row on.
    [$owner, $business] = ga39Collected();

    expect(ga39Switch((int) $business->id, null))->toBeNull();

    $states = app(PaymentGatewayResolver::class)
        ->states((int) $business->id, null);

    $bachs = collect($states)->firstWhere('code', 'bachs');

    expect($bachs)->not->toBeNull()
        ->and($bachs['is_enabled'])->toBeTrue()
        ->and($bachs['custody'])->toBeTrue()
        ->and($bachs['is_paused'])->toBeFalse();
});

test('a business whose owner switched off is not chargeable, and says it is paused', function () {
    [$owner, $business] = ga39Collected();
    $store = ga39Store($owner);

    $this->withToken(ga39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => false])
        ->assertOk();

    $resolver = app(PaymentGatewayResolver::class);

    $businessStates = $resolver->states((int) $business->id, null);
    $bachs = collect($businessStates)->firstWhere('code', 'bachs');

    expect($bachs['is_enabled'])->toBeFalse()
        ->and($bachs['is_paused'])->toBeTrue();

    // And the store that inherits it is off too, because the effective row is
    // the business's when the store has none of its own.
    $storeStates = $resolver->states((int) $business->id, $store);
    $storeBachs = collect($storeStates)->firstWhere('code', 'bachs');

    expect($storeBachs['is_enabled'])->toBeFalse();
});
