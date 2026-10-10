<?php

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Facades\DB;

/*
| WS-39 — Payment gateways (the driver seam and the resolution rule).
|
| This covers the three defects the multi-gateway work exists to fix, all of
| which are about *which* provider is offered and *whose* keys are used:
|
|   1. an unassigned store was offered every platform-active gateway;
|   2. business credentials were read from a column that does not exist, so
|      checkout always charged through the platform account;
|   3. the POS kept its own hard-coded list (covered in the POS suite).
|
| (1) and (2) are money-routing bugs. They are asserted here rather than
| discovered in production.
*/

function ws39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ws39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

/**
 * Connect a provider for a business, storing credentials exactly as the
 * application does — through the cipher.
 */
function ws39Connect(Business $business, string $code, array $config, bool $active = true): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => $active,
            'config' => json_encode(CredentialCipher::encrypt($config, PaymentGatewayRegistry::secretKeysFor($code))),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function ws39Assign(Business $business, Store $store, string $code, bool $active = true, ?array $config = null): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('store_payment_method')->updateOrInsert(
        ['store_id' => $store->id, 'payment_method_id' => $method->id],
        [
            'business_id' => $business->id,
            'is_active' => $active,
            'config' => $config === null
                ? null
                : json_encode(CredentialCipher::encrypt($config, PaymentGatewayRegistry::secretKeysFor($code))),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function ws39Resolver(): PaymentGatewayResolver
{
    return app(PaymentGatewayResolver::class);
}

function ws39Paystack(): array
{
    return ['public_key' => 'pk_live_test1234567890', 'secret_key' => 'sk_live_test1234567890'];
}

beforeEach(function () {
    // Bring the catalogue up to date the way a deploy would.
    $this->seed(PaymentMethodSeeder::class);
});

test('a store with nothing configured is offered no payment methods at all', function () {
    // The defect: this used to fall back to every platform-active method, so an
    // unconfigured store was shown gateways its business had never connected —
    // and, with the key lookup broken, charged through the platform's account.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);

    expect(ws39Resolver()->forStore($store))->toBe([]);
});

test('a business-wide connection is inherited by a store that has no rows of its own', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    // A brand-new store assigns nothing: inheritance is structural.
    $store = ws39Store($owner);

    $usable = ws39Resolver()->forStore($store);

    expect($usable)->toHaveKey('paystack')
        ->and($usable['paystack']->source)->toBe('business')
        ->and($usable['paystack']->credentials->get('secret_key'))->toBe('sk_live_test1234567890');
});

test('a store override wins over the business default', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $store = ws39Store($owner);
    ws39Assign($business, $store, 'paystack', true, [
        'public_key' => 'pk_live_store0000000',
        'secret_key' => 'sk_live_store0000000',
    ]);

    $usable = ws39Resolver()->forStore($store);

    expect($usable['paystack']->source)->toBe('store')
        ->and($usable['paystack']->credentials->get('secret_key'))->toBe('sk_live_store0000000');
});

test('a store override without its own config still inherits the business credentials', function () {
    // This is what keeps every existing plain-assignment row working: a row
    // decides *whether* the provider is on, not necessarily with which keys.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $store = ws39Store($owner);
    ws39Assign($business, $store, 'paystack', true, null);

    $usable = ws39Resolver()->forStore($store);

    expect($usable['paystack']->source)->toBe('store')
        ->and($usable['paystack']->credentials->get('secret_key'))->toBe('sk_live_test1234567890');
});

test('a store can switch off a provider the business connected', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $store = ws39Store($owner);
    ws39Assign($business, $store, 'paystack', false);

    expect(ws39Resolver()->forStore($store))->not->toHaveKey('paystack');
});

test('a provider the platform has switched off is offered to nobody', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $store = ws39Store($owner);
    PaymentMethod::where('code', 'paystack')->update(['is_active' => false]);

    expect(ws39Resolver()->forStore($store))->not->toHaveKey('paystack');
});

test('a provider charging in another currency is not offered', function () {
    // Bitfra prices in USD, so it must never appear for an NGN store — this is
    // the currency gate, not a special case for any one provider.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    PaymentMethod::where('code', 'bitfra')->delete();
    PaymentMethod::create(['code' => 'bitfra', 'name' => 'Bitfra', 'type' => 'gateway', 'is_active' => true]);

    ws39Connect($business, 'bitfra', ['api_key' => 'bix-'.str_repeat('a', 28).'-'.str_repeat('b', 28), 'webhook_secret' => 'whsec_abcdefgh']);

    $ngnStore = ws39Store($owner);
    expect(ws39Resolver()->forStore($ngnStore))->not->toHaveKey('bitfra');

    $usd = DB::table('currencies')->where('code', 'USD')->first()
        ?? DB::table('currencies')->insertGetId(['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'created_at' => now(), 'updated_at' => now()]);

    $usdStoreId = is_object($usd) ? $usd->id : $usd;
    $usdStore = ws39Store($owner, ['currency_id' => $usdStoreId]);

    expect(ws39Resolver()->forStore($usdStore))->toHaveKey('bitfra');
});

test('a connection with incomplete credentials is not offered', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // Public key only — no secret to charge with.
    DB::table('business_payment_method')->insert([
        'business_id' => $business->id,
        'payment_method_id' => PaymentMethod::where('code', 'paystack')->value('id'),
        'is_active' => true,
        'config' => json_encode(['public_key' => 'pk_live_abcdefghij']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(ws39Resolver()->forStore(ws39Store($owner)))->not->toHaveKey('paystack');
});

test('another business connection is never usable by this one', function () {
    [$ownerA, $businessA] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $businessB] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws39Connect($businessB, 'paystack', ws39Paystack());

    expect(ws39Resolver()->forStore(ws39Store($ownerA)))->toBe([]);
});

test('credentials are encrypted at rest and decrypt on read', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $raw = (string) DB::table('business_payment_method')->where('business_id', $business->id)->value('config');

    // The stored column must not contain the secret in cleartext...
    expect($raw)->not->toContain('sk_live_test1234567890')
        // ...while the public key is deliberately left readable.
        ->and($raw)->toContain('pk_live_test1234567890');

    // ...and the resolver must hand back the real value.
    $usable = ws39Resolver()->forStore(ws39Store($owner));
    expect($usable['paystack']->credentials->get('secret_key'))->toBe('sk_live_test1234567890');
});

test('encrypting twice does not double-encrypt', function () {
    $config = ['public_key' => 'pk_live_x', 'secret_key' => 'sk_live_y'];
    $secrets = PaymentGatewayRegistry::secretKeysFor('paystack');

    $once = CredentialCipher::encrypt($config, $secrets);
    $twice = CredentialCipher::encrypt($once, $secrets);

    expect($twice['secret_key'])->toBe($once['secret_key'])
        ->and(CredentialCipher::decrypt($twice, $secrets)['secret_key'])->toBe('sk_live_y');
});

test('credentials never render their secret when dumped', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $usable = ws39Resolver()->forStore(ws39Store($owner));
    $dump = print_r($usable['paystack']->credentials->__debugInfo(), true);

    expect($dump)->not->toContain('sk_live_test1234567890')
        ->and($dump)->toContain('pk_live_test1234567890');
});

test('the management state reports where each setting comes from', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $store = ws39Store($owner);
    ws39Assign($business, $store, 'bank_transfer', true);

    $states = collect(ws39Resolver()->states((int) $business->id, $store))->keyBy('code');

    expect($states['paystack']['source'])->toBe('business')
        ->and($states['paystack']['business_connected'])->toBeTrue()
        ->and($states['bank_transfer']['source'])->toBe('store')
        ->and($states['bank_transfer']['store_overrides'])->toBeTrue()
        // Masked only — the full secret must never reach a response payload.
        ->and(json_encode($states))->not->toContain('sk_live_test1234567890');
});

test('a provider with no driver is not seeded and reads as unavailable', function () {
    // Guards the rollout: a half-finished integration must not offer a gateway
    // that would fail at checkout.
    //
    // Bachs is the live example. Its published API requires pre-created product
    // ids rather than an amount, and does not document payment retrieval,
    // webhook signing or refunds — so it is registered (its card and fee are
    // real information) but has no driver, and therefore no `payment_methods`
    // row, so it can never be connected.
    expect(PaymentMethod::where('code', 'bachs')->exists())->toBeFalse()
        ->and(PaymentGatewayRegistry::has('bachs'))->toBeTrue()
        ->and(app(PaymentGatewayManager::class)->isImplemented('bachs'))->toBeFalse();
});

/* ------------------------------------------------- management API surface */

function ws39Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

test('the gateway catalogue lists each provider with its fee', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $response = $this->withToken(ws39Token($owner))
        ->getJson('/api/v1/management/payment-gateways')
        ->assertOk();

    $catalogue = collect($response->json('data.catalogue'))->keyBy('code');

    expect($catalogue)->toHaveKeys(['bank_transfer', 'paystack'])
        // The fee is the reason the screen exists — a provider without one, or
        // with an empty string, would render a blank card.
        ->and($catalogue['paystack']['fee_label'])->not->toBeEmpty()
        ->and($catalogue['monnify']['fields'])->toHaveCount(3)
        // The browser has no business holding the validation patterns.
        ->and($catalogue['paystack']['fields'][0])->not->toHaveKey('rules');
});

test('connecting a provider at business scope encrypts its secret', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', [
            'is_enabled' => true,
            'config' => ws39Paystack(),
        ])
        ->assertOk();

    $raw = (string) DB::table('business_payment_method')->where('business_id', $business->id)->value('config');

    expect($raw)->not->toContain('sk_live_test1234567890')
        ->and($raw)->toContain('pk_live_test1234567890');
});

test('an edit that leaves the secret blank keeps the stored one', function () {
    // The form cannot echo a secret back, so a submit arrives with it empty.
    // Treating that as "clear it" would break every live connection the first
    // time someone changed a public key.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $this->withToken(ws39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', [
            'is_enabled' => true,
            'config' => ['public_key' => 'pk_live_changed00000', 'secret_key' => ''],
        ])
        ->assertOk();

    $store = ws39Store($owner);
    $usable = ws39Resolver()->forStore($store);

    expect($usable['paystack']->credentials->get('secret_key'))->toBe('sk_live_test1234567890');
});

test('a store override can be connected and disconnected through the API', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $store = ws39Store($owner);

    $this->withToken(ws39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', [
            'is_enabled' => false,
            'store_id' => $store->id,
            'config' => ws39Paystack(),
        ])
        ->assertOk();

    expect(ws39Resolver()->forStore($store))->not->toHaveKey('paystack');

    $this->withToken(ws39Token($owner))
        ->deleteJson("/api/v1/management/payment-gateways/paystack?store_id={$store->id}")
        ->assertOk();

    // Falls back to the business default, not to off.
    expect(ws39Resolver()->forStore($store))->toHaveKey('paystack');
});

test('another business store cannot be configured', function () {
    [$ownerA] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $businessB] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $storeB = ws39Store(User::where('business_id', $businessB->id)->firstOrFail());

    $this->withToken(ws39Token($ownerA))
        ->putJson('/api/v1/management/payment-gateways/paystack', [
            'is_enabled' => true,
            'store_id' => $storeB->id,
            'config' => ws39Paystack(),
        ])
        ->assertForbidden();
});

test('an unknown provider is a 404', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/not_a_provider', [
            'is_enabled' => true,
            'config' => ['x' => 'y'],
        ])
        ->assertNotFound();
});

test('a malformed credential is refused with a message naming the field', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', [
            'is_enabled' => true,
            'config' => ['public_key' => 'pk_live_ok1234567890', 'secret_key' => 'sk_live_x";drop'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('config.secret_key');
});

test('testing an unconnected provider says so rather than calling out', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws39Token($owner))
        ->postJson('/api/v1/management/payment-gateways/paystack/test')
        ->assertOk()
        ->assertJsonPath('data.success', false);
});

test('the management payload never carries a full secret', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    $response = $this->withToken(ws39Token($owner))
        ->getJson('/api/v1/management/payment-gateways')
        ->assertOk();

    expect($response->getContent())->not->toContain('sk_live_test1234567890')
        ->and($response->json('data.providers'))->toBeArray()
        // The webhook URL travels in the same payload, so the same rule applies
        // to it: it selects which stored secret a signature is tested against,
        // and must never carry any part of one.
        ->and(json_encode(collect($response->json('data.providers'))->pluck('webhook_url')->all()))
        ->not->toContain('sk_live');
});

/* --------------------------------------- what the clients are actually given */

test('the storefront is told how each method is paid, not who the provider is', function () {
    // The client branches on `mode`, so it never has to recognise provider
    // names. The old check (`type === 'gateway' || code.includes('paystack')`)
    // sent every gateway to Paystack's checkout.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);

    DB::table('store_banks')->insert([
        'business_id' => $business->id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'TEST LTD',
        'is_primary' => true,
        'is_verified' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ws39Connect($business, 'paystack', ws39Paystack());
    ws39Assign($business, $store, 'bank_transfer', true);

    $methods = collect(
        $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')
            ->assertOk()
            ->json('data.payment_methods')
    )->keyBy('code');

    expect($methods)->toHaveKeys(['paystack', 'bank_transfer'])
        ->and($methods['paystack']['mode'])->toBe('redirect')
        ->and($methods['bank_transfer']['mode'])->toBe('offline');
});

test('the pos is offered cash plus whatever the store actually accepts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);

    ws39Connect($business, 'paystack', ws39Paystack());

    $token = $owner->createToken('pos-access', ['pos'], now()->addHour())->plainTextToken;

    $methods = collect(
        $this->withToken($token)
            ->getJson('/api/v1/pos/stores/'.$store->store_id.'/payment-methods')
            ->assertOk()
            ->json('data.payment_methods')
    )->keyBy('code');

    // Cash is the till's own and always present.
    expect($methods)->toHaveKey('cash')
        ->and($methods)->toHaveKey('paystack')
        // A provider this store never connected must not appear at the till,
        // and neither must one with no driver behind it.
        ->and($methods)->not->toHaveKey('bitfra')
        ->and($methods)->not->toHaveKey('bachs');
});

/* ------------------------------------------------------ the admin switch */

test('an admin switching a provider off stops it being offered', function () {
    // The admin console already owns this toggle; what it drives is
    // `payment_methods.is_active`, and the resolver is what turns that into
    // "the business cannot use this any more".
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);

    ws39Connect($business, 'paystack', ws39Paystack());
    expect(ws39Resolver()->forStore($store))->toHaveKey('paystack');

    PaymentMethod::where('code', 'paystack')->update(['is_active' => false]);

    expect(ws39Resolver()->forStore($store))->not->toHaveKey('paystack');

    // The storefront list is the same resolver, so it agrees without anything
    // else being changed.
    $methods = $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')
        ->assertOk()
        ->json('data.payment_methods');

    expect(collect($methods)->pluck('code'))->not->toContain('paystack');
});

test('a switched-off provider is reported as disabled, not as unbuilt', function () {
    // The two look identical to a boolean "available" flag but mean different
    // things to the screen: one should be hidden, the other explained. A
    // business that already connected it must be told why it stopped working
    // rather than finding the card gone.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'paystack', ws39Paystack());

    PaymentMethod::where('code', 'paystack')->update(['is_active' => false]);

    $states = collect(ws39Resolver()->states((int) $business->id))->keyBy('code');

    expect($states['paystack']['available'])->toBeFalse()
        ->and($states['paystack']['disabled_by_platform'])->toBeTrue()
        ->and($states['paystack']['unavailable_reason'])->toContain('platform');

    // A provider with no driver at all is unavailable for a different reason.
    expect($states['bachs']['available'])->toBeFalse()
        ->and($states['bachs']['disabled_by_platform'])->toBeFalse();
});
