<?php

use App\Models\ExchangeRate;
use App\Support\Money\ExchangeRateService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/*
| Converting a naira store into a USD provider's currency.
|
| Bitfra is the only provider here that prices in dollars, and the resolver now
| offers it to a naira store on the strength of a fetched rate rather than
| refusing it outright. That makes two things load-bearing, and both are pinned
| below: the rate has to be fetchable and storable, and the arithmetic has to
| fail *closed* when it is not — a missing rate must never become a free
| purchase.
|
| The published rate is stored by `rates:fetch` from a keyless public API. The
| command is tested against a fake; the conversion is tested against rows
| written directly, so the two failures are distinguishable when one of them
| breaks.
*/

function fxSeedRate(string $quote, float $rate, ?string $fetchedAt = null): ExchangeRate
{
    return ExchangeRate::updateOrCreate(
        ['base' => 'USD', 'quote' => $quote],
        ['rate' => (string) $rate, 'fetched_at' => $fetchedAt ?? now()],
    );
}

function fxService(): ExchangeRateService
{
    // The service caches the table; each test writes rows directly, so the
    // cached copy of the previous test's rows would otherwise answer.
    ExchangeRateService::forget();

    return app(ExchangeRateService::class);
}

/**
 * The shape open.er-api.com returns, trimmed to the fields the command reads.
 */
function fxApiBody(array $rates, ?int $updatedUnix = null): array
{
    return [
        'result' => 'success',
        'base_code' => 'USD',
        'time_last_update_unix' => $updatedUnix ?? now()->timestamp,
        'rates' => $rates,
    ];
}

beforeEach(function () {
    // CAD, NGN and USD are what the seeder ships; the fetch job stores a row
    // for what the catalogue knows, not for all ~160 the API returns.
    $this->seed(CurrencySeeder::class);
    ExchangeRateService::forget();
});

/* ------------------------------------------------------------- the fetch job */

test('the fetch job stores a row for every currency the catalogue knows', function () {
    Http::swap(new Factory);
    Http::fake([
        '*' => Http::response(fxApiBody(['NGN' => 1331.267014, 'CAD' => 1.3642, 'USD' => 1, 'JPY' => 149.3])),
    ]);

    $this->artisan('rates:fetch')->assertSuccessful();

    $stored = ExchangeRate::pluck('rate', 'quote');

    expect(ExchangeRate::pluck('quote')->sort()->values()->all())->toBe(['CAD', 'NGN'])
        ->and((float) $stored['NGN'])->toBe(1331.267014)
        ->and((float) $stored['CAD'])->toBe(1.3642)
        // Neither the base quoting itself nor a currency the app cannot charge
        // in is stored.
        ->and($stored)->not->toHaveKey('USD')
        ->and($stored)->not->toHaveKey('JPY');
});

test('the fetch job records when the provider published the rate, not when we asked', function () {
    $published = now()->subHours(30)->startOfSecond();

    Http::swap(new Factory);
    Http::fake(['*' => Http::response(fxApiBody(['NGN' => 1331.2], $published->timestamp))]);

    $this->artisan('rates:fetch')->assertSuccessful();

    expect(ExchangeRate::where('quote', 'NGN')->sole()->fetched_at->timestamp)
        ->toBe($published->timestamp);
});

test('a failed fetch leaves the stored rates exactly as they were', function () {
    $existing = fxSeedRate('NGN', 1300.0, now()->subDay()->toDateTimeString());

    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['message' => 'nope'], 503)]);

    $this->artisan('rates:fetch')->assertFailed();

    $row = $existing->fresh();

    expect((float) $row->rate)->toBe(1300.0)
        ->and($row->fetched_at->timestamp)->toBe($existing->fetched_at->timestamp);
});

test('a response that is not a rate table leaves the stored rates alone', function () {
    $existing = fxSeedRate('NGN', 1300.0);

    Http::swap(new Factory);
    // A 200 with an envelope that has no rates — a changed schema, or an HTML
    // error page. Writing zero rows over good ones would take USD gateways
    // offline until the next run.
    Http::fake(['*' => Http::response(['result' => 'error', 'error-type' => 'unsupported-code'])]);

    $this->artisan('rates:fetch')->assertFailed();

    expect((float) $existing->fresh()->rate)->toBe(1300.0);
});

test('a currency the API does not know keeps its previous rate', function () {
    $existing = fxSeedRate('CAD', 1.35);

    Http::swap(new Factory);
    Http::fake(['*' => Http::response(fxApiBody(['NGN' => 1331.2]))]);

    $this->artisan('rates:fetch')->assertSuccessful();

    expect((float) $existing->fresh()->rate)->toBe(1.35)
        ->and((float) ExchangeRate::where('quote', 'NGN')->sole()->rate)->toBe(1331.2);
});

/* ------------------------------------------------------------ the arithmetic */

test('a naira amount converts into the provider minor units', function () {
    fxSeedRate('NGN', 1331.267014);

    // ₦23,000 is 2,300,000 kobo; at ₦1,331.267014 to the dollar that is
    // $17.2767… — 1,728 cents, rounded to the cent as the provider charges it.
    expect(fxService()->convert(2300000, 'NGN', 'USD'))->toBe(1728);
});

test('a converted partial converts back into naira minor units', function () {
    fxSeedRate('NGN', 1000.0);

    // $2.50 at ₦1,000 to the dollar is ₦2,500 — 250,000 kobo.
    expect(fxService()->convert(250, 'USD', 'NGN'))->toBe(250000);
});

test('the same currency converts to itself, unchanged', function () {
    // The path six of the seven providers take. It must not depend on a row
    // existing — there is nothing to look up.
    expect(fxService()->convert(2300000, 'NGN', 'NGN'))->toBe(2300000)
        ->and(fxService()->convert(1728, 'USD', 'USD'))->toBe(1728)
        ->and(fxService()->divisorFor('NGN', 'NGN'))->toBe(1.0);
});

test('a missing rate converts to null, never to zero and never to the input', function () {
    // No rows at all. `round($x) * null` is 0 in PHP, so an unchecked
    // unresolvable pair would turn a ₦23,000 sale into a free one.
    expect(fxService()->convert(2300000, 'NGN', 'USD'))->toBeNull()
        ->and(fxService()->divisorFor('NGN', 'USD'))->toBeNull()
        ->and(fxService()->ratedAt('NGN', 'USD'))->toBeNull();
});

test('a currency whose minor unit is not a hundredth is never priced', function () {
    // JPY has no decimal places. The conversion cancels the two scales against
    // each other, which is only sound when both are 100 — so a currency outside
    // the explicit list fails closed and hides the gateway instead of charging
    // a hundredfold.
    fxSeedRate('JPY', 149.3);
    fxSeedRate('NGN', 1331.267014);

    expect(fxService()->convert(2300000, 'NGN', 'JPY'))->toBeNull()
        ->and(fxService()->convert(2300000, 'JPY', 'NGN'))->toBeNull()
        // Even against itself: the caller asked a question this cannot answer
        // soundly, so it says so rather than guessing.
        ->and(fxService()->convert(100, 'JPY', 'JPY'))->toBeNull();
});

test('an amount below one minor unit converts to zero, for the caller to refuse', function () {
    fxSeedRate('NGN', 1331.267014);

    // ₦1 is 100 kobo — well under half a cent. The service is honest that it
    // rounds to nothing; refusing it is the caller's job, because only the
    // caller knows whether to refuse or to round.
    expect(fxService()->convert(100, 'NGN', 'USD'))->toBe(0)
        ->and(fxService()->smallestConvertibleMinor('NGN', 'USD'))->toBe(666);
});

test('the divisor is the single number a browser needs to mirror the conversion', function () {
    fxSeedRate('NGN', 1331.267014);

    $divisor = fxService()->divisorFor('NGN', 'USD');

    // Rounding the minor amount divided by the divisor reproduces `convert()`.
    expect($divisor)->toEqualWithDelta(1331.267014, 0.000001)
        ->and((int) round(2300000 / $divisor))->toBe(1728);
});

test('the rated-at stamp is the older of the two sides consulted', function () {
    fxSeedRate('NGN', 1331.267014, now()->subHours(5)->toDateTimeString());
    fxSeedRate('CAD', 1.36, now()->subHours(40)->toDateTimeString());

    $rates = fxService();

    // A conversion is only as fresh as its stalest operand.
    expect((int) $rates->ratedAt('NGN', 'CAD')->diffInHours(now()))->toBe(40)
        // Nothing is consulted to convert a currency into itself.
        ->and($rates->ratedAt('NGN', 'NGN'))->toBeNull()
        // The base resolves structurally, so only the other side is stamped.
        ->and((int) $rates->ratedAt('USD', 'NGN')->diffInHours(now()))->toBe(5);
});
