<?php

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\StoreBank;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-40 — `connect:payees`, the pass that re-points destinations at the bank
| account on file.
|
| The gap it exists for: switching the processor leaves businesses that have
| named a bank account holding none at the provider now in force. What it must
| **not** do is close that gap by creating accounts. The entity type and the
| terms acceptance are decided once and are immutable at the provider, which is
| why they come off a form rather than being defaulted — a job that called
| `provision()` would open an undeletable account with a guessed entity type and
| a payable destination with nobody's consent on record.
|
| So the three properties this file pins down are: it registers destinations and
| nothing else, it never asks the provider for anything when the destination
| already agrees, and a run that did nothing says so rather than reporting
| success over a platform where nothing could be registered at all.
*/

function cp40Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function cp40PlatformBachs(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * A business with a bank account named and an account at the processor, holding
 * whatever destination it was last given.
 *
 * @param  array<string, mixed>|null  $destination  null for an account that has
 *                                                  never had one registered
 */
function cp40Business(?array $destination, string $accountNumber = '0123456789'): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $bank = StoreBank::factory()->create([
        'business_id' => $business->id,
        'account_number' => $accountNumber,
        'bank_name' => 'Guaranty Trust Bank',
    ]);

    $business->forceFill(['payout_bank_id' => $bank->id, 'payout_entity_type' => 'company'])->save();

    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();

    DB::table('business_payment_method')->updateOrInsert(
        ['business_id' => $business->id, 'payment_method_id' => $method->id],
        [
            'is_active' => true,
            'gateway_account_id' => 'acct_cp40',
            'config' => json_encode(['managed' => array_filter([
                'status' => 'active',
                'capabilities' => ['card_collection' => 'active', 'payouts' => 'active'],
                'payout_destination' => $destination,
            ])]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    return [$business->fresh(), $bank];
}

/**
 * Bachs answering a destination registration, and nothing else.
 */
function cp40FakeBachs(): void
{
    Http::swap(new Factory);

    Http::fake([
        'sandbox-api.bachs.io/*' => Http::response([
            'id' => 'pd_cp40',
            'is_usable' => true,
            'status' => 'active',
            'currency' => 'NGN',
            'bank_name' => 'Guaranty Trust Bank',
            'account_name' => 'ADA TRADING LTD',
            'last4' => '6789',
        ]),
    ]);
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('with the switch off it is a no-op rather than a nightly failure', function () {
    // A scheduled job that failed every hour over a platform that is simply not
    // enforcing anything would be noise, and noise is how a real failure gets
    // ignored.
    Http::swap(new Factory);
    Http::fake();

    $this->artisan('connect:payees')
        ->expectsOutputToContain('No system payment processor is in force.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('a processor this build cannot run is reported as a failure', function () {
    // The other half of the same branch. Here every business on the platform is
    // unable to sell, and a scheduler quietly reporting success would be the
    // only voice saying otherwise.
    cp40Settings(['system_payment_processor' => 'stripe']);

    $this->artisan('connect:payees')
        ->expectsOutputToContain('cannot be run')
        ->assertExitCode(1);
});

test('it never creates an account', function () {
    // The rule the whole command is shaped around. A business with a bank
    // account named and no account at this processor is waiting on the forced
    // step, where the entity type and the terms are the owner's own acts.
    cp40Settings(['system_payment_processor' => 'bachs']);
    cp40PlatformBachs();
    cp40FakeBachs();

    [$business] = cp40Business(destination: null);
    DB::table('business_payment_method')->where('business_id', $business->id)->delete();

    $this->artisan('connect:payees')
        ->expectsOutputToContain('no account at this processor yet')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('a destination that already agrees with the bank account is left alone', function () {
    // The property that makes an hourly run safe: the provider is not called at
    // all. Decided on the last four digits and nothing else — the bank's name is
    // spelled by whoever is speaking, so "GTBank" here against the "Guaranty
    // Trust Bank" our own list holds would otherwise re-register the same
    // destination every hour and file an audit entry saying a business's money
    // had moved when it had not.
    cp40Settings(['system_payment_processor' => 'bachs']);
    cp40PlatformBachs();
    cp40FakeBachs();

    cp40Business(destination: [
        'id' => 'pd_cp40',
        'is_usable' => true,
        'last4' => '6789',
        'bank_name' => 'GTBank',
    ], accountNumber: '0123456789');

    $this->artisan('connect:payees')
        ->expectsOutputToContain('already registered')
        ->assertExitCode(0);

    Http::assertNothingSent();

    expect(ActivityLog::where('action', 'payment_gateway.payout_destination_registered')->exists())
        ->toBeFalse();
});

test('a destination that disagrees is re-registered and recorded', function () {
    // What the pass is actually for: the business has moved its payouts to
    // another of its own accounts, and the provider still holds the old one.
    cp40Settings(['system_payment_processor' => 'bachs']);
    cp40PlatformBachs();
    cp40FakeBachs();

    [$business] = cp40Business(destination: [
        'id' => 'pd_old',
        'is_usable' => true,
        'last4' => '1111',
        'bank_name' => 'Zenith Bank',
    ], accountNumber: '9876543210');

    $this->artisan('connect:payees')
        ->expectsOutputToContain('registered')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/payouts/destinations')
        && $request['account_number'] === '9876543210'
        && $request->hasHeader('X-Account-Id', 'acct_cp40'));

    $config = json_decode(
        (string) DB::table('business_payment_method')->where('business_id', $business->id)->value('config'),
        true,
    );

    // The account the business named, now the one the provider holds — which is
    // what makes the *next* run of this pass a no-op.
    expect($config['managed']['payout_destination']['last4'])->toBe('3210')
        // Attribution for a machine-made change to where a business's money
        // goes: the trail is the only place that can answer "why is this
        // business's money landing somewhere else than it was last month".
        ->and(ActivityLog::where('action', 'payment_gateway.payout_destination_registered')
            ->where('business_id', $business->id)
            ->exists())->toBeTrue();
});

test('a dry run reports without sending anything', function () {
    cp40Settings(['system_payment_processor' => 'bachs']);
    cp40PlatformBachs();
    cp40FakeBachs();

    cp40Business(destination: [
        'id' => 'pd_old',
        'is_usable' => true,
        'last4' => '1111',
    ], accountNumber: '9876543210');

    $this->artisan('connect:payees', ['--dry-run' => true])
        ->expectsOutputToContain('would register')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('one business can be asked about on its own', function () {
    // What an operator runs when a business asks why its money is still going
    // somewhere else — and the reason the report is per business rather than one
    // number. The other business is not read, and so not touched.
    cp40Settings(['system_payment_processor' => 'bachs']);
    cp40PlatformBachs();
    cp40FakeBachs();

    [$named] = cp40Business(destination: ['id' => 'pd_old', 'last4' => '1111'], accountNumber: '0123456789');
    [$other] = cp40Business(destination: ['id' => 'pd_old', 'last4' => '2222'], accountNumber: '9876543210');

    $this->artisan('connect:payees', ['--business' => (string) $named->id, '--dry-run' => true])
        ->expectsOutputToContain('#'.$named->id)
        ->assertExitCode(0);

    expect(Artisan::output())->not->toContain('#'.$other->id);
});

test('a refusal from the provider fails the run', function () {
    // One business whose money has no route to its bank among a hundred quiet
    // successes is exactly the case worth waking somebody for.
    cp40Settings(['system_payment_processor' => 'bachs']);
    cp40PlatformBachs();

    Http::swap(new Factory);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['message' => 'That account cannot receive payouts.'], 422)]);

    cp40Business(destination: ['id' => 'pd_old', 'last4' => '1111'], accountNumber: '9876543210');

    $this->artisan('connect:payees')
        ->expectsOutputToContain('That account cannot receive payouts.')
        ->assertExitCode(1);
});

test('a business with no bank account named is not asked about at all', function () {
    // There is nothing to register, and the forced step on its next visit is
    // what collects it. The report says so rather than leaving an operator to
    // infer it from a count that does not add up.
    cp40Settings(['system_payment_processor' => 'bachs']);
    cp40PlatformBachs();
    cp40FakeBachs();

    Business::query()->update(['payout_bank_id' => null]);

    $this->artisan('connect:payees')
        ->expectsOutputToContain('No businesses have a bank account named.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});
