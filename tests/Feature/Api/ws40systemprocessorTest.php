<?php

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Connect\PlatformPayoutService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\OnBehalf\BachsOnBehalf;
use App\Services\Payments\OnBehalf\Contracts\OnBehalfProcessor;
use App\Services\Payments\OnBehalf\OnBehalfProcessors;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-40 — the system payment processor.
|
| `settings.system_payment_processor` is one switch with two states and a third
| that is neither: unset means the platform behaves exactly as it always has,
| set means every business's customer inflow goes through the platform's own
| connection on that one provider with the split made at settlement, and a
| *stored* value this build cannot run means enforced-with-no-processor rather
| than off.
|
| The three states are what this suite pins down, because each of them is a
| decision that would be silent if it were wrong: an off deployment must be
| byte-identical to what it was, an on deployment must not fall back to a
| business's own keys when the platform's account is missing, and a broken
| switch must fail closed rather than open.
*/

/**
 * Write the singleton settings row the platform reads.
 *
 * Written as `first()`-then-`update` rather than the more usual
 * `updateOrCreate(['id' => 1])`, because `id` is not fillable on `Setting`: the
 * lookup constraint is applied to the query but dropped from the insert, so a
 * row is never created *with* id 1 and the update branch can never match. Each
 * call then inserts another row, and `SystemProcessor` — which reads the first
 * one — keeps seeing whichever row landed lowest. The switch would read as off
 * for the rest of the file with no error anywhere.
 */
function ws40Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function ws40Switch(?string $code): void
{
    ws40Settings(['system_payment_processor' => $code]);
}

function ws40Commission(float $percent = 2.5, int $fixedKobo = 0): void
{
    ws40Settings([
        'platform_commission_percent' => $percent,
        'platform_commission_fixed_kobo' => $fixedKobo,
    ]);
}

function ws40PlatformPaystack(): void
{
    config([
        'services.paystack.secret_key' => 'sk_live_platform1234567890',
        'services.paystack.public_key' => 'pk_live_platform1234567890',
    ]);
}

function ws40PlatformBachs(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function ws40Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ws40-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

/**
 * The `managed` block as it is stored on a connection row.
 *
 * Written by hand rather than produced by a provider call, because these tests
 * are about what the *resolver* does with a connection that already exists —
 * the account lifecycle has its own suite, and routing every one of these
 * through a provisioning round trip would make them fail for reasons that have
 * nothing to do with the switch.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ws40ManagedState(bool $usable = true, array $overrides = []): array
{
    return array_merge([
        'status' => 'active',
        'capabilities' => [
            'card_collection' => 'active',
            'ngn_card_collection' => 'active',
            'bank_transfer' => 'active',
            'payouts' => 'active',
        ],
        'requirements' => [],
        'payout_destination' => [
            'id' => 'pd_ws40',
            'is_usable' => $usable,
            'currency' => 'NGN',
            'bank_name' => 'Guaranty Trust Bank',
            'account_name' => 'ADA TRADING LTD',
            'last4' => '6789',
        ],
    ], $overrides);
}

/**
 * A settlement account the platform charges a business's customers through.
 */
function ws40Managed(Business $business, string $code, string $accountId, bool $active = true, bool $usable = true): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => $active,
            'gateway_account_id' => $accountId,
            'config' => json_encode(['managed' => ws40ManagedState($usable)]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/**
 * The business has said which bank account its sales are paid into.
 *
 * The pointer and nothing else: no provider account, no connection row. It is
 * the state a business lands in when it has named an account and the platform
 * has not yet — or can no longer — be paid through.
 */
function ws40PayoutBank(Business $business, string $entityType = 'company'): StoreBank
{
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $business->forceFill([
        'payout_bank_id' => $bank->id,
        'payout_entity_type' => $entityType,
    ])->save();

    return $bank;
}

/**
 * One row holding *both* a business's own keys and a settlement account — the
 * state a business is in when an admin turns the switch on after it had already
 * connected its own gateway.
 *
 * The two live in the same row because they live in the same row in life:
 * `config` holds whatever the business stored, and `gateway_account_id` is a
 * column of its own that nothing overwrites. What the switch changes is which
 * of them the resolver reads.
 *
 * @param  array<string, mixed>  $ownKeys
 */
function ws40ManagedWithOwnKeys(Business $business, string $code, string $accountId, array $ownKeys): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => true,
            'gateway_account_id' => $accountId,
            'config' => json_encode(array_merge(
                CredentialCipher::encrypt($ownKeys, PaymentGatewayRegistry::secretKeysFor($code)),
                ['managed' => ws40ManagedState()],
            )),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/**
 * A business's own provider keys, stored exactly as the application stores them.
 *
 * @param  array<string, mixed>  $config
 */
function ws40OwnKeys(Business $business, string $code, array $config, bool $active = true): void
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

/**
 * Assign a provider to a store, as the business's own screen does.
 *
 * No credentials: this is the "this store may use it" row, which for an offline
 * rail is the whole of the connection.
 */
function ws40Assign(Business $business, Store $store, string $code, bool $active = true): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('store_payment_method')->updateOrInsert(
        ['store_id' => $store->id, 'payment_method_id' => $method->id],
        ['business_id' => $business->id, 'is_active' => $active, 'created_at' => now(), 'updated_at' => now()],
    );
}

function ws40PaystackKeys(): array
{
    return ['public_key' => 'pk_live_own1234567890', 'secret_key' => 'sk_live_own1234567890'];
}

function ws40Admin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ws40AdminHeaders(User $admin): array
{
    return [
        'Authorization' => 'Bearer '.$admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
        'Accept' => 'application/json',
    ];
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/*
|--------------------------------------------------------------------------
| The setting itself
|--------------------------------------------------------------------------
*/

test('the switch is stored, reported in the settings payload, and cleared by an explicit null', function () {
    $admin = ws40Admin();

    $response = $this->putJson('/api/v1/admin/settings', ['system_payment_processor' => 'paystack'], ws40AdminHeaders($admin))
        ->assertOk()
        ->assertJsonPath('data.settings.system_payment_processor', 'paystack')
        ->assertJsonPath('data.settings.system_payment_processor_name', 'Paystack');

    expect(Setting::query()->value('system_payment_processor'))->toBe('paystack')
        ->and($response->json('data.changed_keys'))->toContain('system_payment_processor');

    // An explicit null is the switch's "off", and it is a change like any other
    // — not an absent key, which means "leave it alone".
    $cleared = $this->putJson('/api/v1/admin/settings', ['system_payment_processor' => null], ws40AdminHeaders($admin))
        ->assertOk()
        ->assertJsonPath('data.settings.system_payment_processor', null);

    expect(Setting::query()->value('system_payment_processor'))->toBeNull()
        ->and($cleared->json('data.changed_keys'))->toContain('system_payment_processor');
});

test('the console\'s form-encoded save can clear the switch with an empty value', function () {
    // The console posts a form (multipart, with `_method=PUT`) because PHP does
    // not parse multipart bodies for real PUTs, and a form cannot carry a null —
    // so "off" arrives as an empty string and is turned into one by Laravel's
    // `ConvertEmptyStringsToNull`. That conversion is the whole reason the
    // switch can be turned off from the screen at all, and it is one middleware
    // registration away from silently becoming "leave it alone" instead, which
    // would make the switch impossible to turn off. Hence the test.
    $admin = ws40Admin();

    $this->put('/api/v1/admin/settings', ['system_payment_processor' => 'bachs'], ws40AdminHeaders($admin))
        ->assertOk();

    expect(Setting::query()->value('system_payment_processor'))->toBe('bachs');

    $this->put('/api/v1/admin/settings', ['system_payment_processor' => ''], ws40AdminHeaders($admin))
        ->assertOk()
        ->assertJsonPath('data.settings.system_payment_processor', null);

    expect(Setting::query()->value('system_payment_processor'))->toBeNull();
});

test('the settings payload offers exactly the processors the platform can provision', function () {
    ws40Admin();

    $options = collect($this->getJson('/api/v1/admin/settings', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->json('data.settings.system_payment_processors'))
        ->pluck('code')
        ->all();

    // The registry's managed set, not a list written into the console: a
    // provider without an on-behalf path cannot be *the platform's* processor,
    // because there would be nothing to split.
    expect($options)->toBe(PaymentGatewayRegistry::managedCodes())
        ->and($options)->toContain('paystack')
        ->and($options)->toContain('bachs')
        ->and($options)->not->toContain('flutterwave');
});

test('a processor the platform cannot provision is refused', function () {
    $admin = ws40Admin();

    $this->putJson('/api/v1/admin/settings', ['system_payment_processor' => 'flutterwave'], ws40AdminHeaders($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('system_payment_processor');

    expect(Setting::query()->value('system_payment_processor'))->toBeNull();
});

test('saving another tab does not disturb the switch', function () {
    // The absent-key rule, which is the difference between "this form did not
    // mention it" and "this form cleared it". A full-form PUT from the branding
    // tab must not silently turn the platform's processor off.
    ws40Switch('bachs');
    $admin = ws40Admin();

    $this->putJson('/api/v1/admin/settings', ['company_name' => 'Storify Ltd'], ws40AdminHeaders($admin))
        ->assertOk();

    expect(Setting::query()->value('system_payment_processor'))->toBe('bachs');
});

test('a stored processor this build cannot run reads as enforced with no processor, not as off', function () {
    // The third state, and the reason it is not "off": quiet fallback would take
    // money across a boundary an admin believes they closed. Written straight to
    // the row, because validation would refuse to create it.
    ws40Switch('stripe');

    expect(SystemProcessor::enforced())->toBeTrue()
        ->and(SystemProcessor::code())->toBeNull()
        ->and(SystemProcessor::unavailableReason())->toContain('stripe');
});

/*
|--------------------------------------------------------------------------
| Off — the deployment that never turns it on
|--------------------------------------------------------------------------
*/

test('while the switch is off a business charges through its own keys, unchanged', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    ws40OwnKeys($business, 'paystack', ws40PaystackKeys());

    $resolved = app(PaymentGatewayResolver::class)->connection((int) $business->id, 'paystack');

    expect($resolved->isEnabled)->toBeTrue()
        ->and($resolved->credentials->get('secret_key'))->toBe('sk_live_own1234567890')
        ->and($resolved->credentials->accountId())->toBeNull()
        ->and($resolved->isManaged())->toBeFalse();

    // The offline rail too: it needs its own assignment and a verified bank, so
    // its presence here is a real answer rather than a default.
    ws40Assign($business, $store, 'bank_transfer');

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

    $methods = collect($this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->assertOk()->json('data.payment_methods'))
        ->pluck('code');

    // With the switch off the platform has no opinion about how money arrives,
    // so both the business's own provider and the offline rail are offered.
    expect($methods)->toContain('paystack')
        ->and($methods)->toContain('bank_transfer');
});

test('while the switch is off an empty checkout carries no reason', function () {
    // The reason field exists for the state the switch creates. With the switch
    // off an empty list means the ordinary "this store connected nothing", which
    // has always rendered as simply no options — inventing a message for it now
    // would be a change to the off deployment.
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    $payload = $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->assertOk();

    expect($payload->json('data.payment_methods'))->toBe([])
        ->and($payload->json('data.unavailable_reason'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| On — the switch doing what it says
|--------------------------------------------------------------------------
*/

test('under enforcement the platform account is used and a business\'s own keys are ignored', function () {
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->update(['can_connect_own_gateway' => true]);
    $store = ws40Store($owner);

    // Both, on the same row: keys stored from when the business connected its
    // own gateway, and the settlement account opened for it. Under enforcement
    // the account is the only thing that may charge.
    ws40ManagedWithOwnKeys($business, 'paystack', 'ACCT_business01', ws40PaystackKeys());

    $resolved = app(PaymentGatewayResolver::class)->connection((int) $business->id, 'paystack');

    expect($resolved->isEnabled)->toBeTrue()
        ->and($resolved->credentials->accountId())->toBe('ACCT_business01')
        // The platform's key, not the business's: the two are different strings
        // and this is the assertion that says which one went into the driver.
        ->and($resolved->credentials->get('secret_key'))->toBe('sk_live_platform1234567890')
        ->and($resolved->isManaged())->toBeTrue();

    expect(collect($this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->json('data.payment_methods'))->pluck('code')->all())
        ->toBe(['paystack']);
});

test('a store\'s own key row cannot mask the business\'s settlement account', function () {
    // The live-fix case. The ordinary precedence prefers a store row that carries
    // a connection, which for a store holding its own keys is exactly the row
    // that hides the account the money has to reach. Under enforcement keys are
    // not a connection at all.
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    ws40Managed($business, 'paystack', 'ACCT_business01');

    $method = PaymentMethod::where('code', 'paystack')->firstOrFail();

    DB::table('store_payment_method')->updateOrInsert(
        ['store_id' => $store->id, 'payment_method_id' => $method->id],
        [
            'business_id' => $business->id,
            'is_active' => true,
            'config' => json_encode(CredentialCipher::encrypt(ws40PaystackKeys(), PaymentGatewayRegistry::secretKeysFor('paystack'))),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    $resolved = app(PaymentGatewayResolver::class)->connection((int) $business->id, 'paystack', (int) $store->id);

    expect($resolved->credentials->accountId())->toBe('ACCT_business01')
        ->and($resolved->credentials->get('secret_key'))->toBe('sk_live_platform1234567890')
        ->and($resolved->isEnabled)->toBeTrue();
});

test('every provider but the selected one is not offered, and says why', function () {
    ws40PlatformBachs();
    ws40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    ws40Managed($business, 'bachs', 'acct_ws40');
    // A Paystack account the business holds, and the offline rail it may have
    // been assigned — neither is a way money may arrive any more.
    ws40Managed($business, 'paystack', 'ACCT_business01');

    $method = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

    DB::table('store_payment_method')->updateOrInsert(
        ['store_id' => $store->id, 'payment_method_id' => $method->id],
        ['business_id' => $business->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    );

    expect(collect($this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->json('data.payment_methods'))->pluck('code')->all())
        ->toBe(['bachs']);

    $states = collect(app(PaymentGatewayResolver::class)->states((int) $business->id, $store))->keyBy('code');

    expect($states['paystack']['is_enabled'])->toBeFalse()
        ->and($states['paystack']['unavailable_reason'])->toBe('Storify processes all payments through Bachs.')
        ->and($states['bank_transfer']['unavailable_reason'])->toBe('Storify processes all payments through Bachs.')
        // And the business's own screen is told the regime rather than being
        // left to infer it from a card that has gone quiet.
        ->and($states['bachs']['platform_enforced'])->toBeTrue()
        ->and($states['bachs']['platform_enforced_code'])->toBe('bachs');
});

test('a business with no bank account named offers nothing, and says why', function () {
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    $payload = $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->assertOk();

    // The customer and the owner get the same sentence, and it asks for the
    // thing the business is actually asked for. It used to say the business had
    // "not registered a settlement account", which is Storify's word for its own
    // plumbing and names a step the business was never given.
    expect($payload->json('data.payment_methods'))->toBe([])
        ->and($payload->json('data.unavailable_reason'))
        ->toBe('Set the bank account your sales are paid into to take payments.');
});

test('a business that has named a bank account is told it is waiting on setup', function () {
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);
    ws40PayoutBank($business);

    // The state a processor switch creates: the business has said where its
    // money goes, and has no account at the processor now in force. That is not
    // something it did wrong and not something it can do anything about, so it
    // is told it is waiting rather than asked to register anything again.
    $payload = $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->assertOk();

    expect($payload->json('data.unavailable_reason'))
        ->toBe('Your bank account is registered, but your payment account is not set up yet.');
});

test('an account with nowhere to pay out to is refused only under enforcement', function () {
    // The asymmetry the switch introduces. Without it a managed account that
    // cannot be paid out still works — the console's sweep pays it once a bank
    // is registered — but a direct settlement has no later step in which to fix
    // it, so charging would strand the money.
    ws40PlatformPaystack();
    ws40PlatformBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    ws40Managed($business, 'bachs', 'acct_ws40', usable: false);

    $resolver = app(PaymentGatewayResolver::class);

    expect(collect($resolver->forStore($store))->keys())->toContain('bachs');

    ws40Switch('bachs');

    expect(collect($resolver->forStore($store))->keys())->not->toContain('bachs')
        ->and($resolver->storeUnavailableReason($store))
        ->toBe('Add the bank account your sales are paid into.');
});

test('the PosCheckoutRequest allow-list drops cash and the legacy transfer alias', function () {
    // Enforced at the rule rather than only at the list, because a till that
    // never offers cash but still accepts a posted `cash` is a rule enforced
    // only on the client.
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    $token = $owner->createToken('pos-access', ['pos'], now()->addHour())->plainTextToken;

    $methods = collect($this->withToken($token)
        ->getJson('/api/v1/pos/stores/'.$store->store_id.'/payment-methods')
        ->assertOk()
        ->json('data.payment_methods'));

    expect($methods)->toBeEmpty();

    // And the till's own fallback is answered rather than left blank.
    $this->withToken($token)
        ->getJson('/api/v1/pos/stores/'.$store->store_id.'/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.unavailable_reason', 'Set the bank account your sales are paid into to take payments.');
});

/*
|--------------------------------------------------------------------------
| The Paystack split, and the guard
|--------------------------------------------------------------------------
*/

test('a platform-processed Paystack charge carries the subaccount and the platform\'s cut', function () {
    ws40PlatformPaystack();
    ws40Commission(2.5);
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);
    ws40Managed($business, 'paystack', 'ACCT_business01');

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'WS40-'.fake()->unique()->numerify('######'),
        'subtotal' => 2500,
        'total' => 2500,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    Http::swap(new Factory);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/ws40', 'reference' => 'ws40_ref'],
    ])]);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ])->assertOk();

    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent) {
        if (! str_contains($request->url(), '/transaction/initialize')) {
            return false;
        }

        $sent = $request->data();

        return true;
    });

    expect($sent['subaccount'])->toBe('ACCT_business01')
        // `bearer: subaccount` makes the business bear Paystack's own processing
        // fee, so the platform's cut is what it says it is.
        ->and($sent['bearer'])->toBe('subaccount')
        // The platform's cut in kobo, per transaction: 2.5% of ₦2,500. It
        // overrides the subaccount's `percentage_charge`, which is why the
        // authoritative figure is sent with the charge.
        ->and($sent['transaction_charge'])->toBe(6250);
});

test('a Paystack charge asking for a subaccount that is missing or mismatched is refused before the request', function () {
    // The highest-risk line in the change. Paystack answers an initialize with
    // no `subaccount` perfectly happily: it charges the platform's own account,
    // pays the business nothing, and reports success. Nothing in the response
    // says a split was dropped, so it must never leave.
    Http::swap(new Factory);
    Http::fake();

    $credentials = GatewayCredentials::make('paystack', [
        'secret_key' => 'sk_live_platform1234567890',
    ], PaymentGatewayRegistry::secretKeysFor('paystack'))->withAccountId('ACCT_business01');

    $gateway = app(PaystackGateway::class);

    $intent = fn (?string $declared): PaymentIntent => new PaymentIntent(
        reference: 'ws40_ref',
        amountMinor: 250000,
        currency: 'NGN',
        email: 'ada@example.test',
        callbackUrl: 'https://shop.test/callback',
        directChargeAccountId: $declared,
        platformFeeMinor: 6250,
    );

    // Declared, and not the account these credentials name.
    $mismatch = $gateway->initialize($intent('ACCT_somebody_else'), $credentials);
    expect($mismatch->success)->toBeFalse();

    // Declared, and not even a subaccount code.
    $malformed = $gateway->initialize($intent('not-an-account'), $credentials);
    expect($malformed->success)->toBeFalse();

    // No credentials for the provider at all.
    $bare = $gateway->initialize($intent('ACCT_business01'), GatewayCredentials::make('paystack', [], ['secret_key']));
    expect($bare->success)->toBeFalse();

    Http::assertNothingSent();

    // And an ordinary charge — no account declared — is untouched by the guard:
    // the platform's own billing, or a business's own keys, still goes out.
    //
    // A fresh recorder, because `Http::fake()` above left a catch-all stub that
    // answers everything with an empty 200 — and stubs are matched in the order
    // they were registered, so the specific one below would never be reached.
    Http::swap(new Factory);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/ws40', 'reference' => 'ws40_ref'],
    ])]);

    $ordinary = $gateway->initialize($intent(null), $credentials);

    expect($ordinary->success)->toBeTrue();

    Http::assertSent(fn (Request $request) => ! array_key_exists('subaccount', $request->data()));
});

test('the connect endpoint settles a Paystack delivery signed with the platform key', function () {
    ws40PlatformPaystack();
    ws40Commission(2.5);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'WS40-'.fake()->unique()->numerify('######'),
        'subtotal' => 2500,
        'total' => 2500,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    $transaction = Transaction::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_id' => $order->id,
        'reference' => 'ws40-webhook-ref',
        'amount' => 2500,
        'currency' => 'NGN',
        'status' => 'pending',
        'payment_method' => 'paystack',
        'metadata' => ['payment_mode' => 'platform_managed', 'platform_fee_kobo' => 6250],
    ]);

    // Two endpoints, because the driver re-verifies through both and refuses
    // when they disagree: the reference lookup, and the list the reference is
    // then found in.
    Http::swap(new Factory);
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'amount' => 250000, 'currency' => 'NGN', 'fees' => 0, 'id' => 4001],
        ]),
        'api.paystack.co/transaction?*' => Http::response([
            'status' => true,
            'data' => [[
                'status' => 'success',
                'reference' => 'ws40-webhook-ref',
                'amount' => 250000,
                'currency' => 'NGN',
                'fees' => 0,
                'id' => 4001,
            ]],
        ]),
    ]);

    $body = json_encode([
        'event' => 'charge.success',
        'data' => ['reference' => $transaction->reference, 'id' => 4001, 'amount' => 250000, 'currency' => 'NGN'],
    ]);

    $signature = hash_hmac('sha512', $body, 'sk_live_platform1234567890');

    $this->call('POST', '/webhooks/connect/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
    ], $body)->assertOk()->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status->value ?? $transaction->fresh()->status)->toBe('confirmed');

    // A delivery that does not verify is refused, and nothing settles.
    $transaction->fresh()->update(['status' => 'pending']);

    $this->call('POST', '/webhooks/connect/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => 'not-the-signature',
    ], $body)->assertStatus(400);

    expect($transaction->fresh()->status->value ?? $transaction->fresh()->status)->toBe('pending');
});

/*
|--------------------------------------------------------------------------
| Bachs: the schedule, and taking the sweep off the money path
|--------------------------------------------------------------------------
*/

test('the account\'s settlement schedule is set to instant, under the account\'s own header', function () {
    ws40PlatformBachs();

    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'acct_ws40'])]);

    $problem = app(BachsOnBehalf::class)->ensureDirectSettlement('acct_ws40', true);

    expect($problem)->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/balance_settings')
        // Addressed as the business: without the header this sets the
        // platform's own schedule and leaves the business's money where it was.
        && $request->hasHeader('X-Account-Id', 'acct_ws40')
        && $request->data()['schedule_by_currency']['NGN']['interval'] === 'instant');

    // And the other mode stays reachable: `auto_payout` off is the platform
    // deliberately keeping the old shape.
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'acct_ws40'])]);

    app(BachsOnBehalf::class)->ensureDirectSettlement('acct_ws40', false);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/balance_settings')
        && $request->data()['schedule_by_currency']['NGN']['interval'] === 'manual');
});

test('the payout sweep never touches a Paystack connection', function () {
    // The sweep is scoped to Bachs, and that scoping is the whole reason a
    // Paystack subaccount cannot be "pulled": its money is already on its way to
    // the business's bank by Paystack's own schedule, and a payout built from it
    // would be an attempt to withdraw money we never held.
    ws40PlatformBachs();
    ws40PlatformPaystack();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Managed($business, 'paystack', 'ACCT_business01');

    Http::swap(new Factory);
    Http::fake();

    $counts = app(PlatformPayoutService::class)->sweep();

    expect($counts)->toBe(['paid' => 0, 'held' => 0, 'skipped' => 0, 'failed' => 0]);
    Http::assertNothingSent();

    // Named directly rather than inferred, so the fact this test exists to pin
    // down is stated: a Paystack account is not a payout candidate.
    expect(app(OnBehalfProcessors::class)->for('paystack')->settlementMode())
        ->toBe(OnBehalfProcessor::SETTLEMENT_DIRECT);
});

test('a business whose payouts are held is given a manual schedule rather than an instant one', function () {
    ws40PlatformBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Managed($business, 'bachs', 'acct_ws40');

    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'acct_ws40'])]);

    $admin = ws40Admin();

    app(PlatformPayoutService::class)->setAutoPayout($business->fresh(), false, $admin);

    // The switch pushes the schedule at the provider, so the admin's flag means
    // the same thing it always did — the money waits — rather than being a
    // promise the provider has not been told about.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/balance_settings')
        && $request->hasHeader('X-Account-Id', 'acct_ws40')
        && $request->data()['schedule_by_currency']['NGN']['interval'] === 'manual');
});

/*
|--------------------------------------------------------------------------
| The other doors
|--------------------------------------------------------------------------
*/

test('connecting own keys is refused while the switch is on', function () {
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->update(['can_connect_own_gateway' => true]);

    $this->withToken($owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken)
        ->putJson('/api/v1/management/payment-gateways/paystack', [
            'public_key' => 'pk_live_own1234567890',
            'secret_key' => 'sk_live_own1234567890',
        ])
        ->assertStatus(422);

    // And the business's screen is not offered a key form whose only outcome is
    // that error.
    $states = collect(app(PaymentGatewayResolver::class)->states((int) $business->id))->keyBy('code');

    expect($states['paystack']['can_connect_own_gateway'])->toBeFalse();
});

test('an invoice cannot be paid through its own Paystack while the switch is on', function () {
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);

    $invoice = Invoice::create([
        'invoice_number' => 'WS40-INV-1',
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'store_id' => $store->id,
        'recipient_name' => 'Ada Obi',
        'recipient_email' => 'ada@example.test',
        'status' => 'sent',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addWeek()->toDateString(),
        'subtotal' => 1000,
        'tax_amount' => 0,
        'total' => 1000,
        'amount_paid' => 0,
        'payment_token' => 'ws40-token',
    ]);

    Http::swap(new Factory);
    Http::fake();

    // The legacy invoice path falls back to the platform's own keys and credits
    // a store balance — the exact "charged through us, unpaid" shape the switch
    // exists to close — so while it is on it refuses with the reason.
    $this->postJson('/pay/invoice/'.$invoice->payment_token.'/initialize', ['amount' => 1000])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Storify processes all payments through Paystack. Invoice payments through a provider of your own are not available while that is switched on.');

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The pre-flight, and the platform's own billing
|--------------------------------------------------------------------------
*/

test('the pre-flight lists the businesses that would stop being able to sell', function () {
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws40Store($owner);
    ws40Managed($business, 'paystack', 'ACCT_business01');

    [$brokenOwner, $broken] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Store($brokenOwner);

    $admin = ws40Admin();

    $payload = $this->getJson('/api/v1/admin/payments/enforcement-preview', ws40AdminHeaders($admin))
        ->assertOk()
        ->assertJsonPath('data.enforced', true)
        ->assertJsonPath('data.processor', 'paystack')
        ->assertJsonPath('data.processor_name', 'Paystack')
        // The switch's own state, and the choices, so the one screen that owns
        // it can be rendered by an admin holding `admin.finance` alone — who may
        // read what the platform is set to without being able to write it.
        ->assertJsonPath('data.stored', 'paystack')
        ->assertJsonPath('data.processors.0.code', 'paystack')
        ->assertJsonPath('data.processors.1.code', 'bachs');

    $lockedOut = collect($payload->json('data.businesses'));

    // The business with an account is not listed; the one without is, with the
    // reason and the stores behind it.
    expect($lockedOut->pluck('business_id')->all())->toBe([(int) $broken->id])
        ->and($lockedOut->first()['stores_locked_out'])->toBe(1)
        ->and($lockedOut->first()['reason'])->toBe('No bank account has been set for its payments to be paid into.')
        ->and($lockedOut->first()['kind'])->toBe('bank_account')
        ->and($payload->json('data.businesses_locked_out'))->toBe(1)
        ->and($payload->json('data.stores_locked_out'))->toBe(1)
        ->and($payload->json('data.businesses_total'))->toBe(2)
        // And the two counts beside the total, which are the difference between
        // a list an admin works through and a list they read. Nobody here has a
        // bank account, so nothing is waiting on setup.
        ->and($payload->json('data.businesses_without_bank_account'))->toBe(1)
        ->and($payload->json('data.businesses_awaiting_setup'))->toBe(0);
});

test('the pre-flight answers for a processor that is not switched on yet', function () {
    ws40PlatformBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Managed($business, 'bachs', 'ACCT_business01');

    [$brokenOwner, $broken] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Store($brokenOwner);

    // Nothing is switched on. Asking what Bachs *would* cost has to work anyway:
    // once the switch is on this becomes a report on damage already done rather
    // than a warning anybody is still in a position to act on.
    $payload = $this->getJson('/api/v1/admin/payments/enforcement-preview?processor=bachs', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        // The state fields keep reporting the present, whatever was asked about.
        ->assertJsonPath('data.enforced', false)
        ->assertJsonPath('data.stored', null)
        ->assertJsonPath('data.processor', null)
        // The numbers describe the scenario that was asked about, and say so.
        ->assertJsonPath('data.counted_for', 'bachs')
        ->assertJsonPath('data.counted_for_name', 'Bachs')
        ->assertJsonPath('data.businesses_locked_out', 1)
        ->assertJsonPath('data.businesses.0.reason', 'No bank account has been set for its payments to be paid into.');

    expect(collect($payload->json('data.businesses'))->pluck('business_id')->all())->toBe([(int) $broken->id]);
});

test('the pre-flight separates businesses that named a bank account from those that have not', function () {
    // The distinction the admin asked for, and the reason it is a distinction:
    // one of these is a conversation and the other is not. A business that has
    // never said where its money should go has to be asked; one that has said,
    // and is merely waiting for an account at the processor in force, has done
    // everything it was ever asked to do — which is exactly what a processor
    // switch looks like from the console, and is not something to go and ask
    // anybody about.
    //
    // Neither row may name the processor. It is Storify's plumbing, the admin
    // has nothing to build there, and a reason that named it is what read as the
    // platform blaming the wrong party.
    ws40PlatformBachs();
    ws40Switch('bachs');

    [$namedOwner, $named] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Store($namedOwner);
    ws40PayoutBank($named);

    [$silentOwner, $silent] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Bola Stores']);
    ws40Store($silentOwner);

    // And one that can sell, which must not be listed however the counts are
    // re-keyed: the list is of businesses that cannot take money, and an account
    // that works is an account that works.
    [$readyOwner, $ready] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Chidi Ltd']);
    ws40Store($readyOwner);
    ws40Managed($ready, 'bachs', 'ACCT_ready');

    $payload = $this->getJson('/api/v1/admin/payments/enforcement-preview', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->assertJsonPath('data.businesses_locked_out', 2)
        ->assertJsonPath('data.businesses_total', 3)
        ->assertJsonPath('data.businesses_without_bank_account', 1)
        ->assertJsonPath('data.businesses_awaiting_setup', 1);

    $rows = collect($payload->json('data.businesses'))->keyBy('business_id');

    expect($rows->keys()->all())->not->toContain((int) $ready->id)
        ->and($rows[(int) $silent->id]['reason'])->toBe('No bank account has been set for its payments to be paid into.')
        ->and($rows[(int) $silent->id]['kind'])->toBe('bank_account')
        ->and($rows[(int) $named->id]['reason'])->toBe('Its bank account is on file, but its payment account has not been set up yet.')
        ->and($rows[(int) $named->id]['kind'])->toBe('setup');

    // No processor name anywhere in the reasons, which is the whole complaint.
    expect(collect($rows)->pluck('reason')->implode(' '))->not->toContain('Bachs');
});

test('the pre-flight counts nobody when no processor is named and none is set', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Store($owner);

    // Off, and nothing asked: there is no processor to test a business against,
    // and a number produced here would be one nobody asked for.
    $this->getJson('/api/v1/admin/payments/enforcement-preview', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->assertJsonPath('data.enforced', false)
        ->assertJsonPath('data.counted_for', null)
        ->assertJsonPath('data.counted_for_name', null)
        ->assertJsonPath('data.businesses_locked_out', 0)
        ->assertJsonPath('data.businesses_total', 1)
        ->assertJsonPath('data.businesses', []);
});

test('the pre-flight refuses a processor it does not know', function () {
    // Not silently answered with the current setting's numbers: a panel that
    // asked about one processor and was shown another's would be lying about
    // the only thing it exists to say.
    $this->getJson('/api/v1/admin/payments/enforcement-preview?processor=flutterwave', ws40AdminHeaders(ws40Admin()))
        ->assertStatus(422)
        ->assertJsonValidationErrors('processor');
});

test('the pre-flight names a selected processor this build cannot run', function () {
    // Fail closed, and be legible while doing it: `processor` is null because
    // there is nothing to run, but the raw stored value is what tells an admin
    // this switch is *on and broken* rather than off — the difference between
    // hunting for a misconfiguration and thinking nothing is selected.
    ws40Settings(['system_payment_processor' => 'flutterwave']);

    $this->getJson('/api/v1/admin/payments/enforcement-preview', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->assertJsonPath('data.enforced', true)
        ->assertJsonPath('data.stored', 'flutterwave')
        ->assertJsonPath('data.processor', null)
        ->assertJsonPath('data.businesses_locked_out', 0);
});

test('the platform\'s own subscription billing follows the selected processor', function () {
    ws40PlatformPaystack();
    ws40PlatformBachs();

    ws40Settings(['billing_gateway' => 'paystack']);

    expect(app(PlatformBillingGateway::class)->code())->toBe('paystack');

    ws40Switch('bachs');

    expect(app(PlatformBillingGateway::class)->code())->toBe('bachs');

    ws40Switch(null);

    // Off, and the column is what it always was.
    expect(app(PlatformBillingGateway::class)->code())->toBe('paystack');
});
