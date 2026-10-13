<?php

use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-40 — the bank account a business is paid into.
|
| The business is asked one question — which of its own bank accounts its money
| should reach — and `businesses.payout_bank_id` is the answer. That column is
| the only destination the payout sweep reads: `PayoutSweepService` takes
| `bank_code` and `account_number` off the row it names, and nothing else
| anywhere decides where a business's money goes.
|
| What it deliberately is *not* any more is a gate. It used to be the thing the
| whole screen keyed on, because the provider paid the business directly and a
| charge with nowhere to land stranded money — so a business with no destination
| could not sell. Storify collects now, the money is held, and this column only
| decides when it leaves. The tests below are therefore about the *column*:
| which row it names, for whom, and what happens when the answer is bad.
|
| ## The endpoint makes no provider call
|
| Every test here asserts that, directly or by simply not faking a provider. What
| used to happen on this route was an account creation, a destination
| registration and a settlement-schedule push — three calls, each able to fail,
| each leaving the business in a different half-built state. What happens now is
| one `UPDATE`, and a test that did not say so would let the calls creep back.
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

function pb40Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/**
 * Nothing on this route may reach out, so anything that does is a failure rather
 * than a fixture. Faked empty so a stray call shows up as a `ConnectionException`
 * naming the URL instead of as a real request to a sandbox.
 */
function pb40NoProvider(): void
{
    Http::swap(new Factory);
    Http::fake();
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('naming one of the business\'s own accounts records it, and asks nobody', function () {
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    expect($business->payout_bank_id)->toBeNull();

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.success', true);

    expect((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id);

    // The assertion that keeps the deleted provisioning from coming back. If a
    // future change opens an account or registers a destination here, this is
    // where it stops.
    Http::assertNothingSent();
});

test('it does not require an entity type or a terms acceptance', function () {
    // Both were inputs to opening an account: the entity type decided which
    // documents the provider would demand and was immutable once set, and the
    // acceptance was submitted as the provider's own `tos_acceptance`. No account
    // is opened, so neither is a fact anybody holds — and a form that still sent
    // them would be asking an owner to classify their business and agree to a
    // contract for something that does not happen.
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'entity_type' => 'individual',
            'terms_accepted' => true,
        ])
        ->assertOk();

    // Accepted and ignored, rather than rejected: an older client still sending
    // them costs nothing, and refusing would break its owner for a field the
    // server has stopped caring about.
    expect((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id);
});

test('typed details are matched back to the row holding them, so a pointer exists', function () {
    // The screen's other path: an owner who has just typed an account number
    // rather than picked one from the list. There is no row to point at unless
    // the numbers are matched back to the `store_banks` row that already holds
    // them — and a pointer is what the sweep reads, so without the match the
    // business would be paid nowhere while believing it had named a bank.
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create([
        'business_id' => $business->id,
        'account_number' => '0123456789',
        'bank_code' => '058',
    ]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'account_number' => '0123456789',
            'account_name' => 'Ada Trading Ltd',
            'bank_code' => '058',
        ])
        ->assertOk();

    expect((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id);
});

test('typed details matching nothing are refused, rather than answered as though they were saved', function () {
    // A pointer is a foreign key, and there is no row here to point at. Creating
    // one would mean this service writing a bank account from arguments it is not
    // responsible for verifying — and a wrong row is worse than a null, because
    // the sweep would trust it and send somebody's money to it.
    //
    // So nothing is invented. But nothing is *answered* either: this used to
    // return `200` with "Sales will be paid into that bank account." while
    // writing nothing at all, and the owner had no way to tell. The screen said
    // the money was routed and it was not, and the first evidence would have been
    // a payout that never arrived. A refusal in words is the only honest reply to
    // a submission the server cannot act on.
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'account_number' => '0123456789',
            'account_name' => 'Ada Trading Ltd',
            'bank_code' => '058',
            'bank_name' => 'Guaranty Trust Bank',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_number');

    expect($business->refresh()->payout_bank_id)->toBeNull()
        ->and(DB::table('store_banks')->where('business_id', $business->id)->count())->toBe(0);
});

test('a refused submission does not clear a destination the business already had', function () {
    // The other half of the same defect, and the more expensive half. The sweep
    // reads `payout_bank_id` and nothing else, so a bad submission that wrote the
    // column as null would leave a business that *had* been getting paid with no
    // destination at all — silently, permanently, and with a `200` saying its
    // bank had just been recorded. Refusing before the write is what closes it;
    // this pins the column itself, because "it threw" and "it threw before it
    // touched anything" are different claims.
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create([
        'business_id' => $business->id,
        'account_number' => '0123456789',
        'bank_code' => '058',
    ]);

    $business->forceFill(['payout_bank_id' => $bank->id])->save();

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'account_number' => '9999999999',
            'account_name' => 'Someone Else',
            'bank_code' => '999',
            'bank_name' => 'A Bank That Does Not Match',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_number');

    // Still pointing at the account that worked.
    expect((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id)
        ->and(DB::table('store_banks')->where('business_id', $business->id)->count())->toBe(1);
});

test('a bank account belonging to another business is refused', function () {
    pb40NoProvider();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $foreign = StoreBank::factory()->create(['business_id' => $otherBusiness->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $foreign->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_bank_id');

    // Refused before anything was written, which is the point of validating it
    // here rather than discovering it when the money is already on its way.
    Http::assertNothingSent();
});

test('a store scope is refused rather than naming the whole business\'s account', function () {
    // A payout is one transfer from one wallet, and the wallet is the
    // business's — nothing else on the platform is separated per store. So a
    // store's screen naming a bank would either be a lie or a silent redirect of
    // every other store's money, and there is no third reading. Refused in words,
    // which is the part worth pinning: a silent `UPDATE` on the business's column
    // would look like it worked.
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = pb40Store($owner);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_id' => $store->id,
            'store_bank_id' => $bank->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');

    expect($business->refresh()->payout_bank_id)->toBeNull();

    // And no row was written for the store either: the refusal is a refusal, not
    // a redirect to the other scope.
    expect(DB::table('store_payment_method')->where('store_id', $store->id)->exists())->toBeFalse();
});

test('a destination can be named before the platform holds any provider keys', function () {
    // The column is local, so it does not depend on Storify's credentials being
    // present — and it must not. An owner naming their bank during a window when
    // the platform's key is being rotated is naming the account their money goes
    // to; refusing it would leave their sales collected and unsendable for as
    // long as the rotation took.
    pb40NoProvider();

    config(['services.bachs.api_key' => null, 'services.bachs.secret_key' => null]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
        ])
        ->assertOk();

    expect((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id);
});
