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
use App\Support\Money\ExchangeRateService;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/*
| WS-39 — a business's payments collected by Storify.
|
| The second way a store can take card payments: instead of holding gateway keys
| of its own, the business is one Storify collects for, and every charge is made
| on **Storify's** credentials. The gross lands in Storify's provider balance, the
| wallet records what the business is owed, and a job pays it out.
|
| ## What this file used to be about, and why it is not any more
|
| It was called `managedgatewaysTest` and most of it asserted a header: every
| charge carried the business's `X-Account-Id`, and the danger the file guarded
| against was that header going missing — because Bachs does not reject it, so a
| charge without one succeeds *as the platform*, and the sale and the refund
| liability quietly become ours.
|
| That header is gone, and so is the account it named. The charge is Storify's on
| purpose now, which turns the old failure inside out: the thing to guard against
| is an account id coming *back*, because a charge that named one would settle the
| business directly while the wallet recorded a debt that had already been paid.
| That is asserted here, directly, on the outgoing request.
*/

function pc39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'pc39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

/**
 * The platform's own Bachs account, which every platform charge is made with.
 *
 * Set on the config rather than read from the environment so the suite does not
 * depend on a developer's `.env` — and so the "no key" case is a test rather
 * than a machine nobody has.
 */
function pc39PlatformKeys(string $apiKey = 'sk_sandbox_platform1234', string $webhookSecret = 'whsec_platform'): void
{
    config([
        'services.bachs.api_key' => $apiKey,
        'services.bachs.webhook_secret' => $webhookSecret,
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/** The processor in force, written the way the admin screen writes it. */
function pc39Processor(?string $code): void
{
    $settings = Setting::query()->first() ?? Setting::query()->create(['company_name' => 'Storify']);

    $settings->update(['system_payment_processor' => $code]);
}

/**
 * A business Storify collects for: flipped, and on the processor in force.
 *
 * Neither a payment-method row nor a bank account is created, and that is the
 * point — under collect-and-pay-out there is nothing per a business to hold, so a
 * business with *nothing* connected is still chargeable.
 */
function pc39Collected(?Store $store = null, array $businessAttributes = []): array
{
    pc39PlatformKeys();
    pc39Processor('bachs');

    [$owner, $business] = createBusinessOwner(array_merge([
        'trial_ends_at' => now()->addWeek(),
    ], $businessAttributes));

    $business->forceFill(['custody_enabled' => true])->save();

    return [$owner, $business->refresh(), $store ?? pc39Store($owner)];
}

/**
 * An owner's off-switch, written the way the service writes it. Note what is
 * *absent*: no credentials, no account id, no stored state of any kind.
 */
function pc39SwitchOff(Business $business, ?Store $store = null): void
{
    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();

    DB::table($store ? 'store_payment_method' : 'business_payment_method')->updateOrInsert(
        $store
            ? ['store_id' => $store->id, 'payment_method_id' => $method->id]
            : ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'business_id' => $business->id,
            'is_active' => false,
            'config' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/** A connection the business made with its own keys — the other regime. */
function pc39OwnKeys(Business $business, array $config, bool $granted = true): void
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

function pc39Resolver(): PaymentGatewayResolver
{
    // The rate table is cached for ten minutes, so a rate seeded in one test
    // would otherwise answer the next one.
    ExchangeRateService::forget();

    return app(PaymentGatewayResolver::class);
}

function pc39Driver(): object
{
    return app(PaymentGatewayManager::class)->driver('bachs');
}

/** Bachs accepting a checkout, so a driver call has something to come back to. */
function pc39FakeCheckout(): void
{
    Http::swap(new Factory);

    Http::fake([
        'sandbox-api.bachs.io/*' => Http::response([
            'checkout_id' => 'chk_pc39',
            'checkout_url' => 'https://checkout.bachs.io/chk_pc39',
            'status' => 'open',
        ]),
    ]);
}

function pc39Commission(float $percent, int $fixedKobo = 0): void
{
    $settings = Setting::query()->first() ?? Setting::query()->create(['company_name' => 'Storify']);

    $settings->update([
        'platform_commission_percent' => $percent,
        'platform_commission_fixed_kobo' => $fixedKobo,
    ]);
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('a platform charge goes out on Storify\'s credentials, naming nothing of the business', function () {
    // The whole money model in one request, and the assertion that matters is a
    // pair of absences. Sending `X-Account-Id` alongside the platform's key makes
    // the charge settle to *the business* at the provider, and `platform_fee`
    // makes the provider take our commission out of that account's proceeds —
    // both of which would pay the business while the wallet recorded a debt
    // against money it had already received.
    pc39Commission(2.5);

    [$owner, $business, $store] = pc39Collected();

    $connection = pc39Resolver()->connection((int) $business->id, 'bachs', (int) $store->id);

    expect($connection->isManaged())->toBeTrue()
        ->and($connection->paymentMode())->toBe('platform_managed')
        ->and($connection->credentials->get('api_key'))->toBe('sk_sandbox_platform1234')
        // The provider's own signing secret is not this connection's business:
        // deliveries come to the platform's endpoint.
        ->and($connection->credentials->get('webhook_secret'))->toBe('whsec_platform');

    pc39FakeCheckout();

    // ₦2,500 at 2.5% is ₦62.50. The commission is still computed — it is what
    // the ledger splits the gross by — and simply no longer sent to the provider.
    expect(PlatformCommission::fromSettings()->on(250000))->toBe(6250);

    $result = pc39Driver()->initialize(new PaymentIntent(
        reference: 'PC39_TEST_0001',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
    ), $connection->credentials);

    expect($result->success)->toBeTrue();

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Account-Id')
        && ! array_key_exists('platform_fee', $request->data())
        && $request['pricing']['amount'] === '2500.00');
});

test('a platform charge is not offered when the platform holds no key', function () {
    // The business's side is fine; ours is not. Offering the method would send
    // the customer to a charge nobody can make, so it is hidden — and the screen
    // is still told which regime is in force, so it renders the right panel
    // instead of an empty card.
    pc39Processor('bachs');
    pc39PlatformKeys(apiKey: '');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->forceFill(['custody_enabled' => true])->save();
    $store = pc39Store($owner);

    expect(pc39Resolver()->forStore($store))->not->toHaveKey('bachs');

    $state = collect(pc39Resolver()->states((int) $business->id, $store))->firstWhere('code', 'bachs');

    expect($state['is_managed_supported'])->toBeTrue()
        // "Complete" answers about the *connection*: a collected business's
        // charge carries nothing of its own, so there is nothing that can be
        // incomplete. That the platform's own key is missing is a different,
        // platform-wide fact, and it is the reason sentence that says so.
        ->and($state['has_credentials'])->toBeTrue()
        ->and($state['unavailable_reason'])->toBe('Storify cannot process payments at the moment.');
});

test('a collected business with no bank on file is still offered, and nothing is explained away', function () {
    // The point of the whole change. The provider used to pay the business
    // directly, so a charge with nowhere to land stranded money and the bank
    // account had to exist first — which turned "we will pay you shortly" into
    // "you cannot trade". Money is held for the business now and handed over when
    // it names an account, so this is a payout delay and never a block. There is
    // no `unavailable_reason` because nothing is unavailable.
    [, $business, $store] = pc39Collected();

    expect($business->payout_bank_id)->toBeNull();

    expect(pc39Resolver()->forStore($store))->toHaveKey('bachs');

    $state = collect(pc39Resolver()->states((int) $business->id, $store))->firstWhere('code', 'bachs');

    expect($state['is_enabled'])->toBeTrue()
        ->and($state['custody'])->toBeTrue()
        ->and($state['unavailable_reason'])->toBeNull();
});

test('a business Storify does not collect for cannot be charged through the platform', function () {
    // The two predicates are one. The gross arrives in Storify's balance either
    // way, and there is no second rail left to settle a business directly — so
    // charging on behalf of a business whose money we do not hold would bank its
    // customers' money with nothing recording that the business is owed it. The
    // resolver refuses instead of degrading, and the enforcement pre-flight is
    // what names the business to an admin.
    pc39PlatformKeys();
    pc39Processor('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->forceFill(['custody_enabled' => false])->save();
    $store = pc39Store($owner);

    expect(pc39Resolver()->forStore($store))->not->toHaveKey('bachs');

    $state = collect(pc39Resolver()->states((int) $business->id, $store))->firstWhere('code', 'bachs');

    expect($state['is_enabled'])->toBeFalse()
        ->and($state['custody'])->toBeFalse()
        // Nothing is paused, because nobody switched anything off: the owner has
        // never seen this provider, and reporting it as their doing would send
        // them to a screen where every switch is already where they want it.
        ->and($state['is_paused'])->toBeFalse();
});

test('a store priced in a currency the wallet cannot hold is not collected for', function () {
    // The wallet, the custody accounts and the payout rail are all denominated in
    // one currency, so a sale in any other has to be refused rather than converted:
    // `transactions.amount` is the store's own figure, and the provider fee and the
    // commission beside it are the figures actually charged. Crediting the net of
    // those would read the store's number as naira — short or clamped to zero on a
    // stronger currency, and paying out more than was collected on a much weaker
    // one. The refusal is here, at the door, so the sale is never taken in the first
    // place; `CustodyService` and the ledger refuse it again on the way past.
    pc39PlatformKeys();
    pc39Processor('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->forceFill(['custody_enabled' => true, 'currency' => 'USD'])->save();
    $store = pc39Store($owner);

    expect(pc39Resolver()->forStore($store))->not->toHaveKey('bachs');

    $state = collect(pc39Resolver()->states((int) $business->id, $store))->firstWhere('code', 'bachs');

    expect($state['custody'])->toBeFalse()
        // And the sentence names the store's own setting, because that is the half
        // its owner can act on — "wait for an admin to flip you" would be advice
        // that changed nothing.
        ->and($state['unavailable_reason'])->toContain('priced in USD');
});

test('the owner\'s switch is the only thing a row decides, and a store can pull it alone', function () {
    // Per-store individuality survives as a *switch* rather than as an account.
    // A store with its own row is deciding whether to sell, not where the money
    // goes — there is nowhere else for it to go. The precedence is unchanged:
    // the store's row wins, and a store without one inherits the business's.
    [, $business] = pc39Collected();

    $storeA = pc39Store(User::findOrFail($business->user_id));
    $storeB = pc39Store(User::findOrFail($business->user_id));

    pc39SwitchOff($business, $storeA);

    expect(pc39Resolver()->connection((int) $business->id, 'bachs', (int) $storeA->id)->isEnabled)->toBeFalse()
        // A store with no row of its own is untouched by its sibling's decision.
        ->and(pc39Resolver()->connection((int) $business->id, 'bachs', (int) $storeB->id)->isEnabled)->toBeTrue();

    // And the source still reports which row decided it, because the screen
    // badges it and a store's own switch is a different statement from the
    // business's.
    $paused = pc39Resolver()->connection((int) $business->id, 'bachs', (int) $storeA->id);

    expect($paused->source)->toBe('store')
        ->and($paused->storeOverrides)->toBeTrue()
        ->and($paused->paused)->toBeTrue();
});

test('with no processor enforced, a business with its own keys is charged on them', function () {
    // The ordinary path, and it has to keep working exactly as it did: a
    // deployment that has not switched enforcement on is charged per business,
    // on that business's own merchant account, with no wallet anywhere in the
    // picture.
    pc39PlatformKeys();
    pc39Processor(null);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = pc39Store($owner);

    pc39OwnKeys($business, ['api_key' => 'sk_sandbox_businesskeys', 'webhook_secret' => 'whsec_business']);

    $connection = pc39Resolver()->connection((int) $business->id, 'bachs', (int) $store->id);

    expect($connection->isManaged())->toBeFalse()
        ->and($connection->credentials->get('api_key'))->toBe('sk_sandbox_businesskeys')
        ->and($connection->credentials->get('webhook_secret'))->toBe('whsec_business')
        ->and($connection->paymentMode())->toBe('own_keys');

    pc39FakeCheckout();

    pc39Driver()->initialize(new PaymentIntent(
        reference: 'PC39_TEST_0002',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'buyer@example.com',
        callbackUrl: 'https://shop.example.com/return',
    ), $connection->credentials);

    // No header, no fee: this is an ordinary merchant charge, exactly as it was
    // before any of this existed. The money settles to *their* account and no
    // wallet is involved.
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Account-Id')
        && ! array_key_exists('platform_fee', $request->data()));
});

test('under enforcement, a grant and a business\'s own keys are ignored', function () {
    // The sharpest consequence of enforcing a processor, and it reverses what
    // this file used to assert. Keys used to win over the platform's account;
    // under enforcement they do not win, and the reason is not deference to the
    // admin — it is that there is no longer a rail on which a business could be
    // settled directly beside one on which it is collected for. Honouring the
    // grant would charge on the business's account *and* credit its wallet for
    // the same sale, so the wallet would be recording a debt against money the
    // business had already received.
    //
    // It is also a live fix rather than only a new rule: the ordinary path lets
    // stored keys keep charging after an admin has taken the grant away, so a
    // console that promises keys "stop being used immediately" was not telling
    // the truth about the code.
    pc39PlatformKeys();
    pc39Processor('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->forceFill(['custody_enabled' => true])->save();
    $store = pc39Store($owner);

    // Granted, and with working keys stored — everything that used to win.
    pc39OwnKeys($business, ['api_key' => 'sk_sandbox_businesskeys', 'webhook_secret' => 'whsec_business']);

    $connection = pc39Resolver()->connection((int) $business->id, 'bachs', (int) $store->id);

    expect($connection->isManaged())->toBeTrue()
        ->and($connection->paymentMode())->toBe('platform_managed')
        // The platform's key, not the business's, even though theirs is right
        // there on the row.
        ->and($connection->credentials->get('api_key'))->toBe('sk_sandbox_platform1234')
        ->and($connection->credentials->get('webhook_secret'))->toBe('whsec_platform');

    // And the row is untouched. Enforcement ignores it rather than rewriting it,
    // so switching enforcement back off puts the business on its own keys again
    // without anybody re-entering them.
    expect(DB::table('business_payment_method')->where('business_id', $business->id)->value('is_active'))
        ->toBe(1);
});

test('a collected business has no place to put an account id, and no row is needed to charge it', function () {
    // The schema-level half of the same guarantee. `gateway_account_id` is gone
    // from both payment-method tables, so there is no column a stale account
    // could survive in — and the resolver for a collected business reads no row
    // at all, which is what makes a business that has never opened the screen
    // chargeable the moment it is flipped.
    [, $business, $store] = pc39Collected();

    expect(DB::table('business_payment_method')->where('business_id', $business->id)->count())->toBe(0)
        ->and(DB::table('store_payment_method')->where('store_id', $store->id)->count())->toBe(0);

    foreach (['business_payment_method', 'store_payment_method'] as $table) {
        expect(Schema::hasColumn($table, 'gateway_account_id'))->toBeFalse();
    }

    $connection = pc39Resolver()->connection((int) $business->id, 'bachs', (int) $store->id);

    expect($connection->isEnabled)->toBeTrue()
        ->and($connection->source)->toBe('none')
        ->and($connection->credentials->isEmpty())->toBeFalse();
});
