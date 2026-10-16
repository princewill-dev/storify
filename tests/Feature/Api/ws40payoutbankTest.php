<?php

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PayoutBankCode;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutSweepService;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

function pb40Settings(array $attributes): void
{
    $settings = Setting::query()->first() ?? Setting::query()->create(['company_name' => 'Storify']);
    $settings->update($attributes);
}

/**
 * A collected sale through the store, matured into `available_balance`.
 *
 * Built through the wallet's own writer rather than by seeding the column, so
 * the end-to-end test below starts from money that arrived the way a real
 * business's does — which is the only kind the sweep has anything to say about,
 * since the plan it pays from is a walk over these rows.
 */
function pb40Funded(User $owner, Store $store, int $kobo): Transaction
{
    $order = Order::create([
        'business_id' => $owner->business_id,
        'store_id' => $store->id,
        'order_number' => 'PB40-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]);

    $sale = Transaction::create([
        'reference' => 'PB40-TXN-'.Str::random(12),
        'business_id' => $owner->business_id,
        'order_id' => $order->id,
        'amount' => $kobo / 100,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ]);

    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($sale, 'bachs');
    $custody->promoteSettled();

    return $sale->fresh();
}

function pb40ProviderAccepts(): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
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

test('repointing at an account the provider has refused is refused, with the reason on file', function () {
    // The check the setup step makes on the first account, made again on every
    // repoint — a business can change which of its accounts pays out at any time,
    // and without this the first account is checked and every later one is not.
    pb40Settings(['system_payment_processor' => 'bachs']);
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    // The verdict as the provider's own refusal stores it: nothing payable, and
    // `is_usable` false — which is the whole of the difference between a provider
    // saying no and our never having got an answer.
    PayoutBankCode::query()->create([
        'store_bank_id' => $bank->id,
        'provider' => 'bachs',
        'bank_code' => null,
        'destination_id' => null,
        'is_usable' => false,
        'failure_reason' => 'Bachs cannot pay into an account at Sparkle Microfinance Bank.',
        'resolved_at' => now(),
    ]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.store_bank_id.0', 'Bachs cannot pay into an account at Sparkle Microfinance Bank.');

    expect($business->refresh()->payout_bank_id)->toBeNull();

    // Read off the row, not asked again: re-resolving here would register a second
    // destination at Bachs, which does not deduplicate, every time a business
    // pointed at a different one of its accounts.
    Http::assertNothingSent();
});

test('repointing at an account we never got an answer about is allowed', function () {
    // The other half of the same discrimination. `Bachs cannot pay this` and `we
    // could not ask Bachs` both leave the row unusable and both carry a sentence,
    // and only the first may stop a business choosing its own account — treating
    // the second as a refusal would let one provider's outage decide where every
    // business on the platform is allowed to be paid.
    pb40Settings(['system_payment_processor' => 'bachs']);
    pb40NoProvider();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    PayoutBankCode::query()->create([
        'store_bank_id' => $bank->id,
        'provider' => 'bachs',
        'bank_code' => null,
        'destination_id' => null,
        'is_usable' => null,
        'failure_reason' => 'We could not reach Bachs to check this bank.',
        'resolved_at' => now(),
    ]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
        ])
        ->assertOk();

    expect((int) $business->refresh()->payout_bank_id)->toBe((int) $bank->id);

    Http::assertNothingSent();
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
    // With per-store wallets off this is still the answer, and the reason is
    // unchanged: a payout is one transfer from one wallet, the wallet is the
    // business's, and a store's screen naming a bank would either be a lie or a
    // silent redirect of every other store's money.
    //
    // What changed is that it is now *conditional*. The wallet and the sweep
    // became per-store, so with the flag on a store naming its own account names
    // money that is genuinely separated — see the tests below. This one pins the
    // off state, which is what a deployment that has not flipped the flag gets.
    pb40NoProvider();
    config(['custody.per_store_wallets' => false]);

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

/*
|--------------------------------------------------------------------------
| WS-51 — the same question, asked of one store
|--------------------------------------------------------------------------
| A store may name its own account, and `stores.payout_bank_id` is that answer.
| It is an *override*, not a second destination: a store that names none falls
| back to the business's, which is where everything went before this existed.
|
| This endpoint refused a store scope outright until the wallet and the sweep
| became per-store. The refusal was right for its time — one column, one balance,
| one transfer — and the tests below pin the two halves of what replaced it: the
| store write, and the flag that keeps it off until the run would honour it.
*/

test('with per-store wallets off, a store scope is refused', function () {
    pb40NoProvider();
    config(['custody.per_store_wallets' => false]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = pb40Store($owner);
    $bank = StoreBank::factory()->create(['business_id' => $business->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'store_id' => $store->id,
        ])
        ->assertStatus(422);

    expect($store->fresh()->payout_bank_id)->toBeNull()
        ->and($business->fresh()->payout_bank_id)->toBeNull();
});

test('with per-store wallets on, a store names its own account and the business is untouched', function () {
    pb40NoProvider();
    config(['custody.per_store_wallets' => true]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $businessBank = StoreBank::factory()->create(['business_id' => $business->id]);
    $storeBank = StoreBank::factory()->create(['business_id' => $business->id]);

    $business->forceFill(['payout_bank_id' => $businessBank->id])->save();

    $store = pb40Store($owner);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $storeBank->id,
            'store_id' => $store->id,
        ])
        ->assertOk();

    expect($store->fresh()->payout_bank_id)->toBe($storeBank->id)
        // The business's destination is where every store that names none is
        // paid, so a store claiming one must not move it.
        ->and($business->fresh()->payout_bank_id)->toBe($businessBank->id);
});

test('a store cannot be pointed at an account the business does not hold', function () {
    pb40NoProvider();
    config(['custody.per_store_wallets' => true]);

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = pb40Store($owner);
    $theirs = StoreBank::factory()->create(['business_id' => $otherBusiness->id]);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $theirs->id,
            'store_id' => $store->id,
        ])
        ->assertStatus(422);

    expect($store->fresh()->payout_bank_id)->toBeNull();
});

test('a store belonging to another business cannot be named at all', function () {
    pb40NoProvider();
    config(['custody.per_store_wallets' => true]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $bank = StoreBank::factory()->create(['business_id' => $business->id]);
    $theirs = pb40Store($otherOwner);

    $this->withToken(pb40Token($owner))
        ->putJson('/api/v1/management/payment-gateways/bachs/payout-account', [
            'store_bank_id' => $bank->id,
            'store_id' => $theirs->id,
        ])
        // Validation, not a 403: the rule is scoped to the business, so an id
        // outside it is not a store this request may name — and the same answer
        // for an id that does not exist keeps this from probing for real ones.
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');

    expect($theirs->fresh()->payout_bank_id)->toBeNull();
});

test('the consolidation fills a destination for the businesses the setup step left without one', function () {
    // The repair, run against the class directly. `artisan migrate` would skip
    // it — the migration is already recorded as run for the test database — and
    // this test would then pass without executing a line.
    $migration = fn () => require database_path(
        'migrations/2026_10_16_000004_consolidate_payout_banks.php'
    );

    // The shape this exists for: the wizard wrote one account and flagged it,
    // and `businesses.payout_bank_id` was never told.
    [, $named] = createBusinessOwner();
    $account = StoreBank::factory()->create(['business_id' => $named->id]);

    // Already answered — by the payout-account endpoint, or by an earlier run of
    // this migration. A repair must not move a destination a business chose,
    // even to another of its own accounts.
    [, $answered] = createBusinessOwner();
    $chosen = StoreBank::factory()->create(['business_id' => $answered->id]);
    $answered->forceFill(['payout_bank_id' => $chosen->id])->save();

    // Never named an account at all. Untouched: the wallet's banner is the
    // honest answer for this business until it does.
    [, $solventless] = createBusinessOwner();

    // Accounts on file and none of them flagged. Nothing requires a flagged row
    // to exist — `is_primary` is a plain column with a plain index — so the
    // ordering has to answer this too, rather than falling through to a second
    // `no_bank` for a business that named three accounts.
    [, $unflagged] = createBusinessOwner();
    $older = StoreBank::factory()->create(['business_id' => $unflagged->id, 'is_primary' => false]);
    StoreBank::factory()->create(['business_id' => $unflagged->id, 'is_primary' => false]);

    // Two flagged rows, which no constraint forbids and concurrent
    // `PATCH .../primary` calls can produce. The tiebreak is what makes the
    // answer the same on every run rather than whichever row the engine read
    // first.
    [, $doubleFlagged] = createBusinessOwner();
    $lower = StoreBank::factory()->create(['business_id' => $doubleFlagged->id, 'is_primary' => true]);
    StoreBank::factory()->create(['business_id' => $doubleFlagged->id, 'is_primary' => true]);

    $migration()->up();

    expect((int) $named->refresh()->payout_bank_id)->toBe((int) $account->id)
        ->and((int) $answered->refresh()->payout_bank_id)->toBe((int) $chosen->id)
        ->and($solventless->refresh()->payout_bank_id)->toBeNull()
        ->and((int) $unflagged->refresh()->payout_bank_id)->toBe((int) $older->id)
        ->and((int) $doubleFlagged->refresh()->payout_bank_id)->toBe((int) $lower->id);

    // Idempotent, and — the part that matters — not an overwriter: a business
    // that named a different one of its own accounts after the first run keeps
    // it, and a second run fills nothing that a business has since unpicked.
    $later = StoreBank::factory()->create(['business_id' => $named->id, 'is_primary' => false]);
    $named->forceFill(['payout_bank_id' => $later->id])->save();

    $migration()->up();

    expect((int) $named->refresh()->payout_bank_id)->toBe((int) $later->id)
        ->and((int) $unflagged->refresh()->payout_bank_id)->toBe((int) $older->id);
});

test('the account a business names through the setup step is the one the sweep pays', function () {
    // The whole bug, end to end: a business that answered the wizard's question
    // held collected money that nothing would ever send, and its wallet said
    // `no_bank` while its bank account sat on the same screen. Both halves are
    // asserted here — the banner goes, and the money moves — because either one
    // alone would have been true all along.
    pb40Settings(['system_payment_processor' => 'bachs']);
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()], ['auto_payout' => true]);

    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();

    $store = pb40Store($owner);

    pb40Funded($owner, $store, 120_000);

    $sweep = app(PayoutSweepService::class);

    // Where the report started: money collected, matured, and unpayable.
    expect($sweep->payoutBlocker($business->refresh()))->toMatchArray(['code' => 'no_bank']);

    // The wizard's exact call. No store: the setup step names an account for the
    // business, and the first one is the business's own default.
    Http::swap(new Factory);
    Http::fake();

    $this->withToken(pb40Token($owner))
        ->postJson('/api/v1/management/payment-settings/bank-accounts', [
            'bank_code' => '058',
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'ACME LTD',
        ])
        ->assertCreated();

    $bank = StoreBank::where('business_id', $business->id)->sole();

    // Naming an account is now where the provider is asked what it calls this
    // bank, so this is no longer a pass that touches no provider at all — but the
    // fake above answers the provider's bank list with nothing, and an empty answer
    // is a list we did not get rather than a bank that cannot be paid. So nothing
    // was registered, there being no code to register with, and the account was
    // still written: our own failed read is not a reason to tell a business its
    // bank is unusable.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/payouts/destinations'));

    expect(PayoutBankCode::query()
        ->where('store_bank_id', $bank->id)
        ->whereNotNull('destination_id')
        ->count())->toBe(0);

    expect($sweep->payoutBlocker($business->refresh()))->toBeNull();

    pb40ProviderAccepts();

    $sweep->sweep();

    $payout = Payout::where('business_id', $business->id)->sole();

    expect((int) $payout->payout_bank_id)->toBe((int) $bank->id)
        ->and($payout->account_number)->toBe('0123456789')
        ->and((int) $payout->amount_kobo)->toBe(120_000)
        // The money left with the transfer that carries it. The sale came
        // through the store, so the store's wallet is the bucket it sat in —
        // drawn down in the same transaction as the payout row.
        ->and((int) $store->refresh()->available_balance)->toBe(0);
});
