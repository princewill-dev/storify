<?php

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\Connect\ConnectRequirementService;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-39 — answering the provider's questions, and switching Storify payments off.
|
| Two halves of the same idea: a business that takes payments through Storify
| should never be asked to retype what we already know, and should always be able
| to stop.
|
| The provider asks a connected account for fields and marks each with a
| `resolution` saying who can supply it. `api` is ours to submit from what the
| business already gave us when it registered and when it completed KYC. This
| file is the assertion that we submit exactly that, and nothing else: no
| invented values, no acceptance of terms on anybody's behalf, and no guess about
| which of the provider's people our records describe.
|
| The second half is the switch. `activation` stops new charges without
| disconnecting the payout account, because the account is where the business's
| money is and switching off should not cost them it.
*/

function rq39Keys(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

function rq39Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * The connected account row, with the acceptance the owner gave on our form.
 *
 * `tos_accepted_at` is stored ISO because that is what a person reads; the
 * provider's field is a Unix timestamp, and the translation is the thing the
 * terms test is about.
 *
 * @param  array<string, mixed>  $managed
 */
function rq39Managed(Business $business, array $managed = [], ?Store $store = null): void
{
    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();

    $attributes = $store
        ? ['store_id' => $store->id, 'payment_method_id' => $method->id]
        : ['business_id' => $business->id, 'payment_method_id' => $method->id];

    DB::table($store ? 'store_payment_method' : 'business_payment_method')->updateOrInsert(
        $attributes,
        [
            'business_id' => $business->id,
            'is_active' => true,
            'gateway_account_id' => 'acct_rq39',
            'config' => json_encode(['managed' => array_merge([
                'status' => 'active',
                'capabilities' => ['card_collection' => 'active', 'payouts' => 'active'],
            ], $managed)]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/**
 * The provider, answering with whatever requirements the test asks about.
 *
 * @param  array<int, array<string, mixed>>  $requirements
 */
function rq39FakeBachs(array $requirements = [], ?int $refusePost = null): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($requirements, $refusePost) {
        if ($request->method() === 'POST' && $refusePost !== null) {
            return Http::response([
                'code' => 'INVALID_REQUIREMENT_FIELD',
                'errors' => ['company.registered_name' => ['is invalid']],
            ], $refusePost);
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'id' => 'acct_rq39',
                'status' => 'active',
                'capabilities' => ['card_collection' => ['status' => 'active']],
                'requirements' => $requirements,
            ]);
        }

        return Http::response(['id' => 'acct_rq39', 'status' => 'active']);
    });
}

/** The `fields` map that reached the account endpoint, if any. */
function rq39Submitted(): ?array
{
    $found = null;

    Http::recorded(function (Request $request) use (&$found) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/accounts/acct_rq39')) {
            $found = $request->data();
        }

        return false;
    });

    return $found;
}

/** The body that reached a person endpoint, if any. */
function rq39PersonSubmitted(string $personId): ?array
{
    $found = null;

    Http::recorded(function (Request $request) use ($personId, &$found) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/persons/'.$personId)) {
            $found = $request->data();
        }

        return false;
    });

    return $found;
}

/** @return array<int, array<string, mixed>> */
function rq39Requirements(): array
{
    return [
        ['field' => 'company.registered_name', 'resolution' => 'api', 'status' => 'currently_due'],
        ['field' => 'business_profile.support_email', 'resolution' => 'api', 'status' => 'currently_due'],
        // Already with the provider. Submitting it again achieves nothing and
        // listing it as work to do sends the owner looking for a task.
        ['field' => 'business_registration_number', 'resolution' => 'review', 'status' => 'currently_due'],
        // Under verification. Re-sending resets a check that is already running.
        ['field' => 'person.name', 'resolution' => 'api', 'status' => 'pending_verification'],
        // Ours in principle and with no mapping to anything we hold.
        ['field' => 'external_account.token', 'resolution' => 'api', 'status' => 'currently_due'],
    ];
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('what we already know is submitted, and what we do not is reported rather than invented', function () {
    // The whole point of the platform: the owner typed their legal name and
    // their email once, at registration and at KYC, and should not be made to
    // type them again into a payment provider's form.
    rq39Keys();
    rq39FakeBachs(rq39Requirements());

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->update(['name' => 'Ada Trading Ltd']);
    rq39Managed($business);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh());

    expect($result->success)->toBeTrue();

    $fields = rq39Submitted()['fields'] ?? [];

    expect($fields['company.registered_name'])->toBe('Ada Trading Ltd')
        ->and($fields['business_profile.support_email'])->toBe($owner->email);

    // The three that must not have been sent, each for its own reason.
    expect($fields)->not->toHaveKey('business_registration_number')
        ->and($fields)->not->toHaveKey('person.name')
        ->and($fields)->not->toHaveKey('external_account.token');

    // And the report says which is which, because "nothing happened" and
    // "the provider is checking it" are different things to be told.
    expect($result->submitted)->toContain('company.registered_name')
        ->and($result->withProvider)->toContain('business_registration_number')
        ->and($result->waiting)->toContain('person.name')
        ->and($result->unresolvable)->toHaveKey('external_account.token')
        ->and($result->unresolvable['external_account.token'])->toBeString()->not->toBe('');
});

test('a requirement we hold no answer for is never filled with the nearest plausible value', function () {
    // The failure this guards against is subtle and expensive: an identity
    // field the provider treats as verified, filled with something that looks
    // right. `company.registration_number` has a real source (the KYC payload),
    // so with no KYC application it is simply absent.
    rq39Keys();
    rq39FakeBachs([
        ['field' => 'company.registration_number', 'resolution' => 'api', 'status' => 'currently_due'],
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    rq39Managed($business);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh());

    expect($result->submitted)->toBe([])
        ->and($result->unresolvable)->toHaveKey('company.registration_number')
        // Nothing was posted at all — not an empty `fields` map, which the
        // provider would have rejected.
        ->and(rq39Submitted())->toBeNull();
});

test("a person's details go to their own endpoint, and only when there is one person", function () {
    // Two people on the provider's account and one KYC application on ours.
    // There is nothing in a `persons.per_…` key that says which of theirs is
    // ours, so submitting would attach one person's documents to another's
    // record. Reported instead.
    rq39Keys();
    rq39FakeBachs([
        ['field' => 'persons.per_one.first_name', 'resolution' => 'api', 'status' => 'currently_due'],
        ['field' => 'persons.per_two.first_name', 'resolution' => 'api', 'status' => 'currently_due'],
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    rq39Managed($business);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh());

    expect($result->unresolvable)->toHaveKey('persons.per_one.first_name')
        ->and($result->unresolvable['persons.per_one.first_name'])->toContain('more than one person')
        ->and($result->unresolvable)->toHaveKey('persons.per_two.first_name')
        ->and(rq39PersonSubmitted('per_one'))->toBeNull()
        ->and(rq39PersonSubmitted('per_two'))->toBeNull();
});

test('one person on the account is unambiguous, and their fields are submitted there', function () {
    rq39Keys();
    rq39FakeBachs([
        ['field' => 'persons.per_one.first_name', 'resolution' => 'api', 'status' => 'currently_due'],
    ]);

    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'name' => 'Ada Okafor',
    ]);
    rq39Managed($business);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh());

    // The person endpoint, not the account one: the provider holds these two
    // apart and sending them to the wrong one is a rejection with no clue in it.
    expect(rq39PersonSubmitted('per_one'))->toBe(['first_name' => 'Ada'])
        ->and($result->submitted)->toContain('persons.per_one.first_name')
        // And nothing went to the account endpoint as a side effect.
        ->and(rq39Submitted())->toBeNull();
});

test('the terms are submitted as the moment the owner accepted them, translated to the provider\'s own format', function () {
    // `tos_acceptance` is a contractual act the owner performed on our form at a
    // known time. The provider's field is a Unix timestamp and ours is stored
    // ISO, so exactly one translation happens, here, once — rather than being
    // discovered per business as a rejected submission.
    rq39Keys();
    rq39FakeBachs([
        ['field' => 'tos_acceptance.date', 'resolution' => 'api', 'status' => 'currently_due'],
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $acceptedAt = '2026-09-01T09:30:00+00:00';

    rq39Managed($business, [
        'tos_accepted_at' => $acceptedAt,
        'tos_accepted_ip' => '203.0.113.7',
        'tos_accepted_user_agent' => 'Mozilla/5.0',
    ]);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh());
    $fields = rq39Submitted()['fields'] ?? [];

    expect($fields['tos_acceptance.date'])->toBe(strtotime($acceptedAt))
        ->and($fields['tos_acceptance.date'])->toBeInt()
        // The stored record is left readable — only the submission is translated.
        ->and(DB::table('business_payment_method')->where('business_id', $business->id)->value('config'))
        ->toContain($acceptedAt)
        ->and($result->submitted)->toContain('tos_acceptance.date');
});

test('with no acceptance on record the terms requirement stays outstanding rather than being invented', function () {
    // The provider asks for a date nobody on our side gave. The only honest
    // answer is to leave it visible for a person to deal with.
    rq39Keys();
    rq39FakeBachs([
        ['field' => 'tos_acceptance.date', 'resolution' => 'api', 'status' => 'currently_due'],
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    rq39Managed($business);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh());

    expect($result->unresolvable)->toHaveKey('tos_acceptance.date')
        ->and(rq39Submitted())->toBeNull();
});

test('a dry run reports what it would send and sends nothing', function () {
    // The operator's look-before-you-leap: a run that reaches the provider only
    // to read.
    rq39Keys();
    rq39FakeBachs(rq39Requirements());

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    rq39Managed($business);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh(), dryRun: true);

    expect($result->success)->toBeTrue()
        ->and($result->dryRun)->toBeTrue()
        ->and($result->submitted)->toContain('company.registered_name')
        ->and(rq39Submitted())->toBeNull();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('a value the provider refuses fails the pass and never echoes what was sent', function () {
    // The refusal is logged with the field names and reported in words. The
    // values are identity data and an error message is not the place for them.
    rq39Keys();
    rq39FakeBachs([
        ['field' => 'company.registered_name', 'resolution' => 'api', 'status' => 'currently_due'],
    ], refusePost: 400);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $business->update(['name' => 'Ada Trading Ltd']);
    rq39Managed($business);

    $result = app(ConnectRequirementService::class)->submit('acct_rq39', $business->fresh());

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBeString()
        ->and($result->message)->not->toContain('Ada Trading Ltd')
        ->and($result->account)->toBeNull();
});

test('the scheduled pass reports every account it looked at, and can be run as a dry run', function () {
    // What an operator runs when a business asks why they are still being
    // chased for something.
    rq39Keys();
    rq39FakeBachs(rq39Requirements());

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    rq39Managed($business);

    $this->artisan('connect:requirements', ['--dry-run' => true])
        ->expectsOutputToContain('acct_rq39')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('a business can stop taking payments without giving up its payout account', function () {
    // The gap: a managed business could register an account and then had no way
    // to stop charging customers. Disconnecting was refused because the row
    // holds an account, and connecting is grant-gated — so the off switch did
    // not exist.
    rq39Keys();
    rq39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    rq39Managed($business);

    $response = $this->withToken(rq39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $row = DB::table('business_payment_method')->where('business_id', $business->id)->first();

    expect((bool) $row->is_active)->toBeFalse()
        // The account is untouched. It is where their money is, and switching
        // off must not cost them it.
        ->and($row->gateway_account_id)->toBe('acct_rq39');

    $bachs = collect($response->json('data.providers'))->firstWhere('code', 'bachs');

    expect($bachs['is_paused'])->toBeTrue()
        ->and($bachs['is_enabled'])->toBeFalse()
        // A named reason, distinct from "never connected" and from "an admin
        // switched it off", so the screen can offer the switch back.
        ->and($bachs['unavailable_reason'])->toContain('switched Storify payments off');

    // And the screen is still told the account and the money route survive it.
    expect($bachs['managed']['account_id'])->toBe('acct_rq39');

    // Switching back on. The flag lives on the provider's own state, which is
    // what the screen renders the switch from.
    $this->withToken(rq39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.providers', fn (array $providers) => ($bachs = collect($providers)
            ->firstWhere('code', 'bachs'))['is_paused'] === false && $bachs['is_enabled'] === true);

    expect(ActivityLog::where('action', 'payment_gateway.paused')->exists())->toBeTrue()
        ->and(ActivityLog::where('action', 'payment_gateway.resumed')->exists())->toBeTrue();
});

test('a store can stop on its own without stopping the business', function () {
    // Stores bank separately here, so one closing for a refit is not the
    // business closing.
    rq39Keys();
    rq39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Ikeja Mall',
        'slug' => 'rq39-ikeja',
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    rq39Managed($business);
    rq39Managed($business, store: $store);

    $this->withToken(rq39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', [
            'store_id' => $store->id,
            'is_active' => false,
        ])
        ->assertOk();

    expect((bool) DB::table('store_payment_method')->where('store_id', $store->id)->value('is_active'))->toBeFalse()
        // The business's own switch is a different switch and stays where it was.
        ->and((bool) DB::table('business_payment_method')->where('business_id', $business->id)->value('is_active'))->toBeTrue();
});

test('stopping is refused when there is nothing to stop, and says so', function () {
    // A keyed connection is the business's own gateway: turning it "off" here
    // would either do nothing or lie. The refusal says which.
    rq39Keys();
    rq39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(rq39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', ['is_active' => false])
        ->assertStatus(422)
        ->assertJsonPath('errors.provider.0', 'This provider is not processing payments for you, so there is nothing to switch off.');

    // And a business with an account but no switch to flip still cannot be
    // turned off from a payload that never said which value it meant.
    $this->withToken(rq39Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/activation', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_active');
});
