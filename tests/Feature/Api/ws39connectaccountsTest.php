<?php

use App\Models\Business;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use App\Services\Payments\Connect\BachsConnectAccountService;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\ExchangeRateService;
use App\Support\Payments\PaymentGatewayRegistry;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-39 — creating a business's account at Bachs, and registering its bank.
|
| This is the first half of the managed model: the business that has no gateway
| of its own registers a **payout account**, and Storify opens a connected
| account for it. Three things are decided here and are immutable at the
| provider — who collects the fees, what the business *is*, and the account
| itself — so they are the things asserted.
|
| The bank account number is deliberately absent from everything we keep. It
| goes to Bachs once and is not written down on our side; what comes back is an
| id and the last four digits.
*/

function ca39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ca39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function ca39PlatformKeys(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function ca39Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * The bank a business types into the payout form.
 *
 * @return array{account_number: string, account_name: string, bank_code: string, bank_name: string}
 */
function ca39Bank(): array
{
    return [
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'bank_code' => '058',
        'bank_name' => 'Guaranty Trust Bank',
    ];
}

/**
 * Bachs answering every call the account lifecycle makes.
 *
 * The account read is the one that matters: `capabilities` and `requirements`
 * are only populated by `GET /v1/accounts/{id}`, never by the creation
 * response, so a fixture that returned them on creation would pass against an
 * implementation that never looked.
 *
 * @param  array<string, mixed>  $account
 */
function ca39FakeBachs(string $accountId = 'acct_ca39', array $account = []): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($accountId, $account) {
        $url = $request->url();

        if (str_contains($url, '/payouts/destinations')) {
            return Http::response([
                'id' => 'pd_ca39',
                'is_usable' => true,
                'status' => 'active',
                'currency' => 'NGN',
                'bank_name' => 'Guaranty Trust Bank',
                'account_name' => 'Ada Trading Ltd',
            ]);
        }

        if ($request->method() === 'GET') {
            return Http::response(array_merge([
                'id' => $accountId,
                'status' => 'active',
                'capabilities' => [
                    'card_collection' => ['status' => 'active'],
                    'ngn_card_collection' => ['status' => 'active'],
                    'bank_transfer' => ['status' => 'active'],
                    'payouts' => ['status' => 'active'],
                ],
                'requirements' => [],
            ], $account));
        }

        return Http::response(['id' => $accountId, 'status' => 'created']);
    });
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('provisioning asks for the collection capabilities and names Bachs as the fee collector', function () {
    // `collector` is the one line the money model turns on and it cannot be
    // changed afterwards: `bachs` makes the business's account settle net of
    // the processing fee, so Storify pays nothing per sale. The alternative
    // would bill every business's fees to our own balance for good.
    ca39PlatformKeys();
    ca39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->update(['name' => 'Ada Trading Ltd']);

    $result = app(BachsConnectAccountService::class)
        ->provision($business->fresh(), null, ca39Bank(), BachsConnectAccountService::ENTITY_COMPANY);

    expect($result->success)->toBeTrue()
        ->and($result->accountId)->toBe('acct_ca39');

    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent) {
        if (! str_ends_with($request->url(), '/accounts') || $request->method() !== 'POST') {
            return false;
        }

        $sent = $request->data();

        return true;
    });

    expect($sent['contact_email'])->toBe($owner->email)
        ->and($sent['entity_type'])->toBe('company')
        ->and($sent['country'])->toBe('NG')
        ->and($sent['responsibilities']['fees']['collector'])->toBe('bachs')
        // Asking for one list and gating on another is how an account ends up
        // approved and still unable to charge, so this is the registry's list.
        ->and(array_keys($sent['configuration']['merchant']['capabilities']))
        ->toBe(PaymentGatewayRegistry::managedCapabilitiesFor('bachs'))
        ->and($sent['configuration']['merchant']['capabilities']['card_collection'])->toBe(['requested' => true])
        // Without payouts the account can collect and can never withdraw.
        ->and($sent['configuration']['recipient']['capabilities']['payouts'])->toBe(['requested' => true]);
});

test('the bank is registered in the provider\'s own shape, and the account number is not kept', function () {
    ca39PlatformKeys();
    ca39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $result = app(BachsConnectAccountService::class)
        ->provision($business, null, ca39Bank(), BachsConnectAccountService::ENTITY_INDIVIDUAL);

    expect($result->success)->toBeTrue();

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/payouts/destinations')) {
            return false;
        }

        // Addressed as the business, not as us: without the header this
        // registers a bank against the *platform's* account.
        return $request->hasHeader('X-Account-Id', 'acct_ca39')
            && $request->data() === [
                'currency' => 'NGN',
                'type' => 'bank_account',
                'account_number' => '0123456789',
                'account_name' => 'Ada Trading Ltd',
                'bank_code' => '058',
            ];
    });

    // What we keep: an id, a name and four digits. A full account number is the
    // business's to hold, and one more copy of it is one more thing to leak.
    expect($result->destination['id'])->toBe('pd_ca39')
        ->and($result->destination['last4'])->toBe('6789')
        ->and($result->destination)->not->toHaveKey('account_number')
        ->and($result->account->canCharge('bachs'))->toBeTrue()
        ->and($result->account->canBePaidOut())->toBeTrue();
});

test('a refusal from the provider names the fields it objected to, never their values', function () {
    // A 422 names the offending fields, and the owner is the only one who can
    // fix them — so the sentence lists the fields. It never echoes the values,
    // because one of them could be an account number.
    ca39PlatformKeys();

    Http::swap(new Factory);
    Http::fake([
        '*/accounts' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['entity_type' => ['invalid'], 'country' => ['unsupported']],
        ], 422),
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $result = app(BachsConnectAccountService::class)
        ->provision($business, null, ca39Bank(), BachsConnectAccountService::ENTITY_INDIVIDUAL);

    expect($result->success)->toBeFalse()
        ->and($result->accountId)->toBeNull()
        ->and($result->message)->toContain('entity_type')
        ->and($result->message)->toContain('country')
        ->and($result->message)->not->toContain('invalid');
});

test('a requirement the provider is still reviewing is not shown as work to do', function () {
    // `resolution: review` means the paperwork is already with Bachs. Listing it
    // as an outstanding task sends a business owner looking for something to do
    // that does not exist.
    ca39PlatformKeys();
    ca39FakeBachs(account: [
        'requirements' => [
            ['field' => 'business_registration_number', 'resolution' => 'review', 'deadline' => '2026-11-01'],
        ],
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $result = app(BachsConnectAccountService::class)->provision($business, null, ca39Bank(), 'company');

    expect($result->success)->toBeTrue()
        ->and($result->account->outstandingRequirements()['actionable'])->toBe([])
        ->and($result->account->outstandingRequirements()['with_provider'])->toHaveCount(1)
        ->and($result->account->hasOutstandingRequirements())->toBeFalse();
});

test('a requirement we cannot satisfy blocks charging and is surfaced to the owner', function () {
    ca39PlatformKeys();
    ca39FakeBachs(account: [
        'requirements' => [
            ['field' => 'business_registration_number', 'resolution' => 'api', 'deadline' => '2026-11-01'],
        ],
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $result = app(BachsConnectAccountService::class)->provision($business, null, ca39Bank(), 'company');

    expect($result->account->hasOutstandingRequirements())->toBeTrue()
        ->and($result->account->outstandingRequirements()['actionable'])->toHaveCount(1);
});

test('a business with no grant registers a payout account and never a gateway', function () {
    // The screen for the default case. `registerPayoutAccount` is reachable by
    // every business, because it is the path for the business that has no
    // gateway of its own — the grant gates the *other* path.
    ca39PlatformKeys();
    ca39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    expect((bool) $business->can_connect_own_gateway)->toBeFalse();

    $response = $this->withToken(ca39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'entity_type' => 'company',
            'terms_accepted' => true,
            'account_number' => '0123456789',
            'account_name' => 'Ada Trading Ltd',
            'bank_code' => '058',
            'bank_name' => 'Guaranty Trust Bank',
        ])
        ->assertOk()
        ->assertJsonPath('data.success', true)
        ->assertJsonPath('data.account_id', 'acct_ca39');

    // The row the resolver reads: an account id, no credentials, and the state
    // the provider reported.
    $row = DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->where('payment_method_id', DB::table('payment_methods')->where('code', 'bachs')->value('id'))
        ->first();

    expect($row->gateway_account_id)->toBe('acct_ca39')
        ->and($row->is_active)->toBeTruthy()
        ->and(json_decode((string) $row->config, true)['managed']['status'])->toBe('active');

    // And the screen is told which regime is in force without having to infer it.
    $bachs = collect($response->json('data.providers'))->firstWhere('code', 'bachs');

    expect($bachs['payment_mode'])->toBe('platform_managed')
        ->and($bachs['can_connect_own_gateway'])->toBeFalse()
        ->and($bachs['managed']['account_id'])->toBe('acct_ca39')
        // Masked: enough to recognise the account, never enough to use it.
        ->and($bachs['managed']['payout_destination']['last4'])->toBe('6789')
        ->and($bachs['webhook_url'])->toBeNull();
});

test('choosing one of the business\'s own accounts names it as the payout bank', function () {
    // The shape the form sends now: the account is saved — and name-verified
    // through Paystack — by the bank-transfer screen, and this screen points at
    // it by id rather than retyping a number that has already been checked. That
    // id is what gets recorded as the business's payout bank, and it is the only
    // thing the business is ever asked for: what the provider then builds out of
    // it is Storify's, and is not something it holds a reference to.
    ca39PlatformKeys();
    ca39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(ca39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'entity_type' => 'company',
            'terms_accepted' => true,
        ])
        ->assertOk();

    expect((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id)
        ->and($business->payout_entity_type)->toBe('company');
});

test('a business already holding an account registers its bank against that one', function () {
    // Accounts cannot be deleted and their fee collector cannot change, so a
    // business changing its bank must land on the account it has. Creating a
    // second one would leave two real accounts holding the same identity.
    ca39PlatformKeys();
    ca39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ca39Token($owner))->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
        'entity_type' => 'company',
        'terms_accepted' => true,
        ...ca39Bank(),
    ])->assertOk();

    // A fresh recorder, so "no second account was created" is asserted against
    // the second submission alone rather than against both.
    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['id' => 'pd_ca39_second', 'is_usable' => true])]);

    $this->withToken(ca39Token($owner))->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
        'entity_type' => 'company',
        'terms_accepted' => true,
        'account_number' => '9876543210',
        'account_name' => 'Ada Trading Ltd',
        'bank_code' => '058',
    ])->assertOk();

    // No second account creation, and the destination call still addresses the
    // account in the header.
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/accounts') && $request->method() === 'POST');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/payouts/destinations')
        && $request->hasHeader('X-Account-Id', 'acct_ca39'));

    expect(DB::table('business_payment_method')->where('business_id', $business->id)->value('gateway_account_id'))
        ->toBe('acct_ca39');
});

test('a store can be given its own payout account', function () {
    // Per-store individuality, in the one place it survives: the money is no
    // longer pooled at all, so a store that wants its own payouts needs its own
    // account — reached by naming the store on the same request.
    ca39PlatformKeys();
    ca39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ca39Store($owner);

    $this->withToken(ca39Token($owner))->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
        'store_id' => $store->id,
        'entity_type' => 'company',
        'terms_accepted' => true,
        ...ca39Bank(),
    ])->assertOk();

    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->value('gateway_account_id'))->toBe('acct_ca39');

    ExchangeRateService::forget();

    $connection = app(PaymentGatewayResolver::class)->connection((int) $business->id, 'bachs', (int) $store->id);

    expect($connection->credentials->accountId())->toBe('acct_ca39')
        ->and($connection->source)->toBe('store');
});

test('a provider that cannot process payments on our behalf is refused rather than half-registered', function () {
    // `flutterwave` rather than `paystack`, which this test named while Bachs
    // was the only provider the platform could provision. Paystack now has a
    // managed path of its own — a subaccount — so it is exactly the wrong
    // example: the refusal below is about a provider *without* one, and picking
    // a managed provider here would assert nothing.
    ca39PlatformKeys();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    Http::swap(new Factory);
    Http::fake();

    $this->withToken(ca39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/flutterwave/payout-account', [
            'entity_type' => 'company',
            'terms_accepted' => true,
            ...ca39Bank(),
        ])
        ->assertStatus(422);

    Http::assertNothingSent();
});
