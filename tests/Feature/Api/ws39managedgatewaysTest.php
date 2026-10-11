<?php

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\Connect\PlatformCommission;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Money\ExchangeRateService;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-39 — a business's payments processed by Storify.
|
| The second way a store can take card payments, and the one most of them use:
| instead of the business holding gateway keys, Storify creates it a connected
| account at the provider and charges on its behalf. Every charge is a *direct
| charge* — the platform's key with the business's `X-Account-Id` — so the money
| belongs to the business from the moment the customer pays.
|
| The whole feature turns on one header. Bachs does not reject a missing or
| misspelled `X-Account-Id`: the request succeeds as the platform, and the sale —
| and the refund liability — become ours with nothing in the response saying so.
| The tests here are mostly about that header being present when it should be,
| absent when it should be, and never silently wrong.
*/

function mg39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'mg39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

/**
 * The platform's own Bachs account, which every managed charge is made with.
 *
 * Set on the config rather than read from the environment so the suite does not
 * depend on a developer's `.env` — and so the "no key" case is a test rather
 * than a machine nobody has.
 */
function mg39PlatformKeys(string $apiKey = 'sk_sandbox_platform1234', string $webhookSecret = 'whsec_platform'): void
{
    config([
        'services.bachs.api_key' => $apiKey,
        'services.bachs.webhook_secret' => $webhookSecret,
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * The state the provider last reported for a connected account.
 *
 * Only `active` capabilities authorise a charge, so the default here is one
 * cleared account — the state a business reaches once Bachs has approved it.
 *
 * @return array<string, mixed>
 */
function mg39AccountState(array $overrides = []): array
{
    return array_merge([
        'status' => 'active',
        'capabilities' => [
            'card_collection' => 'active',
            'ngn_card_collection' => 'active',
            'bank_transfer' => 'active',
            'mobile_money' => 'active',
            'payouts' => 'active',
        ],
        'requirements' => [],
        'payout_destination' => [
            'id' => 'pd_mg39',
            'is_usable' => true,
            'currency' => 'NGN',
            'bank_name' => 'Test Bank',
            'account_name' => 'TEST BUSINESS LTD',
            'last4' => '6789',
        ],
    ], $overrides);
}

/**
 * A connection the platform processes, written the way the service writes it:
 * an account id in its own column and no credentials anywhere.
 *
 * A `$store` gives that store its own subaccount; without one the account is the
 * business's, which is what every store inherits.
 */
function mg39Managed(
    Business $business,
    string $accountId = 'acct_mg39business',
    bool $active = true,
    ?Store $store = null,
    array $state = [],
): void {
    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();

    $attributes = $store
        ? ['store_id' => $store->id, 'payment_method_id' => $method->id]
        : ['business_id' => $business->id, 'payment_method_id' => $method->id];

    DB::table($store ? 'store_payment_method' : 'business_payment_method')->updateOrInsert(
        $attributes,
        [
            'business_id' => $business->id,
            'is_active' => $active,
            'gateway_account_id' => $accountId,
            // Deliberately *not* ciphertext: a managed connection holds no
            // secret, and passing it through the cipher would only make the
            // fixture look like something it is not.
            'config' => json_encode(['managed' => mg39AccountState($state)]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/** A connection the business made with its own keys — the other regime. */
function mg39OwnKeys(Business $business, array $config, bool $granted = true): void
{
    if ($granted) {
        $business->update(['can_connect_own_gateway' => true]);
    }

    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => true,
            'config' => json_encode(CredentialCipher::encrypt($config, PaymentGatewayRegistry::secretKeysFor('bachs'))),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function mg39Resolver(): PaymentGatewayResolver
{
    // The rate table is cached for ten minutes, so a rate seeded in one test
    // would otherwise answer the next one.
    ExchangeRateService::forget();

    return app(PaymentGatewayResolver::class);
}

function mg39Driver(): object
{
    return app(PaymentGatewayManager::class)->driver('bachs');
}

/**
 * Bachs accepting a checkout, so a driver call has something to come back to.
 */
function mg39FakeCheckout(): void
{
    Http::swap(new Factory);

    Http::fake([
        'sandbox-api.bachs.io/*' => Http::response([
            'checkout_id' => 'chk_mg39',
            'checkout_url' => 'https://checkout.bachs.io/chk_mg39',
            'status' => 'open',
        ]),
    ]);
}

function mg39Commission(float $percent, int $fixedKobo = 0): void
{
    Setting::query()->updateOrCreate(['id' => 1], [
        'company_name' => 'Storify',
        'platform_commission_percent' => $percent,
        'platform_commission_fixed_kobo' => $fixedKobo,
    ]);
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('a managed charge goes out on the business\'s account with the platform\'s commission', function () {
    // The whole money model in one request: the platform's key signs it, the
    // business's account is named, and our cut is taken by the provider out of
    // that account's proceeds rather than billed to us.
    mg39PlatformKeys();
    mg39Commission(2.5);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = mg39Store($owner);
    mg39Managed($business, 'acct_mg39business');

    $connection = mg39Resolver()->connection((int) $business->id, 'bachs', (int) $store->id);

    expect($connection->isManaged())->toBeTrue()
        ->and($connection->credentials->accountId())->toBe('acct_mg39business')
        // The platform's key, not the business's — there is no business key.
        ->and($connection->credentials->get('api_key'))->toBe('sk_sandbox_platform1234')
        ->and($connection->paymentMode())->toBe('platform_managed')
        // The provider's own webhook secret is not this connection's business:
        // deliveries come to the platform's connect endpoint.
        ->and($connection->credentials->get('webhook_secret'))->toBe('whsec_platform');

    mg39FakeCheckout();

    // ₦2,500 at 2.5% is ₦62.50, and Bachs wants it as a decimal string.
    $commission = PlatformCommission::fromSettings()->on(250000);

    expect($commission)->toBe(6250);

    $result = mg39Driver()->initialize(new PaymentIntent(
        reference: 'MG39_TEST_0001',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
        directChargeAccountId: 'acct_mg39business',
        platformFeeMinor: $commission,
    ), $connection->credentials);

    expect($result->success)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Account-Id', 'acct_mg39business')
        && $request['pricing']['amount'] === '2500.00'
        && $request['platform_fee'] === '62.50');
});

test('a managed connection is not offered when the platform holds no key', function () {
    // The provider's account is fine; ours is not. Offering the method would
    // send the customer to a charge nobody can make, so it is hidden.
    mg39PlatformKeys(apiKey: '');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = mg39Store($owner);
    mg39Managed($business, 'acct_mg39business');

    expect(mg39Resolver()->forStore($store))->not->toHaveKey('bachs');

    $state = collect(mg39Resolver()->states((int) $business->id, $store))->firstWhere('code', 'bachs');

    // The screen is told which of the two regimes is in force regardless: it
    // renders a payout-account panel rather than a credentials form from this.
    expect($state['payment_mode'])->toBe('platform_managed')
        ->and($state['is_managed_supported'])->toBeTrue()
        // "Complete" answers about the *connection*, and this one is complete —
        // the account is approved and has a bank. That the platform's own key is
        // missing is a different, platform-wide fact, and it is the reason that
        // says so rather than this flag.
        ->and($state['has_credentials'])->toBeTrue()
        ->and($state['unavailable_reason'])->toBe('Storify cannot process payments at the moment.');
});

test('a managed account the provider has not cleared cannot be charged through', function () {
    // Created, but Bachs has not approved it: the capability is still pending.
    // Charging anyway is a customer-facing error we can see coming.
    mg39PlatformKeys();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = mg39Store($owner);
    mg39Managed($business, 'acct_mg39business', state: [
        'status' => 'pending',
        'capabilities' => ['card_collection' => 'pending'],
    ]);

    expect(mg39Resolver()->forStore($store))->not->toHaveKey('bachs');

    $state = collect(mg39Resolver()->states((int) $business->id, $store))->firstWhere('code', 'bachs');

    expect($state['unavailable_reason'])->toBe('Your payout account is still being reviewed. You will be able to take payments once it is approved.');
});

test('a managed account with no bank registered is offered, but says what is missing', function () {
    // Charging is possible before a destination exists; being paid out is not.
    // The two are separate questions and the screen has to be able to say so.
    mg39PlatformKeys();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = mg39Store($owner);
    mg39Managed($business, 'acct_mg39business', state: ['payout_destination' => null]);

    // Offered: a business can take a customer's money before it has said where
    // to send it, and blocking sales on a bank form would be the wrong way
    // round. The owner is told what to do instead.
    expect(mg39Resolver()->forStore($store))->toHaveKey('bachs');

    $state = collect(mg39Resolver()->states((int) $business->id, $store))->firstWhere('code', 'bachs');

    expect($state['managed']['can_charge'])->toBeTrue()
        ->and($state['managed']['can_be_paid_out'])->toBeFalse()
        ->and($state['managed']['payout_destination'])->toBeNull()
        ->and($state['unavailable_reason'])->toBe('Add a bank account so Storify can pay your sales out to you.');
});

test('a direct charge with no usable account id is refused before anything is posted', function () {
    // The failure this whole feature guards against. A direct charge that goes
    // out without its account is not a failed payment — it is a *successful*
    // payment to the wrong party, and the response says nothing. So the driver
    // refuses on the request it was given, and never reaches the provider.
    mg39PlatformKeys();

    $platform = app(PaymentGatewayManager::class)->driver('bachs');

    Http::swap(new Factory);
    Http::fake();

    // A: the intent names an account the credentials do not carry — the shape a
    // resolver bug would produce.
    $missing = $platform->initialize(new PaymentIntent(
        reference: 'MG39_TEST_0002',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
        directChargeAccountId: 'acct_mg39business',
    ), app(PlatformBillingGateway::class)->credentialsFor('bachs'));

    expect($missing->success)->toBeFalse()
        ->and($missing->message)->toContain('does not match');

    // B: a malformed id, which is not an account at all.
    $malformed = $platform->initialize(new PaymentIntent(
        reference: 'MG39_TEST_0003',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
        directChargeAccountId: 'not-an-account',
    ), app(PlatformBillingGateway::class)->credentialsFor('bachs')->withAccountId('not-an-account'));

    expect($malformed->success)->toBeFalse()
        ->and($malformed->message)->toContain('not a valid account id');

    Http::assertNothingSent();
});

test('a store\'s own account wins over the business\'s, and a bare store row inherits it', function () {
    // Per-store individuality survives the move to one platform account: a store
    // that wants its own subaccount gets one, and every other store keeps the
    // business's. The precedence rule is the one the keys path already uses.
    mg39PlatformKeys();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $storeA = mg39Store($owner);
    $storeB = mg39Store($owner);

    mg39Managed($business, 'acct_mg39business');
    mg39Managed($business, 'acct_mg39storea', store: $storeA);

    // The store's own account.
    expect(mg39Resolver()->connection((int) $business->id, 'bachs', (int) $storeA->id)->credentials->accountId())
        ->toBe('acct_mg39storea');

    // A store with a row of its own that carries nothing inherits the
    // business's account — the same rule that keeps plain assignments working.
    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();
    DB::table('store_payment_method')->insert([
        'store_id' => $storeB->id,
        'business_id' => $business->id,
        'payment_method_id' => $method->id,
        'is_active' => true,
        'config' => null,
        'gateway_account_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $inherited = mg39Resolver()->connection((int) $business->id, 'bachs', (int) $storeB->id);

    expect($inherited->credentials->accountId())->toBe('acct_mg39business')
        // The store row still decided *whether*, so the source is the store.
        ->and($inherited->source)->toBe('store')
        ->and($inherited->storeOverrides)->toBeTrue();
});

test('a business with its own keys is charged on them, not on its account', function () {
    // Keys win deliberately. A business an admin has granted is one that asked
    // to use its own merchant account, and the subaccount is kept rather than
    // removed so revoking the grant puts it back on it.
    mg39PlatformKeys();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = mg39Store($owner);

    mg39Managed($business, 'acct_mg39business');
    mg39OwnKeys($business, ['api_key' => 'sk_sandbox_businesskeys', 'webhook_secret' => 'whsec_business']);

    $connection = mg39Resolver()->connection((int) $business->id, 'bachs', (int) $store->id);

    expect($connection->isManaged())->toBeFalse()
        ->and($connection->credentials->accountId())->toBeNull()
        ->and($connection->credentials->get('api_key'))->toBe('sk_sandbox_businesskeys')
        ->and($connection->paymentMode())->toBe('own_keys');

    mg39FakeCheckout();

    mg39Driver()->initialize(new PaymentIntent(
        reference: 'MG39_TEST_0004',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
    ), $connection->credentials);

    // No header, no fee: this is an ordinary merchant charge, exactly as it was
    // before any of this existed.
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Account-Id')
        && ! array_key_exists('platform_fee', $request->data()));

    // And the account is still there, untouched, under the keys.
    expect(DB::table('business_payment_method')->where('business_id', $business->id)->value('gateway_account_id'))
        ->toBe('acct_mg39business');
});
