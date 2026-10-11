<?php

use App\Jobs\PayOutSettledFunds;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\Connect\PlatformPayoutService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-39 — getting a business's settled money into its bank.
|
| Storify holds nothing: the money sits in the business's *own* account at Bachs
| and this is what empties it. That makes every guard here a money-leaving
| guard, and there is no endpoint to take a payout back — so the amount comes
| from the provider's own `available_balance` rather than from any figure of
| ours, a balance of zero is a skip rather than an attempt, and the request
| carries a persisted idempotency key so a retry cannot pay twice.
|
| The settled/available distinction is the whole reason this is on a schedule
| rather than at the checkout: a charge lands in `pending_balance` and only
| becomes movable when the provider settles it, which is days. Paying early is
| not risky, it is simply refused — `INSUFFICIENT_BALANCE`.
*/

function pl39Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'pl39-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function pl39Keys(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * A business with a connected account and an approved bank.
 *
 * `$destination` false leaves the bank out, which is the state a business is in
 * between registering its account and registering where the money goes.
 */
function pl39Managed(Business $business, string $accountId, bool $destination = true, ?Store $store = null): void
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
            'gateway_account_id' => $accountId,
            'config' => json_encode(['managed' => [
                'status' => 'active',
                'capabilities' => ['card_collection' => 'active', 'payouts' => 'active'],
                'requirements' => [],
                'payout_destination' => $destination ? [
                    'id' => 'pd_'.$accountId,
                    'is_usable' => true,
                    'currency' => 'NGN',
                    'bank_name' => 'Guaranty Trust Bank',
                    'account_name' => 'ADA TRADING LTD',
                    'last4' => '6789',
                ] : null,
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/**
 * Bachs, answering per account.
 *
 * The account is in the request *header*, which is why the closure reads it
 * rather than the URL — every call is addressed to the same host and the header
 * is the only thing that says whose money is being moved.
 *
 * @param  array<string, string>  $available  account id => available balance
 * @param  array<string, string>  $refusals  account id => the provider's error code
 */
function pl39FakeBachs(array $available = ['acct_pl39a' => '2500.00'], array $refusals = []): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($available, $refusals) {
        $account = (string) $request->header('X-Account-Id')[0];
        $url = $request->url();

        if (str_contains($url, '/accounts/')) {
            return Http::response([
                'id' => $account,
                'status' => 'active',
                'capabilities' => ['card_collection' => ['status' => 'active'], 'payouts' => ['status' => 'active']],
                'requirements' => [],
            ]);
        }

        if (str_contains($url, '/balances')) {
            // A large pending balance on purpose: the point of the sweep is that
            // it pays what has *settled*, and a test that had nothing pending
            // would pass against an implementation that ignored the difference.
            return Http::response([
                'balances' => [
                    ['currency' => 'USD', 'available_balance' => '0.00', 'pending_balance' => '0.00'],
                    ['currency' => 'NGN', 'available_balance' => $available[$account] ?? '0.00', 'pending_balance' => '9000.00'],
                ],
            ]);
        }

        if (str_contains($url, '/payouts') && $request->method() === 'POST') {
            if (isset($refusals[$account])) {
                return Http::response(['code' => $refusals[$account], 'message' => 'Refused.'], 400);
            }

            return Http::response([
                'id' => 'po_'.$account,
                'reference' => $request->data()['reference'] ?? null,
                'amount' => $request->data()['amount'] ?? null,
                'status' => 'pending',
            ]);
        }

        return Http::response(['status' => 'ok']);
    });
}

/** Every payout request that reached the provider, in order. */
function pl39PayoutRequests(): array
{
    $requests = [];

    Http::recorded(function (Request $request) use (&$requests) {
        if (str_contains($request->url(), '/payouts') && $request->method() === 'POST') {
            $requests[] = $request;
        }

        return false;
    });

    return $requests;
}

function pl39History(int $businessId): array
{
    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();

    $config = json_decode((string) DB::table('business_payment_method')
        ->where('business_id', $businessId)
        ->where('payment_method_id', $method->id)
        ->value('config'), true);

    return is_array($config['payouts'] ?? null) ? $config['payouts'] : [];
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

test('the sweep withdraws the provider\'s settled balance and nothing more', function () {
    // The amount is the provider's own figure, never ours. We cannot see what
    // has settled, and a payout that overdraws is refused — so the only safe
    // number to send is the one the provider just reported.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $counts = app(PlatformPayoutService::class)->sweep();

    expect($counts)->toBe(['paid' => 1, 'held' => 0, 'skipped' => 0, 'failed' => 0]);

    $sent = pl39PayoutRequests();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->header('X-Account-Id')[0])->toBe('acct_pl39a')
        ->and($sent[0]['destination'])->toBe('pd_acct_pl39a')
        // ₦2,500 as the destination receives it — the provider adds its own fee
        // on top, which is why the figure cannot be ours to choose.
        ->and($sent[0]['amount'])->toBe('2500.00')
        ->and($sent[0]->header('Idempotency-Key'))->not->toBeEmpty();

    // Recorded against the connection, so the admin console can show what left
    // and what became of it.
    $history = pl39History((int) $business->id);

    expect($history)->toHaveCount(1)
        ->and($history[0]['amount_kobo'])->toBe(250000)
        ->and($history[0]['destination'])->toBe('pd_acct_pl39a')
        ->and($history[0]['status'])->toBe('pending');
});

test('an account with nothing settled is skipped rather than attempted', function () {
    // The ordinary state for days after a sale: the charge succeeded and the
    // money is not yet movable. Asking anyway would be a refused call on every
    // tick, and the distinction is the reason this runs on a schedule.
    pl39Keys();
    pl39FakeBachs(['acct_pl39a' => '0.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $counts = app(PlatformPayoutService::class)->sweep();

    expect($counts)->toBe(['paid' => 0, 'held' => 0, 'skipped' => 1, 'failed' => 0])
        ->and(pl39PayoutRequests())->toBe([])
        ->and(pl39History((int) $business->id))->toBe([]);
});

test('an account with no bank registered is never asked for a balance', function () {
    // Registering the account and saying where the money goes are two steps, and
    // a business can be between them. There is nothing to pay into, so the
    // question is not worth asking.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a', destination: false);

    $counts = app(PlatformPayoutService::class)->sweep();

    expect($counts)->toBe(['paid' => 0, 'held' => 0, 'skipped' => 1, 'failed' => 0]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/balances'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payouts'));
});

test('one business\'s refused payout does not stop the next being paid', function () {
    // The sweep runs for everyone at once. A rejected bank account, a debt or a
    // destination under review is one business's problem and must never become
    // ninety-nine others' missed payout.
    pl39Keys();
    pl39FakeBachs(
        available: ['acct_pl39a' => '2500.00', 'acct_pl39b' => '5000.00'],
        refusals: ['acct_pl39a' => 'ORGANIZATION_IN_DEBT'],
    );

    [$ownerA, $businessA] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$ownerB, $businessB] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    pl39Managed($businessA, 'acct_pl39a');
    pl39Managed($businessB, 'acct_pl39b');

    $counts = app(PlatformPayoutService::class)->sweep();

    expect($counts)->toBe(['paid' => 1, 'held' => 0, 'skipped' => 0, 'failed' => 1]);

    // The one that worked did so, and the other was attempted and recorded.
    expect(pl39History((int) $businessB->id))->toHaveCount(1)
        ->and(pl39History((int) $businessA->id)[0]['status'])->not->toBe('pending');
});

test('the same silent retry carries the same idempotency key', function () {
    // The provider debits when the payout is created, and there is no endpoint
    // to take one back — so a second attempt at the same settled balance must
    // read to Bachs as the same request, not as a second payout. The key is
    // derived from the account, the destination and the amount, which is exactly
    // what makes it identical.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $payouts = app(PlatformPayoutService::class);

    $payouts->payOut((int) $business->id);
    $payouts->payOut((int) $business->id);

    $keys = array_map(fn (Request $request) => $request->header('Idempotency-Key')[0], pl39PayoutRequests());

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1]);
});

test('a payout is addressed to the store that collected the money', function () {
    // A store with its own account is a separate row and therefore a separate
    // payout: paying the business's account would leave that store's money
    // where the store cannot see it.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39store' => '1200.00', 'acct_pl39business' => '300.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = pl39Store($owner);

    pl39Managed($business, 'acct_pl39business');
    pl39Managed($business, 'acct_pl39store', store: $store);

    app(PlatformPayoutService::class)->payOut((int) $business->id, (int) $store->id);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/payouts')
        && $request->header('X-Account-Id')[0] === 'acct_pl39store'
        && $request->hasHeader('X-Account-Id'));

    expect(pl39History((int) $business->id))->toBe([]);
});

test('a business with no connected account is left alone', function () {
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $result = app(PlatformPayoutService::class)->payOut((int) $business->id);

    expect($result->outcome)->toBe('skipped');

    Http::assertNothingSent();
});

test('the scheduled command pays out, and a dry run only reports', function () {
    // The dry run is what makes this safe to point at a live organisation for
    // the first time: it reads every account's position and moves nothing.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $this->artisan('connect:payouts', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('2,500.00');

    expect(pl39PayoutRequests())->toBe([]);

    $this->artisan('connect:payouts')->assertSuccessful();

    expect(pl39PayoutRequests())->toHaveCount(1);
});

test('the command refuses to run when the platform holds no key', function () {
    // Nothing about this is safe to guess at: without the platform's key every
    // account read fails, and a sweep that reported "nothing settled" would look
    // exactly like a working one.
    config(['services.bachs.api_key' => null, 'services.bachs.webhook_secret' => null]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    Http::swap(new Factory);
    Http::fake();

    $this->artisan('connect:payouts')->assertFailed();

    Http::assertNothingSent();
});

/* ------------------------------------------------------------ admin console */

function pl39FinanceAdmin(): User
{
    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId(null);

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $admin->assignRole('Finance Admin');

    return $admin;
}

/**
 * An admin who runs the business directory and nothing else.
 *
 * No seeded role holds `admin.businesses` without also holding `admin.finance`,
 * so the permission is granted directly — otherwise the refusal below could be
 * coming from any of the permissions the role happens to lack, and the test
 * would pass while proving nothing about the one it names.
 */
function pl39BusinessOnlyAdmin(): User
{
    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId(null);

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $admin->givePermissionTo('admin.businesses');

    return $admin;
}

test('the console shows what the account holds and what has already left', function () {
    // The two numbers the decision needs: what can be moved, and what has been.
    // The balance comes from the provider rather than from anything of ours,
    // because it is the only figure a payout may be requested for.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $this->getJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', pl39Headers(pl39FinanceAdmin()))
        ->assertOk()
        ->assertJsonPath('data.account.account_id', 'acct_pl39a')
        ->assertJsonPath('data.account.available_kobo', 250000)
        // Masked, and no account number anywhere in the payload.
        ->assertJsonPath('data.account.payout_destination.last4', '6789')
        ->assertJsonPath('data.history', []);

    expect($this->getJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', pl39Headers(pl39FinanceAdmin()))
        ->json('data.account'))->not->toHaveKey('account_number');
});

test('pay out now moves the money and records the attempt', function () {
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', [], pl39Headers(pl39FinanceAdmin()))
        ->assertOk()
        ->assertJsonPath('data.outcome', 'paid')
        ->assertJsonPath('data.amount', '2500.00')
        ->assertJsonPath('data.payout_id', 'po_acct_pl39a')
        ->assertJsonPath('data.history.0.status', 'pending');

    expect(pl39PayoutRequests())->toHaveCount(1);
});

test('paying out an account with nothing settled is not reported as a failure', function () {
    // A 4xx here would have the console render a failure for a healthy account
    // between a sale and its settlement, which is most of the week.
    pl39Keys();
    pl39FakeBachs(['acct_pl39a' => '0.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', [], pl39Headers(pl39FinanceAdmin()))
        ->assertOk()
        ->assertJsonPath('data.outcome', 'skipped')
        ->assertJsonPath('data.history', []);

    expect(pl39PayoutRequests())->toBe([]);
});

test('a business with no payout account is answered rather than erroring', function () {
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $headers = pl39Headers(pl39FinanceAdmin());

    $this->getJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', $headers)
        ->assertOk()
        ->assertJsonPath('data.account', null)
        ->assertJsonPath('data.history', []);

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.outcome', 'skipped');
});

test('an admin without the finance permission cannot move a business\'s money', function () {
    // `admin.businesses` is not `admin.finance`: running the business directory
    // and withdrawing a tenant's money to a bank are different powers, and one
    // must not imply the other.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', [], pl39Headers(pl39BusinessOnlyAdmin()))
        ->assertForbidden();

    expect(pl39PayoutRequests())->toBe([]);
});

/* ------------------------------------------------------------- commission */

function pl39SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function pl39Headers(User $admin): array
{
    return [
        'Authorization' => 'Bearer '.$admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
        'Accept' => 'application/json',
    ];
}

test('the platform commission is bounded so a fee can never swallow a sale', function () {
    // Bachs refuses `platform_fee >= gross` outright, so an unbounded percentage
    // would turn every small sale into a customer-facing failure caused entirely
    // by our own configuration.
    $admin = pl39SuperAdmin();

    Setting::create(['company_name' => 'Storify']);

    $this->putJson('/api/v1/admin/settings', ['platform_commission_percent' => 100], pl39Headers($admin))
        ->assertStatus(422);

    $this->putJson('/api/v1/admin/settings', ['platform_commission_percent' => -1], pl39Headers($admin))
        ->assertStatus(422);

    $this->putJson('/api/v1/admin/settings', ['platform_commission_fixed_kobo' => -500], pl39Headers($admin))
        ->assertStatus(422);

    $this->putJson('/api/v1/admin/settings', ['platform_commission_percent' => 2.5], pl39Headers($admin))
        ->assertOk();

    expect((float) Setting::query()->first()->platform_commission_percent)->toBe(2.5);
});

test('a save that does not mention the commission leaves it as it was', function () {
    // `PUT /admin/settings` takes a whole form, so every field it *does* carry
    // is a statement about what that field should be — which is why the rest of
    // the settings follow the posted values rather than the stored ones. The
    // commission is deliberately the exception, and has to be: this screen is
    // not the only caller of the same save, and a partial save that reset the
    // commission to zero would quietly stop Storify earning on every sale it
    // processes from that moment on, with nothing on screen having said so.
    $admin = pl39SuperAdmin();

    Setting::create([
        'company_name' => 'Storify',
        'platform_commission_percent' => 2.5,
        'platform_commission_fixed_kobo' => 10000,
    ]);

    $this->putJson('/api/v1/admin/settings', [
        'company_name' => 'Storify Ltd',
    ], pl39Headers($admin))->assertOk();

    $settings = Setting::query()->first();

    expect($settings->company_name)->toBe('Storify Ltd')
        ->and((float) $settings->platform_commission_percent)->toBe(2.5)
        ->and((int) $settings->platform_commission_fixed_kobo)->toBe(10000);
});

test('the payment methods screen reports the commission and who it applies to', function () {
    // The admin sets Storify's cut there, beside the gateway it is taken
    // through, and is told which providers can actually run a platform-managed
    // connection — otherwise the number implies it applies to everything.
    $admin = pl39SuperAdmin();
    Setting::create(['company_name' => 'Storify', 'platform_commission_percent' => 2.5, 'platform_commission_fixed_kobo' => 10000]);

    $response = $this->getJson('/api/v1/admin/payment-methods', pl39Headers($admin))->assertOk();

    $billing = $response->json('data.billing');

    expect($billing['commission']['percent'])->toBe(2.5)
        ->and($billing['commission']['fixed_kobo'])->toBe(10000)
        ->and($billing['commission']['is_zero'])->toBeFalse()
        ->and($billing['commission']['managed_providers'])->toContain('bachs');
});

test('a commission of zero is a choice, not an error', function () {
    // A platform that charges businesses only for their subscription is a real
    // configuration, and no fee line is sent for it — a `0.00` platform fee is
    // not the same request to Bachs as no fee at all.
    $admin = pl39SuperAdmin();
    Setting::create(['company_name' => 'Storify']);

    $this->putJson('/api/v1/admin/settings', [
        'platform_commission_percent' => 0,
        'platform_commission_fixed_kobo' => 0,
    ], pl39Headers($admin))->assertOk();

    $response = $this->getJson('/api/v1/admin/payment-methods', pl39Headers($admin))->assertOk();

    expect($response->json('data.billing.commission.is_zero'))->toBeTrue();
});

test('the console can see every account a business holds, and pay the one it picks', function () {
    // A business whose stores bank separately has more than one account, and
    // each is a separate payout. The API could always pay a store scope; without
    // a list of them the console could not choose one, so a store's money sat in
    // an account nothing on screen could reach.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39store' => '1200.00', 'acct_pl39business' => '300.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = pl39Store($owner, ['name' => 'Ikeja Mall']);

    pl39Managed($business, 'acct_pl39business');
    pl39Managed($business, 'acct_pl39store', store: $store);

    $response = $this->getJson(
        '/api/v1/admin/businesses/'.$business->business_code.'/payouts',
        pl39Headers(pl39FinanceAdmin()),
    )->assertOk();

    $scopes = collect($response->json('data.scopes'));
    $whole = $scopes->firstWhere('store_id', null);
    $own = $scopes->firstWhere('store_id', $store->id);

    expect($scopes)->toHaveCount(2)
        // The business's own account is the one with no store, and it is
        // labelled rather than left blank — a row with no name reads as a bug.
        ->and($whole['label'])->toBe('Whole business')
        ->and($whole['account_id'])->toBe('acct_pl39business')
        // Shown, so an admin can see which account actually has money rather
        // than picking one to find out.
        ->and($whole['available_kobo'])->toBe(30000)
        ->and($own['label'])->toBe('Store: Ikeja Mall')
        ->and($own['account_id'])->toBe('acct_pl39store')
        ->and($own['available_kobo'])->toBe(120000);

    // Paying the scope the admin chose. Sent in the body, because the payout is
    // a POST — reading the query string alone would have paid the business's
    // account and left the store's money where the store cannot see it.
    $this->postJson(
        '/api/v1/admin/businesses/'.$business->business_code.'/payouts',
        ['store_id' => $store->id],
        pl39Headers(pl39FinanceAdmin()),
    )->assertOk()->assertJsonPath('data.outcome', 'paid');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/payouts')
        && $request->method() === 'POST'
        && $request->header('X-Account-Id')[0] === 'acct_pl39store');

    // And the history written is the store's, not the business's: the two are
    // separate accounts, and a record on the wrong one would read as the
    // business having been paid money that went to the store.
    $storeHistory = json_decode((string) DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->value('config'), true)['payouts'] ?? [];

    expect($storeHistory)->toHaveCount(1)
        ->and($storeHistory[0]['destination'])->toBe('pd_acct_pl39store')
        ->and(pl39History((int) $business->id))->toBe([]);
});

test('the console shows the chargebacks and refunds that explain a short balance', function () {
    // Not money we move, but money the business has already lost — and the
    // reason a settled figure can be smaller than the sales suggest. An admin
    // asked "why is this short" should not have to open the provider's dashboard.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $method = PaymentMethod::where('code', 'bachs')->firstOrFail();
    $config = json_decode((string) DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->where('payment_method_id', $method->id)
        ->value('config'), true);

    $config['disputes'] = [['id' => 'dp_pl39', 'amount_kobo' => 250000, 'currency' => 'NGN', 'status' => 'needs_response']];
    $config['refunds'] = [['id' => 'rf_pl39', 'status' => 'failed', 'failure_reason' => 'card_declined']];

    DB::table('business_payment_method')
        ->where('business_id', $business->id)
        ->where('payment_method_id', $method->id)
        ->update(['config' => json_encode($config)]);

    $this->getJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', pl39Headers(pl39FinanceAdmin()))
        ->assertOk()
        ->assertJsonPath('data.disputes.0.id', 'dp_pl39')
        ->assertJsonPath('data.disputes.0.amount_kobo', 250000)
        ->assertJsonPath('data.refunds.0.id', 'rf_pl39')
        ->assertJsonPath('data.refunds.0.failure_reason', 'card_declined');
});

/* ------------------------------------------------------- holding the money */

test('a business that has never been held is paid automatically', function () {
    // The regression guard for the migration: automatic withdrawal is what every
    // business already had, so a business created after the column exists is
    // opted in without anyone opting it in. A default that came out the other
    // way would strand every business's settled money in its provider account,
    // silently, on the day this shipped.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    expect($business->fresh()->auto_payout)->toBeTrue();

    pl39Managed($business, 'acct_pl39a');

    expect(app(PlatformPayoutService::class)->sweep())
        ->toBe(['paid' => 1, 'held' => 0, 'skipped' => 0, 'failed' => 0]);
});

test('a held business is counted, and its money is never asked for', function () {
    // The switch has one job: no payout request leaves for this business. The
    // balance is still readable — the money is in the business's own account at
    // the provider, not lost — so the console can show what is being held.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $business->update(['auto_payout' => false]);

    $counts = app(PlatformPayoutService::class)->sweep();

    // Counted separately from `skipped`: "nothing has settled" and "we are
    // deliberately holding this" are the two answers someone investigating a
    // missing transfer has to be able to tell apart.
    expect($counts)->toBe(['paid' => 0, 'held' => 1, 'skipped' => 0, 'failed' => 0])
        ->and(pl39PayoutRequests())->toBe([])
        ->and(pl39History((int) $business->id))->toBe([]);

    expect(app(PlatformPayoutService::class)->accountSummary((int) $business->id)['available_kobo'])
        ->toBe(250000);
});

test('a settlement does not move money for a held business', function () {
    // The sweep is not the only way money leaves. This job runs the moment a
    // sale settles, which is precisely when a held business's balance must not
    // be touched — so the check has to be on this path too.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $business->update(['auto_payout' => false]);

    (new PayOutSettledFunds((int) $business->id))->handle(app(PlatformPayoutService::class));

    expect(pl39PayoutRequests())->toBe([]);
});

test('the switch holds the schedule, not the admin paying by hand', function () {
    // This is how a business that would rather accumulate gets paid when it
    // asks, and it is the reason the switch is read in the sweep and the
    // settlement job rather than inside `payOut()`. Gating the choke point
    // instead would have made the manual button a lie.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $business->update(['auto_payout' => false]);

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/payouts', [], pl39Headers(pl39FinanceAdmin()))
        ->assertOk()
        ->assertJsonPath('data.outcome', 'paid')
        ->assertJsonPath('data.amount', '2500.00');

    expect(pl39PayoutRequests())->toHaveCount(1);
});

test('a dry run says which money is held rather than promising to pay it', function () {
    // The dry run's whole value is that what it prints is what the sweep would
    // do. An account it listed as payable but the sweep would skip is worse than
    // no report at all, because it is the report an operator trusts.
    pl39Keys();
    pl39FakeBachs(available: ['acct_pl39a' => '2500.00']);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $business->update(['auto_payout' => false]);

    $this->artisan('connect:payouts', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('held: automatic payouts off')
        ->expectsOutputToContain('Would pay 0.00 NGN in total.')
        ->expectsOutputToContain('And hold 2,500.00 NGN');

    expect(pl39PayoutRequests())->toBe([]);
});

/* ------------------------------------------------------- the switch itself */

test('an admin can hold and release a business\'s automatic payouts, and both are recorded', function () {
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $admin = pl39FinanceAdmin();
    $url = '/api/v1/admin/businesses/'.$business->business_code.'/payouts/automatic';

    $this->putJson($url, ['auto_payout' => false, 'reason' => 'Dispute under review'], pl39Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.auto_payout', false);

    expect($business->fresh()->auto_payout)->toBeFalse();

    $row = DB::table('activity_logs')->where('action', 'business_payout_automatic_disabled')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->business_id)->toBe((int) $business->id)
        ->and((int) $row->user_id)->toBe((int) $admin->id)
        ->and(json_decode((string) $row->old_values, true))->toBe(['auto_payout' => true])
        ->and(json_decode((string) $row->new_values, true))->toBe(['auto_payout' => false])
        ->and(json_decode((string) $row->metadata, true))->toBe(['reason' => 'Dispute under review']);

    // Back on, under its own action name: two decisions, two rows, rather than
    // one row rewritten until the log no longer says what happened.
    $this->putJson($url, ['auto_payout' => true], pl39Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.auto_payout', true);

    expect($business->fresh()->auto_payout)->toBeTrue()
        ->and(DB::table('activity_logs')->where('action', 'business_payout_automatic_enabled')->exists())->toBeTrue();
});

test('a payload that does not say whether to hold is refused', function () {
    // The endpoint does one thing, so a body that does not state the value is a
    // caller that has not decided. Guessing "on" would move money nobody asked
    // to move; guessing "off" would strand money nobody meant to strand.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $this->putJson(
        '/api/v1/admin/businesses/'.$business->business_code.'/payouts/automatic',
        ['reason' => 'Just a note'],
        pl39Headers(pl39FinanceAdmin()),
    )->assertStatus(422)->assertJsonValidationErrors('auto_payout');

    expect($business->fresh()->auto_payout)->toBeTrue();
});

test('holding a business\'s payouts is not a permission the business directory carries', function () {
    // The same boundary as paying out: `admin.businesses` runs the directory,
    // and stopping a tenant's income is `admin.finance`.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    pl39Managed($business, 'acct_pl39a');

    $this->putJson(
        '/api/v1/admin/businesses/'.$business->business_code.'/payouts/automatic',
        ['auto_payout' => false],
        pl39Headers(pl39BusinessOnlyAdmin()),
    )->assertForbidden();

    expect($business->fresh()->auto_payout)->toBeTrue();
});

test('the console says whether payouts are automatic, and whether money has anywhere to go', function () {
    // Two facts the panel cannot work out for itself. `auto_payout` is what the
    // sweep reads, and `has_destination` is the difference between a business
    // whose money is moving and one whose money has been quietly skipping for
    // weeks because there is no bank to send it to.
    pl39Keys();
    pl39FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $stranded = pl39Store($owner, ['name' => 'No Bank Branch']);

    pl39Managed($business, 'acct_pl39a');
    pl39Managed($business, 'acct_pl39nobank', destination: false, store: $stranded);

    $business->update(['auto_payout' => false]);

    $response = $this->getJson(
        '/api/v1/admin/businesses/'.$business->business_code.'/payouts',
        pl39Headers(pl39FinanceAdmin()),
    )->assertOk()->assertJsonPath('data.auto_payout', false);

    $scopes = collect($response->json('data.scopes'));
    $whole = $scopes->firstWhere('store_id', null);
    $noBank = $scopes->firstWhere('store_id', $stranded->id);

    expect($whole['has_destination'])->toBeTrue()
        ->and($whole['destination']['bank_name'])->toBe('Guaranty Trust Bank')
        // Masked here too. The number went to the provider at registration and
        // was never kept, so there is nothing fuller to leak.
        ->and($whole['destination']['last4'])->toBe('6789')
        ->and($whole['destination'])->not->toHaveKey('account_number')
        ->and($noBank['has_destination'])->toBeFalse()
        ->and($noBank['destination'])->toBeNull();
});
