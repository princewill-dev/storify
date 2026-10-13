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
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-40 — the system payment processor.
|
| `settings.system_payment_processor` is one switch with two states and a third
| that is neither: unset means the platform behaves exactly as it always has,
| set means every business's customer inflow goes through the platform's own
| credentials on that one provider, and a *stored* value this build cannot run
| means enforced-with-no-processor rather than off.
|
| The three states are what this suite pins down, because each of them is a
| decision that would be silent if it were wrong: an off deployment must be
| byte-identical to what it was, an on deployment must not fall back to a
| business's own keys, and a broken switch must fail closed rather than open.
|
| What the switch buys changed shape with collect-and-pay-out, and this file
| changed with it. Under enforcement the charge is Storify's own: it carries the
| platform's key and names nothing of the business's — no settlement account, no
| destination, no header — because the gross lands in Storify's balance and a
| payout run hands it over afterwards. So the provisioning machinery these tests
| used to arrange is gone rather than updated, and what a business's payment
| rows are read for is the one thing they still say on its behalf: its owner has
| switched Storify payments *off*.
|
| The other half of the model lives where it belongs rather than here. What a
| collected charge does to the wallet and both sets of books is WS-45's subject;
| what a delivery settles is WS-39's. This file is about the switch: what it
| offers, what it refuses, and who it leaves holding money nobody can hand over.
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
 * A business Storify collects for.
 *
 * The column defaults true and the migration that decommissioned the connected
 * accounts backfilled every row, so a business a test creates is already
 * collected for. Written out anyway, for the same reason it is written out in
 * the custody suites: what every test below turns on is the *distinction*, and a
 * suite whose precondition is a column default stops testing anything the day
 * the default moves.
 */
function ws40Collected(Business $business): Business
{
    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();

    return $business->fresh();
}

/**
 * A business still on the old arrangement, whose own keys charge its customers.
 *
 * Not an arrangement a deployment should be in once the switch is on — the
 * enforcement pre-flight exists to name exactly these businesses — which is why
 * so many of the tests below have to arrange it deliberately.
 */
function ws40NotCollected(Business $business): Business
{
    $business->forceFill(['custody_enabled' => false, 'custody_enabled_at' => null])->save();

    return $business->fresh();
}

/**
 * The one thing a payment-method row still says on a business's behalf: its
 * owner has switched Storify payments off.
 *
 * Rows used to carry the settlement account the platform charged through, and
 * every test that wanted a *chargeable* business had to write one. There is no
 * account any more — the charge is Storify's own and names nothing of the
 * business's, and `resolveEnforced()` reads the rows for `is_active` and nothing
 * else — so the only row worth writing here is a "no".
 */
function ws40SwitchedOff(Business $business, string $code = 'paystack'): void
{
    $method = PaymentMethod::where('code', $code)->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        ['is_active' => false, 'config' => null, 'created_at' => now(), 'updated_at' => now()],
    );
}

/**
 * The bank account a business's sales are paid into.
 *
 * The pointer and nothing else. It is what the payout sweep reads and therefore
 * what decides whether a business that can sell can also be *paid*, which is now
 * a separate question from whether it can sell at all.
 */
function ws40PayoutBank(Business $business): StoreBank
{
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $business->forceFill(['payout_bank_id' => $bank->id])->save();

    return $bank;
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

test('the settings payload offers exactly the processors the platform can charge through', function () {
    ws40Admin();

    $options = collect($this->getJson('/api/v1/admin/settings', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->json('data.settings.system_payment_processors'))
        ->pluck('code')
        ->all();

    // The platform's own list, not one written into the console: a provider
    // Storify holds no key for cannot be *the platform's* processor, because
    // there would be nothing for it to charge with. There is no second
    // condition any more — the per-business account that used to gate this went
    // with the accounts.
    expect($options)->toBe(SystemProcessor::selectableCodes())
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
        ->and($resolved->isManaged())->toBeFalse()
        // And nothing is recorded as collected, whatever the business's own
        // custody flag says. With no processor enforced the charge is the
        // business's, its money lands in the business's own provider account,
        // and a `true` here would have the delivery credit a wallet for money
        // Storify never held: the business paid twice for one sale.
        ->and($resolved->custody)->toBeFalse();

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

test('under enforcement the platform\'s own keys are used and a business\'s are ignored', function () {
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->update(['can_connect_own_gateway' => true]);
    ws40Collected($business);
    $store = ws40Store($owner);

    // Keys the business connected when it ran its own gateway, still stored. They
    // are the live defect the switch closes as much as they are a rule of it:
    // the ordinary path keeps charging through stored keys after an admin takes
    // the grant away, so the console's promise that they "stop being used
    // immediately" was not true of the code.
    ws40OwnKeys($business, 'paystack', ws40PaystackKeys());

    $resolved = app(PaymentGatewayResolver::class)->connection((int) $business->id, 'paystack');

    expect($resolved->isEnabled)->toBeTrue()
        // The platform's key, not the business's: the two are different strings
        // and this is the assertion that says which one went into the driver.
        ->and($resolved->credentials->get('secret_key'))->toBe('sk_live_platform1234567890')
        ->and($resolved->isManaged())->toBeTrue()
        ->and($resolved->custody)->toBeTrue();

    expect(collect($this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->json('data.payment_methods'))->pluck('code')->all())
        ->toBe(['paystack']);
});

test('a store\'s own key row cannot mask the platform\'s charge', function () {
    // The ordinary precedence prefers a store row that carries a connection,
    // which for a store holding its own keys is exactly the row that would keep
    // the charge on the business's account. Under enforcement the only path that
    // supplies credentials is the platform's, so a store's stored keys are read
    // for nothing at all.
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Collected($business);
    $store = ws40Store($owner);

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

    expect($resolved->credentials->get('secret_key'))->toBe('sk_live_platform1234567890')
        ->and($resolved->isEnabled)->toBeTrue()
        ->and($resolved->isManaged())->toBeTrue();
});

test('every provider but the selected one is not offered, and says why', function () {
    ws40PlatformBachs();
    ws40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Collected($business);
    $store = ws40Store($owner);

    // A Paystack connection the business holds, and the offline rail it could
    // have been assigned — neither is a way money may arrive any more.
    ws40OwnKeys($business, 'paystack', ws40PaystackKeys());
    ws40Assign($business, $store, 'bank_transfer');

    expect(collect($this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->json('data.payment_methods'))->pluck('code')->all())
        ->toBe(['bachs']);

    $states = collect(app(PaymentGatewayResolver::class)->states((int) $business->id, $store))->keyBy('code');

    expect($states['paystack']['is_enabled'])->toBeFalse()
        ->and($states['paystack']['unavailable_reason'])->toBe('Storify processes all payments through Bachs.')
        ->and($states['bank_transfer']['unavailable_reason'])->toBe('Storify processes all payments through Bachs.')
        // And the business's own screen is told the regime rather than being
        // left to infer it from a card that has gone quiet.
        ->and($states['bachs']['platform_enforced'])->toBeTrue()
        ->and($states['bachs']['platform_enforced_code'])->toBe('bachs')
        ->and($states['bachs']['custody'])->toBeTrue();
});

test('a business Storify does not collect for is offered nothing, and told why', function () {
    // The refusal that replaced four account-shaped ones. A business an admin has
    // not yet flipped cannot be charged through the platform at all, because the
    // gross would land in Storify's balance with nothing recording that the
    // business is owed it — which is precisely the outcome the whole change
    // exists to prevent, and a storefront that quietly took the payment anyway
    // would be the way it happened.
    //
    // The sentence names an admin's step rather than the business's. It used to
    // say the business had "not registered a settlement account", which is
    // Storify's word for its own plumbing and names something the business was
    // never given.
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40NotCollected($business);
    $store = ws40Store($owner);

    $payload = $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->assertOk();

    expect($payload->json('data.payment_methods'))->toBe([])
        ->and($payload->json('data.unavailable_reason'))
        ->toBe('Storify is not collecting payments for this business yet, so it cannot take payments here.');
});

test('a business Storify collects for is offered payments whether or not it has named a bank', function () {
    // The gate this change exists to open, asserted where the closing one used to
    // be. A destination had to be registered to *sell*, so a business that had
    // not filled in a bank form could not ring up a sale at all — a failure worse
    // than the one it prevented. The money now waits for the bank instead of the
    // customer waiting for the money.
    //
    // Both halves matter. Offered, and with nothing to say about it: the empty
    // reason is the assertion that a missing destination is no longer a reason a
    // customer sees nothing at checkout.
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Collected($business);
    $store = ws40Store($owner);

    expect($business->fresh()->payout_bank_id)->toBeNull();

    $resolver = app(PaymentGatewayResolver::class);

    expect(collect($resolver->forStore($store))->keys())->toContain('paystack')
        ->and($resolver->storeUnavailableReason($store))->toBeNull()
        ->and(collect($this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->json('data.payment_methods'))->pluck('code')->all())
        ->toBe(['paystack']);
});

test('a business that switched Storify payments off is told so', function () {
    // The one thing a payment-method row still says on a business's behalf, and
    // the only state in which the owner rather than an admin is the one who can
    // put it right. Asked *after* custody, because a business Storify does not
    // collect for cannot be charged whatever its rows say, and telling its owner
    // to switch payments back on would be advice that changes nothing.
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Collected($business);
    $store = ws40Store($owner);

    ws40SwitchedOff($business, 'paystack');

    $payload = $this->getJson('/api/v1/storefront/'.$store->slug.'/payment-methods')->assertOk();

    expect($payload->json('data.payment_methods'))->toBe([])
        ->and($payload->json('data.unavailable_reason'))
        ->toBe('You have switched Storify payments off. Switch it back on to take payments again.');

    $state = collect(app(PaymentGatewayResolver::class)->states((int) $business->id, $store))
        ->firstWhere('code', 'paystack');

    // Reported as a switch somebody threw rather than as nothing being
    // connected at all, which is what lets the screen offer it back to its owner
    // instead of showing them a card they have to set up from scratch. `source`
    // is the half that proves the row was read: `none` is the business that has
    // never touched this screen, and the two are not the same state.
    expect($state['is_paused'])->toBeTrue()
        ->and($state['source'])->toBe('business');
});

test('the till loses cash and every provider while the platform is enforcing, and is told why', function () {
    // The till offers two things when this switch is off: cash, and a transfer
    // into a bank account of the business's own. Neither is money the platform
    // sees or can split, which is exactly what a system processor rules out — so
    // under it the till offers nothing at all, and this is the assertion that
    // says so rather than only the list.
    //
    // The account below is what makes the assertion mean anything: without it
    // the list would be down to cash anyway, and "empty" would be true for a
    // reason that has nothing to do with enforcement.
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40NotCollected($business);
    $store = ws40Store($owner);

    StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'WS40 Trading Ltd',
        'is_primary' => true,
        'is_verified' => true,
    ]);

    $token = $owner->createToken('pos-access', ['pos'], now()->addHour())->plainTextToken;

    $payload = $this->withToken($token)
        ->getJson('/api/v1/pos/stores/'.$store->store_id.'/payment-methods')
        ->assertOk();

    expect($payload->json('data.payment_methods'))->toBeEmpty()
        // The till's own fallback, from the same method the storefront reads, so
        // a cashier can be told whether to send the customer to the bank or to
        // call support.
        ->and($payload->json('data.unavailable_reason'))
        ->toBe('Storify is not collecting payments for this business yet, so it cannot take payments here.')
        // And the per-method reason stays silent: there is nothing to say about
        // one rail being withheld when the whole till is down.
        ->and($payload->json('data.bank_transfer_unavailable_reason'))->toBeNull();

    // Enforced and not collected for, which is the state this pins. A till that
    // is offered nothing must not accept a posted transfer either.
    $this->withToken($token)
        ->postJson('/api/v1/pos/stores/'.$store->store_id.'/checkout', [
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payments' => [['method' => 'bank_transfer', 'amount' => 1000]],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['payments.0.method']);
});

/*
|--------------------------------------------------------------------------
| The platform's own endpoint, under the platform's own key
|--------------------------------------------------------------------------
*/

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
    // Left on the old arrangement deliberately: with custody on the *custody*
    // guard answers first and this test would be pinning the other sentence. The
    // test below is the one that pins it.
    ws40NotCollected($business);
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

test('an invoice cannot be paid through its own Paystack while Storify collects for the business', function () {
    // **The other switch, and the reason it needs its own guard.** Custody is set
    // per business and the processor switch is platform-wide, and the two are set
    // at different moments — a pilot business is flipped first and the switch
    // follows. In that window every other path already charges on Storify's
    // credentials, and this one would still take an invoice payment through a
    // Paystack account the wallet never sees, credit `stores.balance` for it, and
    // leave money owed to a business that no payout run can pay out.
    ws40Switch(null);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Collected($business);
    $store = ws40Store($owner);
    $invoice = ws40Invoice($business, $store, 'ws40-custody-token');

    Http::swap(new Factory);
    Http::fake();

    $this->postJson('/pay/invoice/ws40-custody-token/initialize', ['amount' => 1000])
        ->assertStatus(409)
        ->assertJsonPath(
            'message',
            "Storify collects this business's payments, so payments to this invoice must be taken through "
            .'Storify. Paying it through a provider of your own is not available.',
        );

    // The offline rail too: a bank-transfer slip is money paid outside the
    // platform, so there is no collection for Storify to hold and no wallet
    // credit to make for it.
    $this->post('/pay/invoice/ws40-custody-token/bank-transfer', [])
        ->assertRedirect();

    Http::assertNothingSent();
});

test('the invoice path is open again the moment the business is not collected for', function () {
    // The control for the guard above. Without it, a refusal that fired for
    // every business would pass the test above and quietly stop invoice payments
    // for the whole platform.
    ws40Switch(null);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40NotCollected($business);
    $store = ws40Store($owner);
    ws40Invoice($business, $store, 'ws40-own-token');

    Http::swap(new Factory);
    Http::fake();

    $this->postJson('/pay/invoice/ws40-own-token/initialize', ['amount' => 1000]);

    // Past the guard and out to Paystack, whatever Paystack then says to a fake.
    Http::assertSentCount(1);
});

function ws40Invoice(Business $business, Store $store, string $token): Invoice
{
    return Invoice::create([
        'invoice_number' => 'WS40-INV-'.strtoupper(Str::random(6)),
        'business_id' => $business->id,
        'user_id' => $business->user_id,
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
        'payment_token' => $token,
    ]);
}

/*
|--------------------------------------------------------------------------
| The pre-flight, and the platform's own billing
|--------------------------------------------------------------------------
*/

test('the pre-flight lists the businesses Storify has not started collecting for', function () {
    ws40PlatformPaystack();
    ws40Switch('paystack');

    [$readyOwner, $ready] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Collected($ready);
    ws40Store($readyOwner);

    [$brokenOwner, $broken] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Bola Stores']);
    ws40NotCollected($broken);
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
        ->assertJsonPath('data.platform_offers_processor', true)
        ->assertJsonPath('data.processors.0.code', 'paystack')
        ->assertJsonPath('data.processors.1.code', 'bachs');

    $lockedOut = collect($payload->json('data.businesses'));

    // The business Storify collects for is not listed as blocked; the one still
    // on the old arrangement is, with the reason and the stores behind it.
    expect($lockedOut->pluck('business_id')->all())->toBe([(int) $broken->id])
        ->and($lockedOut->first()['stores_locked_out'])->toBe(1)
        ->and($lockedOut->first()['reason'])->toBe('Its sales are not yet collected by Storify, so there is nothing to charge them with.')
        ->and($lockedOut->first()['kind'])->toBe('custody')
        ->and($payload->json('data.businesses_locked_out'))->toBe(1)
        ->and($payload->json('data.stores_locked_out'))->toBe(1)
        ->and($payload->json('data.businesses_total'))->toBe(2)
        // And the two counts beside the total, which are the difference between
        // a list an admin works through and a list they read.
        ->and($payload->json('data.businesses_awaiting_custody'))->toBe(1)
        ->and($payload->json('data.businesses_switched_off'))->toBe(0)
        // The business that can trade is counted somewhere else, and it matters
        // that it is somewhere else: it has not named a bank, so its money
        // accumulates in Storify's balance, but it is working and must not be
        // reported to an admin as broken.
        ->and($payload->json('data.businesses_cannot_be_paid'))->toBe(1);
});

test('the pre-flight answers for a processor that is not switched on yet', function () {
    ws40PlatformBachs();

    [$readyOwner, $ready] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Collected($ready);
    ws40Store($readyOwner);

    [$brokenOwner, $broken] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Bola Stores']);
    ws40NotCollected($broken);
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
        ->assertJsonPath('data.businesses.0.reason', 'Its sales are not yet collected by Storify, so there is nothing to charge them with.');

    expect(collect($payload->json('data.businesses'))->pluck('business_id')->all())->toBe([(int) $broken->id]);
});

test('the pre-flight separates businesses that are not collected for from those that switched payments off', function () {
    // The distinction the admin asked for, and the reason it is a distinction:
    // these are two different conversations. One is "we have not moved you onto
    // the new rail yet", which is Storify's own step; the other is a business
    // that has asked not to be charged through us, which only it can answer.
    //
    // Neither row may name the processor. It is Storify's plumbing, the admin
    // has nothing to build there, and a reason that named it is what read as the
    // platform blaming the wrong party.
    ws40PlatformBachs();
    ws40Switch('bachs');

    [$offOwner, $off] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Collected($off);
    ws40Store($offOwner);
    ws40SwitchedOff($off, 'bachs');

    [$uncollectedOwner, $uncollected] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Bola Stores']);
    ws40NotCollected($uncollected);
    ws40Store($uncollectedOwner);

    // And one that can sell, which must not be listed however the counts are
    // re-keyed: the list is of businesses that cannot take money, and a business
    // the platform collects for is one that can — whether or not it has named a
    // bank to be paid into.
    [$readyOwner, $ready] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Chidi Ltd']);
    ws40Collected($ready);
    ws40Store($readyOwner);

    $payload = $this->getJson('/api/v1/admin/payments/enforcement-preview', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->assertJsonPath('data.businesses_locked_out', 2)
        ->assertJsonPath('data.businesses_total', 3)
        ->assertJsonPath('data.businesses_awaiting_custody', 1)
        ->assertJsonPath('data.businesses_switched_off', 1);

    $rows = collect($payload->json('data.businesses'))->keyBy('business_id');

    expect($rows->keys()->all())->not->toContain((int) $ready->id)
        ->and($rows[(int) $uncollected->id]['kind'])->toBe('custody')
        ->and($rows[(int) $uncollected->id]['reason'])->toBe('Its sales are not yet collected by Storify, so there is nothing to charge them with.')
        ->and($rows[(int) $off->id]['kind'])->toBe('connection')
        ->and($rows[(int) $off->id]['reason'])->toBe('Payments are switched off for this connection.');

    // No processor name anywhere in the reasons, which is the whole complaint.
    expect(collect($rows)->pluck('reason')->implode(' '))->not->toContain('Bachs');
});

test('the pre-flight reports whether the platform itself still offers the processor', function () {
    // The fourth way a store ends up unchargeable, and the only one that is not
    // about a business at all: an admin has switched the provider off platform-
    // wide, so every business is blocked for a reason none of them can act on.
    // Reported as one field rather than multiplied across every business in the
    // list, because a list of all of them would bury the one fact that matters.
    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();
    DB::table('payment_methods')->where('id', $method->id)->update(['is_active' => false]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws40Collected($business);
    ws40Store($owner);

    $payload = $this->getJson('/api/v1/admin/payments/enforcement-preview?processor=bachs', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->assertJsonPath('data.platform_offers_processor', false)
        ->assertJsonPath('data.businesses_locked_out', 0);

    // And no business is listed for it. Every one of them can still trade: this
    // is the platform's switch, the platform is what has to move it, and naming
    // the businesses would be handing an admin a list of people to call about
    // something none of them did.
    expect($payload->json('data.businesses_awaiting_custody'))->toBe(0)
        ->and($payload->json('data.businesses_switched_off'))->toBe(0);
});

test('the pre-flight counts a business that can sell but cannot be paid separately', function () {
    // The two questions used to be one. A business with a working account and no
    // bank account named was refused at checkout, so it was "locked out" and
    // belonged in the same list as a business the platform cannot charge for at
    // all.
    //
    // It does not any more: it can take money, and the money waits for it. But
    // "who are we holding money for that we cannot hand over" is still a question
    // worth answering before it becomes a backlog, so it gets its own list —
    // *not* the locked-out one, because a business that is trading happily must
    // not be reported to an admin as broken.
    ws40PlatformBachs();
    ws40Switch('bachs');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Collected($business);
    ws40Store($owner);

    $payload = $this->getJson('/api/v1/admin/payments/enforcement-preview', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->assertJsonPath('data.businesses_locked_out', 0)
        ->assertJsonPath('data.stores_locked_out', 0)
        ->assertJsonPath('data.businesses_cannot_be_paid', 1);

    expect(collect($payload->json('data.businesses'))->pluck('business_id')->all())->toBe([]);

    $row = collect($payload->json('data.cannot_be_paid'))->first();

    expect($row['business_id'])->toBe((int) $business->id)
        ->and($row['reason'])->toBe('It can take payments, but it has not named a bank account to be paid into.');
});

test('the pre-flight does not list a business the payout run could pay', function () {
    // The other side of the list, so a bug that lists everybody is caught. The
    // bank fields are what decide it, because they are what the sweep reads: a
    // `payout_bank_id` pointing at a row with no account number is a business
    // the sweep skips in silence, and it belongs on the list.
    ws40PlatformBachs();
    ws40Switch('bachs');

    [$paidOwner, $paid] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Ada Trading Ltd']);
    ws40Collected($paid);
    ws40Store($paidOwner);
    ws40PayoutBank($paid);

    [$blankOwner, $blank] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['name' => 'Bola Stores']);
    ws40Collected($blank);
    ws40Store($blankOwner);

    // Empty strings rather than nulls: the columns are not nullable, and the
    // half-filled row is the state the check has to survive — a bank pointer
    // whose row a business never finished, which the sweep skips in silence.
    $bank = StoreBank::factory()->create([
        'business_id' => $blank->id,
        'bank_code' => '',
        'account_number' => '',
    ]);

    $blank->forceFill(['payout_bank_id' => $bank->id])->save();

    $payload = $this->getJson('/api/v1/admin/payments/enforcement-preview', ws40AdminHeaders(ws40Admin()))
        ->assertOk()
        ->assertJsonPath('data.businesses_cannot_be_paid', 1);

    expect(collect($payload->json('data.cannot_be_paid'))->pluck('business_id')->all())
        ->toBe([(int) $blank->id]);
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
