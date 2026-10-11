<?php

use App\Models\Business;
use App\Models\ExchangeRate;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\ExchangeRateService;
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
/**
 * Allow this business to keep gateway keys of its own.
 *
 * The platform default is that Storify processes a business's payments through
 * its connected provider account and pays the business out to its own bank, so
 * connecting own keys is refused until an admin grants it — the one permission
 * in this area that is the platform's to give, not the business's to take. These
 * tests are about the connecting rather than about the permission, so they grant
 * it up front the way the admin console does.
 *
 * Set directly on the row rather than through the admin endpoint: that endpoint
 * has its own suite, and routing these tests through it would make every one of
 * them fail for a reason that has nothing to do with what they assert.
 */
function ws39GrantOwnGateway(Business $business): void
{
    $business->update(['can_connect_own_gateway' => true]);
}

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

/**
 * The id of the USD currency row, created if this suite has not seeded one.
 *
 * These tests build stores by hand and do not run the currency seeder, so the
 * row is not guaranteed to be there.
 */
function ws39UsdCurrencyId(): int
{
    return (int) (DB::table('currencies')->where('code', 'USD')->value('id')
        ?? DB::table('currencies')->insertGetId([
            'name' => 'US Dollar',
            'code' => 'USD',
            'symbol' => '$',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
}

function ws39Resolver(): PaymentGatewayResolver
{
    // The service caches the rate table for ten minutes, so a test that seeds a
    // rate would otherwise be answered from the previous test's copy.
    ExchangeRateService::forget();

    return app(PaymentGatewayResolver::class);
}

/**
 * Store a USD-based rate, or clear the one for `$quote` when `$rate` is null.
 *
 * Null is a case worth testing rather than an absence: the fetch job having
 * never run is exactly the state a fresh deployment is in, and it must hide the
 * gateway rather than price it.
 */
function ws39Rate(string $quote, ?float $rate): void
{
    if ($rate === null) {
        ExchangeRate::where('base', 'USD')->where('quote', $quote)->delete();

        return;
    }

    ExchangeRate::updateOrCreate(
        ['base' => 'USD', 'quote' => $quote],
        ['rate' => (string) $rate, 'fetched_at' => now()],
    );
}

function ws39Paystack(): array
{
    return ['public_key' => 'pk_live_test1234567890', 'secret_key' => 'sk_live_test1234567890'];
}

function ws39BachsConfig(): array
{
    return ['api_key' => 'sk_sandbox_abcdefghij', 'webhook_secret' => 'whsec_test1234567890'];
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

test('a provider charging in another currency is offered to a store it can be priced for', function () {
    // Bitfra prices in USD. A naira store may take it — the amount is converted
    // at the daily rate — so the gate is now "can we price this", not "do the
    // currencies match".
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws39Connect($business, 'bitfra', ['api_key' => 'bix-'.str_repeat('a', 28).'-'.str_repeat('b', 28), 'webhook_secret' => 'whsec_abcdefgh']);

    // A dollar store needs no rate: its own currency is what Bitfra charges.
    $usdStore = ws39Store($owner, ['currency_id' => ws39UsdCurrencyId()]);

    expect(ws39Resolver()->forStore($usdStore))->toHaveKey('bitfra');

    // A naira store needs one, and has none yet.
    ws39Rate('NGN', null);
    $ngnStore = ws39Store($owner);

    expect(ws39Resolver()->forStore($ngnStore))->not->toHaveKey('bitfra');

    ws39Rate('NGN', 1331.267014);

    expect(ws39Resolver()->forStore($ngnStore))->toHaveKey('bitfra');
});

test('a connected provider held back only for want of a rate says so', function () {
    // This is the only remaining reason a *connected* provider is withheld, and
    // it is not something the merchant can fix from the settings screen — the
    // message has to say that, because a blank card is what made the original
    // report take this long to diagnose.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);
    ws39Connect($business, 'bitfra', ['api_key' => 'bix-'.str_repeat('a', 28).'-'.str_repeat('b', 28), 'webhook_secret' => 'whsec_abcdefgh']);

    // The currency check is per store, so the screen reaches it by passing the
    // store in scope — which is what the management panel does.
    $states = collect(ws39Resolver()->states((int) $business->id, $store))->keyBy('code');

    $reason = (string) $states['bitfra']['unavailable_reason'];

    expect(str_contains($reason, 'USD'))->toBeTrue()
        ->and(str_contains($reason, 'rate'))->toBeTrue();
});

test('an offline provider is still confined to the store currency', function () {
    // Nothing converts for a bank slip: there is no provider to hand a
    // converted figure to, and the storefront path takes the amount off the
    // uploaded slip in naira. Converting the check for redirect providers must
    // not have loosened it for these.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $usdStore = ws39Store($owner, ['currency_id' => ws39UsdCurrencyId()]);

    ws39Assign($business, $usdStore, 'bank_transfer');

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

    expect(ws39Resolver()->forStore($usdStore))->not->toHaveKey('bank_transfer');
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

test('a provider is seeded and chargeable once it has a driver', function () {
    // Guards the rollout: a half-finished integration must not offer a gateway
    // that would fail at checkout, and the seeder is the gate — it skips every
    // code the manager cannot resolve a driver for.
    //
    // Bachs was the example of the gate working: registered, listed with its
    // real card and fee, and impossible to connect because it had no driver and
    // therefore no row. It has one now, so it is seeded like any other provider
    // and a merchant can charge through it.
    expect(PaymentGatewayRegistry::has('bachs'))->toBeTrue()
        ->and(app(PaymentGatewayManager::class)->isImplemented('bachs'))->toBeTrue()
        ->and(PaymentMethod::where('code', 'bachs')->exists())->toBeTrue();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);

    // A row is not an offer: an unconnected store is still shown nothing.
    expect(ws39Resolver()->forStore($store))->not->toHaveKey('bachs');

    ws39Connect($business, 'bachs', ws39BachsConfig());

    $usable = ws39Resolver()->forStore($store);

    expect($usable)->toHaveKey('bachs')
        ->and($usable['bachs']->isUsable())->toBeTrue()
        ->and($usable['bachs']->credentials->get('api_key'))->toBe('sk_sandbox_abcdefghij');
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
    ws39GrantOwnGateway($business);

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
    ws39GrantOwnGateway($business);
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
    ws39GrantOwnGateway($business);
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

test('the storefront is told each method\'s currency and how to display the converted figure', function () {
    // The customer has to see the converted amount before paying, so the client
    // needs two things it cannot derive: which currency each method charges in,
    // and the single divisor that mirrors the server's arithmetic. A rates map
    // is deliberately not sent — the client's sum has to match `convert()`.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);

    ws39Connect($business, 'paystack', ws39Paystack());
    ws39Connect($business, 'bitfra', ['api_key' => 'bix-'.str_repeat('a', 28).'-'.str_repeat('b', 28), 'webhook_secret' => 'whsec_abcdefgh']);
    ws39Rate('NGN', 1331.267014);

    $payload = $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->assertOk();

    $methods = collect($payload->json('data.payment_methods'))->keyBy('code');

    expect($payload->json('data.store_currency'))->toBe('NGN')
        // A naira provider needs no conversion, so it carries none.
        ->and($methods['paystack']['currency'])->toBe('NGN')
        ->and($methods['paystack']['conversion'])->toBeNull()
        ->and($methods['bitfra']['currency'])->toBe('USD')
        ->and($methods['bitfra']['conversion']['currency'])->toBe('USD')
        ->and($methods['bitfra']['conversion']['divisor'])->toEqualWithDelta(1331.267014, 0.0001)
        // ₦23,000 in kobo divided by that divisor is what the customer is shown.
        ->and((int) round(2300000 / $methods['bitfra']['conversion']['divisor']))->toBe(1728);
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
        // A provider this store never connected must not appear at the till —
        // `bachs` now has a driver and a seeded row, and is still absent here for
        // that reason alone. `bitfra` is USD-priced and has no rate seeded, so it
        // is hidden for a reason of its own.
        ->and($methods)->not->toHaveKey('bitfra')
        ->and($methods)->not->toHaveKey('bachs');
});

test('the till is told the same conversion the shopfront is', function () {
    // The two surfaces read the same resolver and must show the same figure for
    // the same payment. They previously kept separate lists that disagreed.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws39Store($owner);

    ws39Connect($business, 'bitfra', ['api_key' => 'bix-'.str_repeat('a', 28).'-'.str_repeat('b', 28), 'webhook_secret' => 'whsec_abcdefgh']);
    ws39Rate('NGN', 1331.267014);

    $token = $owner->createToken('pos-access', ['pos'], now()->addHour())->plainTextToken;

    $payload = $this->withToken($token)
        ->getJson('/api/v1/pos/stores/'.$store->store_id.'/payment-methods')
        ->assertOk();

    $methods = collect($payload->json('data.payment_methods'))->keyBy('code');

    expect($payload->json('data.store_currency'))->toBe('NGN')
        // Cash is taken in the store's own currency and is never converted.
        ->and($methods['cash']['currency'])->toBe('NGN')
        ->and($methods['cash']['conversion'])->toBeNull()
        ->and($methods['bitfra']['currency'])->toBe('USD')
        ->and($methods['bitfra']['conversion']['divisor'])->toEqualWithDelta(1331.267014, 0.0001);
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

    // The same false on a screen must not always read as the platform's doing:
    // Bachs is on offer and has been switched off by nobody — this business has
    // simply never connected it, and the screen has to say that instead.
    expect($states['bachs']['available'])->toBeTrue()
        ->and($states['bachs']['disabled_by_platform'])->toBeFalse()
        ->and($states['bachs']['has_connection'])->toBeFalse()
        ->and($states['bachs']['is_enabled'])->toBeFalse();
});

test('a provider with no row at all is unavailable without blaming the platform', function () {
    // The other half of the distinction, and the state a provider is in between
    // being added to the registry and having a driver built: no `payment_methods`
    // row. Nothing is switched off, nothing is connected, and the screen must say
    // "not built" rather than "turned off by Storify".
    //
    // No registered code is in this state right now — every one of them has a
    // driver — so the row is removed to reach it, which is what the seeder
    // skipping an unimplemented code leaves behind.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws39Connect($business, 'bachs', ws39BachsConfig());

    PaymentMethod::where('code', 'bachs')->delete();

    $states = collect(ws39Resolver()->states((int) $business->id))->keyBy('code');

    expect($states['bachs']['available'])->toBeFalse()
        ->and($states['bachs']['disabled_by_platform'])->toBeFalse();
});
