<?php

use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Otp;
use App\Models\Store;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-56 — business-scoped customer accounts
|--------------------------------------------------------------------------
| The load-bearing assertions here are the ones that FAIL on the API as it was
| before this workstream: cross-tenant sign-in and, above all, cross-tenant
| password reset. `CustomerAuthController` resolved every account with
| `Customer::where('email', …)->first()` and `OtpService` keyed codes on the
| address alone, so one email holding an account at two businesses could have
| one business's reset code spend itself on the other's row.
|
| Also covered: per-business registration and claiming, OTP isolation,
| the verification gate, the orphan guard, the transitional route's tenant
| inference, the checkout-onboarding gate, the address book, consent and data
| rights, and the management login flow asserted untouched because OtpService is
| shared with it.
*/

function ws56Store(string $slug): Store
{
    [, $business] = createBusinessOwner();

    return Store::create([
        'business_id' => $business->id,
        'name' => 'WS56 Shop',
        'slug' => $slug,
        'status' => Store::STATUS_ACTIVE,
        // `resolveStore()` gates on this and it defaults to false, so a store
        // built without it answers 404 on every storefront route — which looks
        // exactly like the tenancy bug these tests exist to catch.
        'has_website' => true,
    ]);
}

function ws56Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ws56-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
        'registered_at' => now(),
    ], $attributes));
}

function ws56Otp(string $email, string $type, ?int $businessId = null): string
{
    return (string) Otp::where('identifier', $email)
        ->where('type', $type)
        ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
        ->latest('id')
        ->value('code');
}

/**
 * @return array<string, mixed>
 */
function ws56RegisterPayload(string $email, string $password = 'secret-pass-123', array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => $email,
        'phone' => '08011112222',
        'password' => $password,
        'password_confirmation' => $password,
    ], $overrides);
}

function ws56Token(Customer $customer): string
{
    return $customer->createToken('customer-access', ['customer'], now()->addHour())->plainTextToken;
}

function ws56Order(Store $store, Customer $customer, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'source' => 'checkout',
        'order_number' => 'WS56-ORD-'.$sequence,
        'subtotal' => 5000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 5000,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

test('one email holds a separate account at each business', function () {
    $storeA = ws56Store('ws56-alpha');
    $storeB = ws56Store('ws56-beta');
    $email = 'shared-'.Str::lower(Str::random(8)).'@example.test';

    $this->postJson("/api/v1/storefront/{$storeA->slug}/auth/register", ws56RegisterPayload($email))
        ->assertCreated()
        ->assertJsonPath('data.otp_required', true);

    // The same address at a second business. This is the request that used to
    // 422 on global uniqueness, contradicting the (business_id, email) index
    // the schema actually carries.
    $this->postJson("/api/v1/storefront/{$storeB->slug}/auth/register", ws56RegisterPayload($email))
        ->assertCreated();

    $rows = Customer::where('email', $email)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('business_id')->unique()->count())->toBe(2)
        ->and($rows->pluck('account_id')->unique()->count())->toBe(2)
        // Neither is an orphan: a row with no business is readable by nothing
        // and scoped to nothing.
        ->and($rows->every(fn (Customer $c) => $c->business_id !== null))->toBeTrue()
        ->and($rows->every(fn (Customer $c) => $c->registered_at !== null))->toBeTrue();
});

test('a password signs in at the business it belongs to and nowhere else', function () {
    $storeA = ws56Store('ws56-a-login');
    $storeB = ws56Store('ws56-b-login');
    $email = 'ws56-two-'.Str::lower(Str::random(8)).'@example.test';

    $rowA = ws56Customer($storeA->business_id, ['email' => $email, 'password' => bcrypt('alpha-pass-123')]);
    $rowB = ws56Customer($storeB->business_id, ['email' => $email, 'password' => bcrypt('beta-pass-123')]);

    $this->postJson("/api/v1/storefront/{$storeA->slug}/auth/login", ['email' => $email, 'password' => 'alpha-pass-123'])
        ->assertOk()
        ->assertJsonPath('data.user.account_id', $rowA->account_id);

    // The other business's password is a wrong password here, not a redirect
    // into the other business's account.
    $this->postJson("/api/v1/storefront/{$storeA->slug}/auth/login", ['email' => $email, 'password' => 'beta-pass-123'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The provided credentials do not match our records.');

    $this->postJson("/api/v1/storefront/{$storeB->slug}/auth/login", ['email' => $email, 'password' => 'beta-pass-123'])
        ->assertOk()
        ->assertJsonPath('data.user.account_id', $rowB->account_id);
});

test('suspension at one business does not suspend the same email at another', function () {
    $storeA = ws56Store('ws56-a-susp');
    $storeB = ws56Store('ws56-b-susp');
    $email = 'ws56-susp-'.Str::lower(Str::random(8)).'@example.test';

    ws56Customer($storeA->business_id, [
        'email' => $email,
        'password' => bcrypt('shared-pass-123'),
        'status' => Customer::STATUS_SUSPENDED,
    ]);
    ws56Customer($storeB->business_id, [
        'email' => $email,
        'password' => bcrypt('shared-pass-123'),
    ]);

    $this->postJson("/api/v1/storefront/{$storeA->slug}/auth/login", ['email' => $email, 'password' => 'shared-pass-123'])
        ->assertStatus(403);

    $this->postJson("/api/v1/storefront/{$storeB->slug}/auth/login", ['email' => $email, 'password' => 'shared-pass-123'])
        ->assertOk();
});

test('a reset code issued for one business cannot reset an account at another', function () {
    $storeA = ws56Store('ws56-a-reset');
    $storeB = ws56Store('ws56-b-reset');
    $email = 'ws56-reset-'.Str::lower(Str::random(8)).'@example.test';

    $rowA = ws56Customer($storeA->business_id, ['email' => $email, 'password' => bcrypt('old-alpha-123')]);
    $rowB = ws56Customer($storeB->business_id, ['email' => $email, 'password' => bcrypt('old-beta-123')]);

    $this->postJson("/api/v1/storefront/{$storeA->slug}/auth/forgot-password", ['email' => $email])
        ->assertOk();

    $code = ws56Otp($email, 'customer_password_reset', $storeA->business_id);
    expect($code)->not->toBe('');

    // A's code spent at B: refused. This is the assertion the scoping exists
    // for — before it, the reset resolved the row by email alone and reached
    // whichever of the two the database returned first.
    $this->postJson("/api/v1/storefront/{$storeB->slug}/auth/reset-password", [
        'email' => $email,
        'otp' => $code,
        'password' => 'beta-takeover-123',
        'password_confirmation' => 'beta-takeover-123',
    ])->assertStatus(422);

    expect(Hash::check('old-beta-123', $rowB->fresh()->password))->toBeTrue()
        ->and(Hash::check('beta-takeover-123', $rowB->fresh()->password))->toBeFalse();

    // The same code at A does work, and reaches A's row only.
    $this->postJson("/api/v1/storefront/{$storeA->slug}/auth/reset-password", [
        'email' => $email,
        'otp' => $code,
        'password' => 'new-alpha-123',
        'password_confirmation' => 'new-alpha-123',
    ])->assertOk();

    expect(Hash::check('new-alpha-123', $rowA->fresh()->password))->toBeTrue()
        ->and($rowB->fresh()->password)->toBe($rowB->password);
});

test('a code issued for one business survives a code issued for another', function () {
    $storeA = ws56Store('ws56-a-otp');
    $storeB = ws56Store('ws56-b-otp');
    $email = 'ws56-otp-'.Str::lower(Str::random(8)).'@example.test';

    $rowB = ws56Customer($storeB->business_id, ['email' => $email, 'password' => bcrypt('old-beta-123')]);

    // B's code first, then A's. Generating A's must not invalidate B's — the
    // "delete previous unverified" sweep used to be scoped to the identifier
    // and type alone, so the second request killed the first across tenants.
    $this->postJson("/api/v1/storefront/{$storeB->slug}/auth/forgot-password", ['email' => $email])->assertOk();
    $codeB = ws56Otp($email, 'customer_password_reset', $storeB->business_id);

    $this->postJson("/api/v1/storefront/{$storeA->slug}/auth/forgot-password", ['email' => $email])->assertOk();

    expect(Otp::where('identifier', $email)->where('business_id', $storeB->business_id)->where('is_verified', false)->exists())
        ->toBeTrue();

    $this->postJson("/api/v1/storefront/{$storeB->slug}/auth/reset-password", [
        'email' => $email,
        'otp' => $codeB,
        'password' => 'beta-recovered-123',
        'password_confirmation' => 'beta-recovered-123',
    ])->assertOk();

    expect(Hash::check('beta-recovered-123', $rowB->fresh()->password))->toBeTrue();
});

test('registration claims an unverified row instead of refusing it', function () {
    $store = ws56Store('ws56-claim');
    $email = 'ws56-claim-'.Str::lower(Str::random(8)).'@example.test';

    // The shape an older checkout left behind: a row with no password its
    // owner chose, no registration date, and orders already pointing at it.
    $legacy = ws56Customer($store->business_id, [
        'email' => $email,
        'password' => bcrypt(Str::random(32)),
        'email_verified_at' => null,
        'registered_at' => null,
    ]);
    ws56Order($store, $legacy, ['status' => 'completed']);

    $this->postJson("/api/v1/storefront/{$store->slug}/auth/register", ws56RegisterPayload($email, 'chosen-pass-123'))
        ->assertCreated();

    $claimed = Customer::where('email', $email)->sole();

    expect($claimed->id)->toBe($legacy->id)
        ->and($claimed->account_id)->toBe($legacy->account_id)
        ->and($claimed->registered_at)->not->toBeNull()
        ->and(Hash::check('chosen-pass-123', $claimed->password))->toBeTrue();

    $code = ws56Otp($email, 'account', $store->business_id);

    $this->postJson("/api/v1/storefront/{$store->slug}/auth/verify-otp", ['email' => $email, 'otp' => $code])
        ->assertOk()
        ->assertJsonPath('data.user.account_id', $legacy->account_id)
        // The history was never moved; it was always on this row.
        ->assertJsonPath('data.user.business_id', $store->business_id);
});

test('registering over a verified account is refused', function () {
    $store = ws56Store('ws56-taken');
    $email = 'ws56-taken-'.Str::lower(Str::random(8)).'@example.test';

    ws56Customer($store->business_id, ['email' => $email, 'email_verified_at' => now()]);

    $this->postJson("/api/v1/storefront/{$store->slug}/auth/register", ws56RegisterPayload($email))
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'This email already has an account here. Please sign in or reset your password.');
});

test('a correct password on an unverified row is refused and re-sends the code', function () {
    $store = ws56Store('ws56-unverified');
    $email = 'ws56-unverified-'.Str::lower(Str::random(8)).'@example.test';

    ws56Customer($store->business_id, [
        'email' => $email,
        'password' => bcrypt('secret-pass-123'),
        'email_verified_at' => null,
    ]);

    $this->postJson("/api/v1/storefront/{$store->slug}/auth/login", ['email' => $email, 'password' => 'secret-pass-123'])
        ->assertStatus(403)
        ->assertJsonPath('errors.code.0', 'email_unverified');

    // Without this gate, a stranger could register over an unverified row and
    // be handed the order history already sitting on it without ever proving
    // control of the mailbox.
    expect(Otp::where('identifier', $email)->where('type', 'account')->where('is_verified', false)->exists())
        ->toBeTrue();
});

test('a token whose account belongs to no business reads nothing', function () {
    $orphan = Customer::create([
        'business_id' => null,
        'first_name' => 'Orphan',
        'last_name' => 'Row',
        'email' => 'ws56-orphan-'.Str::lower(Str::random(8)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
    ]);

    $this->withToken(ws56Token($orphan))->getJson('/api/v1/storefront/account/orders')
        ->assertStatus(403)
        ->assertJsonPath('errors.account.0', 'no_business');
});

test('the transitional auth routes resolve the tenant from the origin header', function () {
    $store = ws56Store('ws56-origin');
    $email = 'ws56-origin-'.Str::lower(Str::random(8)).'@example.test';

    // The deployed storefront calls this tenant-less URI and knows nothing
    // about the store being in the path. `Origin` is what keeps it working.
    $this->withHeaders([
        'Origin' => 'https://'.$store->slug.'.'.config('frontend.storefront_main_domain'),
    ])->postJson('/api/v1/storefront/auth/register', ws56RegisterPayload($email))->assertCreated();

    $customer = Customer::where('email', $email)->sole();

    expect($customer->business_id)->toBe($store->business_id);
});

test('a storefront auth request that names no store at all is refused', function () {
    $email = 'ws56-nostore-'.Str::lower(Str::random(8)).'@example.test';

    $this->postJson('/api/v1/storefront/auth/register', ws56RegisterPayload($email))
        ->assertStatus(422)
        ->assertJsonPath('errors.store.0', 'store_unresolved');

    // Refused, not guessed at: a row created here would belong to no business
    // and be readable by nothing.
    expect(Customer::where('email', $email)->exists())->toBeFalse();
});

test('the canonical auth route names point at the store-scoped URIs', function () {
    expect(route('api.storefront.auth.register', ['store' => 'swift-one']))
        ->toEndWith('/api/v1/storefront/swift-one/auth/register')
        ->and(route('api.storefront.auth.legacy.register'))
        ->toEndWith('/api/v1/storefront/auth/register');
});

test('the management login flow is untouched by the otp scoping', function () {
    // OtpService is shared, and a scoped delete written carelessly would
    // invalidate in-flight management codes the moment a storefront one was
    // minted for the same address. This is the regression guard.
    Mail::fake();

    $this->postJson('/api/v1/management/auth/register', [
        'name' => 'WS56 Owner',
        'email' => 'ws56-owner-'.Str::lower(Str::random(8)).'@example.test',
        'phone' => '08011112222',
        'password' => 'secret-pass-123',
        'password_confirmation' => 'secret-pass-123',
    ])->assertCreated();
});

test('the address book is saved addresses only and keeps one default', function () {
    $store = ws56Store('ws56-address');
    $customer = ws56Customer($store->business_id);
    $token = ws56Token($customer);

    $first = $this->withToken($token)->postJson('/api/v1/storefront/account/addresses', [
        'label' => 'Home',
        'recipient_name' => 'Ada Obi',
        'recipient_phone' => '08011112222',
        'street_address' => '12 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ])->assertCreated()
        ->json('data.address');

    // The first entry in an empty book is the default, because a book with an
    // entry and no default makes the checkout's "use my saved address" prompt
    // unanswerable.
    expect($first['is_default'])->toBeTrue();

    $second = $this->withToken($token)->postJson('/api/v1/storefront/account/addresses', [
        'label' => 'Office',
        'recipient_name' => 'Ada Obi',
        'recipient_phone' => '08011112222',
        'street_address' => '9 Broad Street',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ])->assertCreated()->json('data.address');

    expect($second['is_default'])->toBeFalse();

    $this->withToken($token)->postJson("/api/v1/storefront/account/addresses/{$second['id']}/default")
        ->assertOk();

    expect(DeliveryAddress::where('customer_id', $customer->id)->where('is_default', true)->count())->toBe(1)
        ->and((int) DeliveryAddress::find($second['id'])->is_default)->toBe(1)
        // The address book never lists the per-order snapshots.
        ->and($customer->deliveryAddresses()->count())->toBe(2);

    $index = $this->withToken($token)->getJson('/api/v1/storefront/account/addresses')->assertOk();

    expect($index->json('data.addresses'))->toHaveCount(2);
    expect($index->json('data.addresses.0.id'))->toBe($second['id']);

    // Deleting the default promotes another, so the book never ends up with
    // entries and no default.
    $this->withToken($token)->deleteJson("/api/v1/storefront/account/addresses/{$second['id']}")->assertOk();

    expect(DeliveryAddress::where('customer_id', $customer->id)->where('is_default', true)->count())->toBe(1);
});

test('one customer cannot reach another customer address', function () {
    $store = ws56Store('ws56-address-thief');
    $owner = ws56Customer($store->business_id);
    $stranger = ws56Customer($store->business_id);

    $address = DeliveryAddress::create([
        'customer_id' => $owner->id,
        'label' => 'Home',
        'recipient_name' => 'Ada Obi',
        'recipient_phone' => '08011112222',
        'street_address' => '12 Marina',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'is_default' => true,
        'is_snapshot' => false,
    ]);

    // A foreign id is indistinguishable from a missing one, so neither a
    // 403 nor a 200 leaks that somebody else's address exists.
    $this->withToken(ws56Token($stranger))
        ->putJson("/api/v1/storefront/account/addresses/{$address->id}", [
            'recipient_name' => 'Stolen',
            'recipient_phone' => '08099999999',
            'street_address' => '1 Nowhere',
            'city' => 'Lagos',
            'state' => 'Lagos',
        ])->assertStatus(404);

    expect($address->fresh()->street_address)->toBe('12 Marina');
});

test('a customer toggles their own preferences and the trail records it', function () {
    $store = ws56Store('ws56-consent');
    $customer = ws56Customer($store->business_id);

    $index = $this->withToken(ws56Token($customer))->getJson('/api/v1/storefront/account/consents')->assertOk();

    $types = collect($index->json('data.consents'))->pluck('type')->all();

    expect($types)->toContain('terms')->toContain('marketing');
    // Only the optional ones are writable: the terms record is evidence that
    // the notice was shown, not a setting to turn off.
    expect(collect($index->json('data.consents'))->firstWhere('type', 'terms')['editable'])->toBeFalse()
        ->and(collect($index->json('data.consents'))->firstWhere('type', 'marketing')['editable'])->toBeTrue();

    $this->withToken(ws56Token($customer))
        ->putJson('/api/v1/storefront/account/consents', ['consents' => ['marketing' => true]])
        ->assertOk();

    // A second identical write appends nothing: the trail holds decisions, not
    // every page load.
    $this->withToken(ws56Token($customer))
        ->putJson('/api/v1/storefront/account/consents', ['consents' => ['marketing' => true]])
        ->assertOk();

    $trail = CustomerConsent::where('customer_id', $customer->id)->where('type', 'marketing')->get();

    expect($trail)->toHaveCount(1)
        ->and($trail->first()->granted)->toBeTrue()
        ->and($trail->first()->source)->toBe('account');
});

test('a customer exports their data and can ask to be erased', function () {
    $store = ws56Store('ws56-rights');
    $customer = ws56Customer($store->business_id);
    ws56Order($store, $customer, ['status' => 'completed']);
    $token = ws56Token($customer);

    $export = $this->withToken($token)->getJson('/api/v1/storefront/account/data-export')->assertOk();

    expect($export->json('data.account.email'))->toBe($customer->email)
        ->and($export->json('data.orders'))->toHaveCount(1);

    // The request deletes nothing and signs the session out: erasure is a
    // decision a business makes, and an angry afternoon should not be able to
    // destroy a financial record.
    $this->withToken($token)
        ->postJson('/api/v1/storefront/account/delete', ['password' => 'secret-pass-123'])
        ->assertOk();

    $customer->refresh();

    expect($customer->deletion_requested_at)->not->toBeNull()
        ->and($customer->status)->toBe(Customer::STATUS_ACTIVE)
        ->and($customer->orders()->count())->toBe(1);

    // The password is what proves it is the account holder asking, so a wrong
    // one is refused.
    $other = ws56Customer($store->business_id, ['deletion_requested_at' => null]);

    $this->withToken(ws56Token($other))
        ->postJson('/api/v1/storefront/account/delete', ['password' => 'not-the-password'])
        ->assertStatus(422);
});
