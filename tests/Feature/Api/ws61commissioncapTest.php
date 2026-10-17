<?php

use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Connect\PlatformCommission;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| WS-61 — the commission is a percentage with a ceiling, not a percentage plus a flat fee.
|
| The owner asked for **"1% capped at ₦300"**. The old model added a fixed kobo
| amount to every sale, which made small sales disproportionately expensive and
| could push the fee to (or past) the gross at any percentage at all. The cap has
| the opposite shape: it never creates a charge, it only limits a large one.
|
| What this file pins:
|
| **Zero means uncapped, not zero fee.** A cap on its own is a ceiling, never a
| charge; only a zero percentage is "take nothing".
|
| **The cap is applied after rounding and before the refusal guard.** A 99.99%
| percentage would swallow a ₦1 sale, but the same rate capped at 50 kobo is
| perfectly takeable. Running the guard first would make the cap unable to do the
| one thing it exists for.
|
| **History is untouched.** The cap reaches the sale at the moment it is priced,
| is frozen into `platform_fee_kobo`, and is never recomputed from later settings.
|
| **The retired flat column is inert.** Rows and fixtures may still carry
| `platform_commission_fixed_kobo`; it contributes nothing to a fee.
*/

function cc61Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/** Write the singleton settings row the calculator reads. */
function cc61Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function cc61Store(User $owner): Store
{
    return Store::create([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'cc61-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
        'payment_mode' => 'manual',
    ]);
}

/**
 * Bachs, answering the one call a managed checkout makes.
 *
 * Only the checkout-session create is needed: this test reads the commission
 * that is frozen when the charge is initialized, before any settlement. The
 * other branches keep the gateway's readiness checks happy.
 */
function cc61FakeBachs(): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/accounts/')) {
            return Http::response([
                'id' => 'acct_cc61',
                'status' => 'active',
                'capabilities' => [
                    'card_collection' => ['status' => 'active'],
                    'payouts' => ['status' => 'active'],
                ],
                'requirements' => [],
            ]);
        }

        if (str_contains($url, '/balances')) {
            return Http::response([
                'balances' => [['currency' => 'NGN', 'available_balance' => '0.00', 'pending_balance' => '0.00']],
            ]);
        }

        if (str_contains($url, '/checkout-sessions')) {
            return Http::response([
                'checkout_id' => 'chk_cc61',
                'checkout_url' => 'https://checkout.bachs.io/chk_cc61',
                'status' => 'open',
            ]);
        }

        return Http::response(['status' => 'ok']);
    });
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

// ---------------------------------------------------------------------------
// The arithmetic
// ---------------------------------------------------------------------------

test('the percentage decides an ordinary sale and the ceiling decides a large one', function () {
    // The owner's example: 1% capped at ₦300 (30,000 kobo). A ₦5,000 sale pays
    // ₦50; a ₦50,000 sale would pay ₦500, so the cap takes it to ₦300.
    expect(PlatformCommission::fromParts(1, 30000)->on(500000))->toBe(5000)
        ->and(PlatformCommission::fromParts(1, 30000)->on(5000000))->toBe(30000);
});

test('a cap equal to the percentage part reduces nothing', function () {
    // 2.5% of ₦2,500 is exactly ₦62.50. `min()` must be inclusive, or a cap set
    // to the exact expected fee would shave a kobo off it.
    expect(PlatformCommission::fromParts(2.5, 6250)->on(250000))->toBe(6250);
});

test('a cap above the percentage part does not become the fee', function () {
    // A ceiling is not an entitlement. A ₦1,000 cap on a ₦62.50 commission still
    // charges ₦62.50.
    expect(PlatformCommission::fromParts(2.5, 100000)->on(250000))->toBe(6250);
});

test('a cap at or above the gross is not itself a reason to refuse the sale', function () {
    // The percentage is what can swallow a sale; the cap only limits it. A cap
    // larger than a small sale leaves the percentage's 3 kobo untouched.
    expect(PlatformCommission::fromParts(2.5, 100)->on(100))->toBe(3)
        ->and(PlatformCommission::fromParts(2.5, 1000000)->on(100))->toBe(3);
});

test('zero means uncapped, not zero commission', function () {
    $commission = PlatformCommission::fromParts(2.5, 0);

    expect($commission->maxKobo())->toBe(0)
        ->and($commission->isZero())->toBeFalse()
        ->and($commission->on(250000))->toBe(6250);
});

test('a ceiling with no percentage takes nothing', function () {
    $commission = PlatformCommission::fromParts(0, 50000);

    expect($commission->isZero())->toBeTrue()
        ->and($commission->on(250000))->toBeNull();
});

test('the cap is applied before the refusal guard', function () {
    // 99.99% of a ₦1 sale rounds to the whole ₦1, which cannot be taken. The
    // same rate capped at 50 kobo is fine — and capping first is what makes it
    // fine. If this test ever fails with null, the guard ran too early.
    expect(PlatformCommission::fromParts(99.99, 50)->on(100))->toBe(50)
        ->and(PlatformCommission::fromParts(99.99, 0)->on(100))->toBeNull();
});

test('rounding happens before the cap, at the boundary', function () {
    // 2.5% of 250,020 kobo is 6,251.25, rounded half-up to 6,251. A 6,250 cap
    // takes it back to exactly the ceiling.
    expect(PlatformCommission::fromParts(2.5, 0)->on(250020))->toBe(6251)
        ->and(PlatformCommission::fromParts(2.5, 6250)->on(250020))->toBe(6250);
});

test('a sale too small to carry a kobo takes no fee, capped or not', function () {
    // 2.5% of 19 kobo rounds to zero; the cap cannot invent a fee from nothing.
    expect(PlatformCommission::fromParts(2.5, 100)->on(19))->toBeNull()
        ->and(PlatformCommission::fromParts(2.5, 100)->on(20))->toBe(1);
});

test('the provider-facing decimal is the capped figure', function () {
    expect(PlatformCommission::fromParts(2.5, 5000)->onAsDecimal(250000))->toBe('50.00');
});

// ---------------------------------------------------------------------------
// Settings: reading, writing, and the retired flat column
// ---------------------------------------------------------------------------

test('the shipped settings take nothing, and the legacy flat amount is inert', function () {
    // A fresh install: percent 0, cap 0. The cap is a ceiling, so zero of both
    // is still "no commission".
    Setting::create(['company_name' => 'Storify']);

    $fresh = PlatformCommission::fromSettings();

    expect($fresh->isZero())->toBeTrue()
        ->and($fresh->maxKobo())->toBe(0)
        ->and($fresh->on(250000))->toBeNull();

    // A row that still carries the old flat fee must not add it to the
    // percentage. Only the cap may reduce the fee now.
    Setting::query()->update([
        'platform_commission_percent' => 2.5,
        'platform_commission_fixed_kobo' => 10000,
        'platform_commission_max_kobo' => 0,
    ]);

    expect(PlatformCommission::fromSettings()->on(250000))->toBe(6250);

    Setting::query()->update(['platform_commission_max_kobo' => 5000]);

    expect(PlatformCommission::fromSettings()->maxKobo())->toBe(5000)
        ->and(PlatformCommission::fromSettings()->on(250000))->toBe(5000);
});

test('the admin can set, re-save and clear the cap', function () {
    // The `ad02*` helpers come from the platform-settings suite; Pest loads
    // every test file into one process, and these are the fixtures for that
    // endpoint.
    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->getJson('/api/v1/admin/settings', ad02Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.settings.platform_commission_max_kobo', 0);

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['platform_commission_max_kobo' => 50000]), ad02Headers($admin))
        ->assertOk();

    expect((int) Setting::query()->first()->platform_commission_max_kobo)->toBe(50000);

    // An unrelated save has no opinion and must not wipe the ceiling.
    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['company_name' => 'Storify Ltd']), ad02Headers($admin))
        ->assertOk();

    expect((int) Setting::query()->first()->platform_commission_max_kobo)->toBe(50000);

    // A blank field is an instruction — uncapped — not an omission.
    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['platform_commission_max_kobo' => null]), ad02Headers($admin))
        ->assertOk();

    expect((int) Setting::query()->first()->platform_commission_max_kobo)->toBe(0);
});

// ---------------------------------------------------------------------------
// The cap reaches the frozen sale record
// ---------------------------------------------------------------------------

test('a managed sale stores the capped commission at the moment it is priced', function () {
    cc61Platform();
    cc61Settings([
        'system_payment_processor' => 'bachs',
        'platform_commission_percent' => 2.5,
        'platform_commission_max_kobo' => 5000,
    ]);
    cc61FakeBachs();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $business->forceFill(['custody_enabled' => true, 'custody_enabled_at' => now()])->save();

    $store = cc61Store($owner);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'CC61-'.fake()->unique()->numerify('######'),
        'subtotal' => 2500,
        'total' => 2500,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/bachs/initialize', [
        'order_number' => $order->order_number,
        'amount' => 2500,
    ], storefrontCustomerHeaders($store))
        ->assertOk()
        ->assertJsonPath('data.authorization_url', 'https://checkout.bachs.io/chk_cc61');

    $transaction = Transaction::query()->where('order_id', $order->id)->sole();

    // 2.5% of ₦2,500 is ₦62.50; the ₦50 cap is what the sale actually bears.
    // The metadata copy is the historical statement the backfill reads, so it
    // must agree with the column.
    expect($transaction->platform_fee_kobo)->toBe(5000)
        ->and($transaction->metadata['platform_fee_kobo'])->toBe(5000);
});
