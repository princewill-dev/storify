<?php

use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-40 — the bank account a business is paid into.
|
| The business is asked one question — which of its own bank accounts its sales
| should reach — and `businesses.payout_bank_id` is the answer. Everything
| between that account and the customer is Storify's: a subaccount, a connected
| account, a settlement schedule. The business never sees any of it, never
| maintains it, and is never asked about it.
|
| That makes this column the thing the whole gate keys on. The forced step reads
| it, the resolver's reasons are chosen by it, and the admin's list of who cannot
| sell is explained by it. So what is asserted here is narrow and load-bearing:
| the pointer is written exactly when a destination was registered, it is written
| for the business and not for a store, and it is never written from a bank
| account belonging to somebody else.
*/

function pb40Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'pb40-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function pb40PlatformKeys(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function pb40Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * Bachs answering the registration, with the payout schedule under the test's
 * control.
 *
 * The schedule is the one call that can be refused without the registration
 * being a failure — the account exists and can be charged either way — so it is
 * the one worth being able to break on purpose.
 */
function pb40FakeBachs(string $accountId = 'acct_pb40', ?int $settlementStatus = null): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($accountId, $settlementStatus) {
        $url = $request->url();

        if (str_contains($url, '/balance_settings')) {
            return $settlementStatus === null
                ? Http::response(['status' => 'ok'])
                : Http::response(
                    ['message' => 'This account cannot be paid out automatically right now.'],
                    $settlementStatus,
                );
        }

        if (str_contains($url, '/payouts/destinations')) {
            return Http::response([
                'id' => 'pd_pb40',
                'is_usable' => true,
                'status' => 'active',
                'currency' => 'NGN',
                'bank_name' => 'Guaranty Trust Bank',
                'account_name' => 'Ada Trading Ltd',
                'last4' => '6789',
            ]);
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'id' => $accountId,
                'status' => 'active',
                'capabilities' => [
                    'card_collection' => ['status' => 'active'],
                    'ngn_card_collection' => ['status' => 'active'],
                    'bank_transfer' => ['status' => 'active'],
                    'payouts' => ['status' => 'active'],
                ],
                'requirements' => [],
            ]);
        }

        return Http::response(['id' => $accountId, 'status' => 'created']);
    });
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('naming a bank account records it on the business, with the entity type', function () {
    pb40PlatformKeys();
    pb40FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    expect($business->payout_bank_id)->toBeNull();

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'entity_type' => 'individual',
            'terms_accepted' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.success', true);

    $business->refresh();

    // The pointer and the kind of business, and nothing else — the account at
    // the provider is not something the business holds a reference to.
    expect((int) $business->payout_bank_id)->toBe((int) $bank->id)
        ->and($business->payout_entity_type)->toBe('individual');
});

test('the account number reaches the provider from our own row, never from the request', function () {
    // The id is not trusted to carry details: the request is scoped so one
    // business cannot name another's account, and then the *stored* number is
    // what goes out. A number posted alongside the id would be a second copy of
    // a verified fact, and therefore a second chance to get a digit wrong.
    pb40PlatformKeys();
    pb40FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create([
        'business_id' => $business->id,
        'account_number' => '0123456789',
        'bank_code' => '058',
    ]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'account_number' => '9999999999',
            'account_name' => 'Somebody Else',
            'bank_code' => '999',
            'entity_type' => 'company',
            'terms_accepted' => true,
        ])
        ->assertOk();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/payouts/destinations')
        && $request['account_number'] === '0123456789'
        && $request['bank_code'] === '058');
});

test('a bank account belonging to another business is refused', function () {
    pb40PlatformKeys();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $foreign = StoreBank::factory()->create(['business_id' => $otherBusiness->id]);

    Http::swap(new Factory);
    Http::fake();

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $foreign->id,
            'entity_type' => 'company',
            'terms_accepted' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_bank_id');

    // Refused before anything was asked of the provider, which is the point of
    // validating it here rather than discovering it at the bank.
    Http::assertNothingSent();
});

test('a refused payout schedule leaves the business registered anyway', function () {
    // The account exists, it can be charged, and the money is direct either way.
    // What failed is the automatic schedule, which is a conversation rather than
    // a reason to unwind a real account — and, for the column asserted here, a
    // reason the business must not be sent back round the form for. A business
    // made to name the same bank again because a cron-shaped call was refused
    // would be answering a question it has already answered.
    pb40PlatformKeys();
    pb40FakeBachs(settlementStatus: 422);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'entity_type' => 'company',
            'terms_accepted' => true,
        ])
        ->assertStatus(422);

    $business->refresh();

    expect((int) $business->payout_bank_id)->toBe((int) $bank->id)
        ->and($business->payout_entity_type)->toBe('company');

    // And the account is still on file at the provider, so the refusal was not a
    // rollback of anything that had actually happened.
    expect(DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->value('gateway_account_id'))->toBe('acct_pb40');
});

test('a refused destination records nothing, because there was nothing to point at', function () {
    // The other half of the same rule. A destination the provider would not take
    // means no destination was registered, so naming a bank here would record a
    // fact that is not true — and the forced step would then stop asking for the
    // one thing the business still has to do.
    pb40PlatformKeys();

    Http::swap(new Factory);
    Http::fake([
        'sandbox-api.bachs.io/*' => Http::response(['message' => 'That account cannot receive payouts.'], 422),
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'entity_type' => 'company',
            'terms_accepted' => true,
        ])
        ->assertStatus(422);

    expect($business->refresh()->payout_bank_id)->toBeNull();
});

test('a store banking separately does not name the whole business\'s account', function () {
    // A store may be paid separately, and a store's registration says where
    // *that store's* money goes. Writing it on the business as well would name
    // one store's bank as everyone's destination and quietly redirect the
    // settlements of the stores that did not ask for it.
    pb40PlatformKeys();
    pb40FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = pb40Store($owner);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_id' => $store->id,
            'store_bank_id' => $bank->id,
            'entity_type' => 'company',
            'terms_accepted' => true,
        ])
        ->assertOk();

    expect(DB::table('store_payment_method')->where('store_id', $store->id)->value('gateway_account_id'))
        ->toBe('acct_pb40')
        ->and($business->refresh()->payout_bank_id)->toBeNull();
});

test('typed account details leave the pointer alone rather than inventing one', function () {
    // Today's screen saves the account and then points at it, so this is the
    // exception. It is still reachable, and the honest answer is to write no
    // pointer rather than to create a `store_banks` row out of arguments this
    // service is not responsible for. The cost is one extra confirmation on the
    // business's next visit — and because the account at the provider already
    // exists, confirming re-registers that same account rather than opening a
    // second one.
    pb40PlatformKeys();
    pb40FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'account_number' => '0123456789',
            'account_name' => 'Ada Trading Ltd',
            'bank_code' => '058',
            'bank_name' => 'Guaranty Trust Bank',
            'entity_type' => 'company',
            'terms_accepted' => true,
        ])
        ->assertOk();

    expect($business->refresh()->payout_bank_id)->toBeNull()
        ->and(DB::table('store_banks')->where('business_id', $business->id)->count())->toBe(0);
});
