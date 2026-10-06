<?php

use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Accounting\LedgerSetupService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| AD-13 follow-up — subscription payments on the platform books
|--------------------------------------------------------------------------
| Ports the remaining `tests/Feature/Accounting/PlatformAccountingTest.php`
| coverage that the API suite did not assert: a subscription payment is
| posted to the platform books (business_id null) balanced and kobo-exact,
| the platform dashboard's gateway-clearing balance carries it, the tenant's
| own journal never sees it, and replaying the callback posts nothing twice.
|
| ws08paystackbillingTest already proves the callback creates a platform
| journal_entries row with the `subscription_payment:*` idempotency key;
| these tests read that posting back through the admin accounting API.
*/

function ad13subsAdminToken(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad13subsManagementToken(User $owner): string
{
    return $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ad13subsSuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad13subsPlan(): SubscriptionPlan
{
    return SubscriptionPlan::create([
        'name' => 'Growth Monthly',
        'description' => 'Mirrors the legacy 15,000 NGN subscription scenario.',
        'amount' => 15000.00,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_default' => false,
        'is_trial' => false,
        'features' => ['Sell online'],
    ]);
}

function ad13subsStore(User $owner): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'AD-13 Subscriptions Store',
        'slug' => 'ad13subs-'.Str::random(8),
        'status' => Store::STATUS_PENDING,
    ]);
}

/**
 * Fake the Paystack calls the money path makes: initialize, verify, and the
 * transaction-list double-check. A fresh factory is swapped in on each call
 * because Http::fake() merges stubs and the earliest match would shadow a
 * re-fake once the payment's real reference exists.
 */
function ad13subsFakeGateway(string $reference, int $amountKobo = 1500000): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($reference, $amountKobo) {
        if (str_contains($request->url(), 'transaction/initialize')) {
            return Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/ad13subs',
                    'access_code' => 'ad13subs_access',
                    'reference' => $reference,
                ],
            ]);
        }

        $payload = [
            'id' => 31313,
            'status' => 'success',
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'reference' => $reference,
        ];

        // transaction/verify returns the transaction object; the list
        // endpoint returns an array the double-check scans for the reference.
        return str_contains($request->url(), 'transaction/verify')
            ? Http::response(['status' => true, 'message' => 'Verification successful', 'data' => $payload])
            : Http::response(['status' => true, 'message' => 'Transactions retrieved', 'data' => [$payload]]);
    });
}

test('a subscription payment posts its full amount to the platform books and never to the tenant books', function () {
    Mail::fake();

    // The platform books exist. The admin dashboard bootstraps them in
    // production, and a posting needs their mappings to resolve.
    app(LedgerSetupService::class)->ensureForBusiness(null);

    $admin = ad13subsSuperAdmin();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $owner->update(['selected_plan_id' => ad13subsPlan()->id]);
    ad13subsStore($owner);

    // Pay 15,000 NGN through the real checkout + callback path.
    ad13subsFakeGateway('ref_ad13subs_checkout');

    $managementToken = ad13subsManagementToken($owner);

    $this->withToken($managementToken)
        ->postJson('/api/v1/management/subscription/process-payment', ['idempotency_key' => 'ad13subs-key-1'])
        ->assertOk();

    $payment = Payment::latest('id')->firstOrFail();

    ad13subsFakeGateway($payment->reference);

    $this->withToken($managementToken)
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertOk()
        ->assertJsonPath('data.already_processed', false);

    expect((string) $payment->fresh()->amount)->toBe('15000.00');

    // Sanctum caches the first user it resolves for the whole test, so the
    // guard must be forgotten before the superadmin's token is presented.
    app('auth')->forgetGuards();

    $adminToken = ad13subsAdminToken($admin);

    // The payment is one entry on the platform journal, found by reference.
    $list = $this->withToken($adminToken)
        ->getJson('/api/v1/admin/accounting/journal?q='.$payment->reference);

    $list->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonCount(1, 'data.entries');

    $detail = $this->withToken($adminToken)
        ->getJson('/api/v1/admin/accounting/journal/'.$list->json('data.entries.0.id'));

    $detail->assertOk()
        ->assertJsonPath('data.entry.reference', $payment->reference)
        ->assertJsonPath('data.entry.total_debits', 1500000)
        ->assertJsonPath('data.entry.total_credits', 1500000)
        ->assertJsonPath('data.entry.balanced', true);

    // Dr Payment Gateway Clearing 15,000 NGN, Cr Sales Revenue 15,000 NGN.
    $lines = collect($detail->json('data.entry.lines'))->keyBy('account_code');

    expect($lines)->toHaveCount(2)
        ->and($lines['1030']['debit_kobo'])->toBe(1500000)
        ->and($lines['4010']['credit_kobo'])->toBe(1500000);

    // The money is in the platform dashboard's clearing balance too.
    $cash = collect($this->withToken($adminToken)
        ->getJson('/api/v1/admin/accounting')
        ->json('data.cash_balances'))->keyBy('code');

    expect($cash['1030']['balance_kobo'])->toBe(1500000);

    // Replaying the callback is a no-op: the API short-circuits before the
    // posting, so exactly one platform entry carries this payment.
    app('auth')->forgetGuards();

    $this->withToken($managementToken)
        ->getJson('/api/v1/management/subscription/callback?reference='.$payment->reference)
        ->assertOk()
        ->assertJsonPath('data.already_processed', true);

    expect(JournalEntry::query()
        ->whereNull('business_id')
        ->where('idempotency_key', 'subscription_payment:'.$payment->id)
        ->count())->toBe(1);

    app('auth')->forgetGuards();

    $this->withToken($adminToken)
        ->getJson('/api/v1/admin/accounting/journal?q='.$payment->reference)
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    // The tenant's own books, fully bootstrapped, carry nothing for it.
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    app('auth')->forgetGuards();

    $this->withToken($managementToken)
        ->getJson('/api/v1/management/accounting/journal?q='.$payment->reference)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
