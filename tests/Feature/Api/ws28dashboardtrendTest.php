<?php

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Resources\Management\Dashboard\TrendSeriesResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-28 — the dashboard trend window
|--------------------------------------------------------------------------
| The sparklines and the period comparison. The properties that matter are
| that a day with no sales is still a day, that the money is summed from the
| column that holds it, that the comparison window is the one immediately
| before rather than an overlapping one, and that neither is reachable with a
| store id outside the caller's circle.
*/

function ws28tToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws28tStore(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'WS28T Store '.Str::upper(Str::random(4)),
        'slug' => 'ws28t-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws28tOrder(Store $store, array $attributes = []): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_number' => 'WS28T-ORD-'.$sequence,
        'subtotal' => 2500,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 2500,
        'amount_paid' => 2500,
        'status' => OrderStatus::PENDING->value,
    ], $attributes));
}

function ws28tTransaction(Order $order, float $amount, array $attributes = []): Transaction
{
    static $sequence = 0;
    $sequence++;

    return Transaction::create(array_merge([
        'reference' => 'WS28T-TXN-'.$sequence,
        'order_id' => $order->id,
        'business_id' => $order->business_id,
        'amount' => $amount,
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ws28tProduct(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'WS28T Product '.Str::upper(Str::random(4)),
        'amount' => 1000,
        'quantity' => 50,
        'status' => 'active',
    ], $attributes));
}

/** Move a row's timestamps to a given instant — the buckets read `created_at`. */
function ws28tAt($model, Carbon $at)
{
    $model->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

    return $model;
}

/** The first day inside a 7-day window, at its start. */
function ws28tWindowStart(int $days = 7): Carbon
{
    return Carbon::now()->subDays($days - 1)->startOfDay();
}

test('the series carries one point per day, filling the days nothing happened', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28tStore($owner, $business);
    $start = ws28tWindowStart();

    // Two of the seven days traded; five did not. The order and its
    // transaction are both moved — they are bucketed separately, and an order
    // left on today would put a second point on the wrong day.
    $first = $start->copy()->addDay()->setTime(10, 0);
    $second = $start->copy()->addDays(4)->setTime(15, 0);

    ws28tAt(ws28tTransaction(ws28tAt(ws28tOrder($store), $first), 3000), $first);
    ws28tAt(ws28tTransaction(ws28tAt(ws28tOrder($store), $second), 2000), $second);

    $response = $this->withToken(ws28tToken($owner))
        ->getJson('/api/v1/management/dashboard/trend?days=7')
        ->assertOk();

    $series = $response->json('data.series');

    // The gap is the whole point: the aggregate returns two rows, and a chart
    // handed two points draws a straight line through five empty days.
    expect($series)->toHaveCount(7)
        ->and(array_column($series, 'date'))->toBe([
            $start->toDateString(),
            $start->copy()->addDay()->toDateString(),
            $start->copy()->addDays(2)->toDateString(),
            $start->copy()->addDays(3)->toDateString(),
            $start->copy()->addDays(4)->toDateString(),
            $start->copy()->addDays(5)->toDateString(),
            $start->copy()->addDays(6)->toDateString(),
        ])
        // Revenue is a float in the payload, but a whole figure serialises
        // without a decimal point — `0.0` reaches JSON as `0`. The assertion
        // pins what a client actually receives.
        ->and(array_column($series, 'revenue'))->toBe([0, 3000, 0, 0, 2000, 0, 0])
        ->and(array_column($series, 'orders'))->toBe([0, 1, 0, 0, 1, 0, 0]);
});

test('revenue is summed from the confirmed transaction column, in its own units', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28tStore($owner, $business);
    $at = Carbon::now()->subDays(2)->setTime(12, 0);

    ws28tAt(ws28tTransaction(ws28tOrder($store), 1500.50), $at);
    ws28tAt(ws28tTransaction(ws28tOrder($store), 2499.25), $at);

    // Not confirmed, so not revenue — the same rule the revenue card applies.
    ws28tAt(ws28tTransaction(ws28tOrder($store), 9000, ['status' => TransactionStatus::PENDING]), $at);

    $response = $this->withToken(ws28tToken($owner))
        ->getJson('/api/v1/management/dashboard/trend?days=7')
        ->assertOk();

    // A kobo-exact total, so the assertion would catch a hundredfold slip
    // between the decimal naira column and the kobo columns beside it.
    expect($response->json('data.totals.revenue'))->toBe(3999.75);
});

test('the comparison window is the days immediately before, day for day', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28tStore($owner, $business);
    $start = ws28tWindowStart();

    // The last instant of the day before the window opens — inside the
    // comparison window, never inside the one on screen.
    ws28tAt(ws28tTransaction(ws28tOrder($store), 5000), $start->copy()->subSecond());

    // One second later is the first instant of the window itself.
    ws28tAt(ws28tTransaction(ws28tOrder($store), 700), $start->copy()->setTime(0, 0));

    $response = $this->withToken(ws28tToken($owner))
        ->getJson('/api/v1/management/dashboard/trend?days=7')
        ->assertOk();

    expect($response->json('data.totals.revenue'))->toBe(700)
        ->and($response->json('data.previous.revenue'))->toBe(5000);
});

test('each metric counts its own rows over the same window', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28tStore($owner, $business);
    $at = Carbon::now()->subDays(3)->setTime(9, 0);

    ws28tAt(ws28tOrder($store), $at);
    ws28tAt(ws28tOrder($store), $at);
    ws28tAt(ws28tProduct($store), $at);
    ws28tAt(ws28tProduct($store), $at);
    ws28tAt(ws28tProduct($store), $at);

    // Created before the window opens, so counted by neither.
    ws28tAt(ws28tOrder($store), $at->copy()->subDays(20));
    ws28tAt(ws28tProduct($store), $at->copy()->subDays(20));

    $response = $this->withToken(ws28tToken($owner))
        ->getJson('/api/v1/management/dashboard/trend?days=7')
        ->assertOk();

    expect($response->json('data.totals.orders'))->toBe(2)
        ->and($response->json('data.totals.products'))->toBe(3)
        ->and($response->json('data.totals.revenue'))->toBe(0);
});

test('a store selection scopes the series and both comparison figures', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $ikeja = ws28tStore($owner, $business, ['name' => 'Ikeja']);
    $yaba = ws28tStore($owner, $business, ['name' => 'Yaba']);
    $at = Carbon::now()->subDays(2)->setTime(12, 0);

    ws28tAt(ws28tOrder($ikeja), $at);
    ws28tAt(ws28tOrder($yaba), $at);
    ws28tAt(ws28tOrder($yaba), $at);

    $response = $this->withToken(ws28tToken($owner))
        ->getJson('/api/v1/management/dashboard/trend?days=7&store_id='.$ikeja->id)
        ->assertOk();

    // Two days back inside a seven-day window is the fifth point, oldest first.
    expect($response->json('data.totals.orders'))->toBe(1)
        ->and($response->json('data.series.4.orders'))->toBe(1);
});

test('the window is a closed set and an inaccessible store is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws28tStore($owner, $owner->business);
    $theirs = ws28tStore($otherOwner, $otherBusiness);
    $token = ws28tToken($owner);

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/trend?store_id='.$theirs->id)
        ->assertStatus(403);

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/trend?store_id=not-a-number')
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');

    // A free integer would be a request to build the response and run the
    // GROUP BYs over whatever span the query string asks for.
    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/trend?days=45')
        ->assertStatus(422)
        ->assertJsonValidationErrors('days');

    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/trend?days=7&store_id='.$mine->id)
        ->assertOk();

    // Absent means the middle window, which is what the control opens on.
    $this->withToken($token)
        ->getJson('/api/v1/management/dashboard/trend')
        ->assertOk()
        ->assertJsonPath('data.range.days', 30)
        ->assertJsonCount(30, 'data.series');
});

test('a user without the dashboard permission cannot load the trend', function () {
    [, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    $this->withToken(ws28tToken($staff))
        ->getJson('/api/v1/management/dashboard/trend')
        ->assertStatus(403);
});

test('the route gate is not enough on its own — revenue stays behind its own ability', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws28tStore($owner, $business);
    $at = Carbon::now()->subDays(2)->setTime(11, 0);

    // One order, carrying the revenue, so the two metrics the cashier may see
    // and the one they may not all stand over the same single row.
    $order = ws28tOrder($store);
    ws28tAt(ws28tTransaction($order, 4500), $at);
    ws28tAt($order, $at);
    ws28tAt(ws28tProduct($store), $at);

    // A cashier holds `dashboard view` — so the route lets them in — but not
    // `transactions view`, so the widgets payload withholds the revenue card.
    // The series has to withhold it too, or the endpoint hands back in an
    // array what the payload was careful not to.
    $cashier = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $cashier->assignRole('Cashier');

    // Without `transactions view` the user counts as restricted staff and
    // sees only the stores they are assigned to — which is also the reason
    // this class of viewer exists at all.
    $cashier->assignedStores()->attach($store->id);

    $response = $this->withToken(ws28tToken($cashier))
        ->getJson('/api/v1/management/dashboard/trend?days=7')
        ->assertOk();

    expect($response->json('data.totals'))->not->toHaveKey('revenue')
        ->and($response->json('data.previous'))->not->toHaveKey('revenue')
        ->and($response->json('data.series.4'))->not->toHaveKey('revenue')
        // The metrics they may see are still there, so the gate is per metric
        // rather than an all-or-nothing switch on the endpoint.
        ->and($response->json('data.totals.orders'))->toBe(1)
        ->and($response->json('data.totals.products'))->toBe(1);
});

test('a caller permitted none of the metrics gets an empty window, not bare dates', function () {
    // The presenter's own rule, asserted directly: reaching it through HTTP
    // would need a role the seeder does not ship, and the behaviour — no
    // metric, no rows — belongs to the presenter either way.
    expect(TrendSeriesResource::series([], Carbon::now()->startOfDay(), 7))->toBe([])
        ->and(TrendSeriesResource::totals([]))->toBe([]);
});
