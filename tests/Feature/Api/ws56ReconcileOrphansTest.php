<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-56 — the orphan report
|--------------------------------------------------------------------------
| `customers:reconcile-orphans` exists because the 2026_10_15_000003 backfill
| repairs only the customer rows it can *prove* a business for, and reports the
| rest instead of guessing. Everything it cannot prove lands in a bucket, and
| each bucket tells a person what to do next.
|
| That makes the classification the product. A row reported as `collision` when
| it is really `no_orders` sends somebody looking for a merge that does not
| exist; a row reported as `backfillable` when the business already holds that
| email invites the very duplicate the unique index would refuse. So each bucket
| is asserted on its own, and the `conflict` column — the other row an operator
| would merge with — is asserted to name the right account.
|
| The other half of the contract is that this writes nothing. It is a report on
| financial history and identity; a command that "helpfully" fixed a row would
| be moving one person's orders onto another person's account unattended.
*/

function ws56rOwner(string $businessName): array
{
    return createBusinessOwner([], ['name' => $businessName]);
}

function ws56rStore(Business $business): Store
{
    return Store::create([
        'user_id' => $business->user_id,
        'business_id' => $business->id,
        'name' => 'WS56R Store',
        'slug' => 'ws56r-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
    ]);
}

/**
 * A customer with no business — the shape registration used to write.
 */
function ws56rOrphan(array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => null,
        'first_name' => 'Orphan',
        'last_name' => 'Person',
        'email' => 'ws56r-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
    ], $attributes));
}

function ws56rCustomer(Business $business, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $business->id,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ws56r-'.Str::lower(Str::random(10)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => Customer::STATUS_ACTIVE,
        'email_verified_at' => now(),
        'registered_at' => now(),
    ], $attributes));
}

function ws56rOrder(Store $store, Customer $customer): Order
{
    static $sequence = 0;
    $sequence++;

    return Order::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'source' => 'checkout',
        'order_number' => 'WS56R-ORD-'.$sequence,
        'subtotal' => 5000,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 5000,
        'amount_paid' => 0,
        'status' => 'completed',
    ]);
}

/**
 * The report, as data.
 *
 * `--json` rather than scraping the tables: the assertion that matters is which
 * bucket a row landed in, and matching that against ASCII box borders would
 * break the moment a column width changed.
 *
 * @return array{total: int, counts: array<string, int>, buckets: array<string, array<int, array<string, mixed>>>}
 */
function ws56rReport(): array
{
    Artisan::call('customers:reconcile-orphans', ['--json' => true]);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

function ws56rBucketOf(array $report, Customer $customer): ?string
{
    foreach ($report['buckets'] as $bucket => $entries) {
        foreach ($entries as $entry) {
            if ($entry['account_id'] === $customer->account_id) {
                return $bucket;
            }
        }
    }

    return null;
}

test('a row with one business and no clash is reported as backfillable, and left alone', function () {
    [$owner, $business] = ws56rOwner('Kano Supplies');
    $store = ws56rStore($business);

    $orphan = ws56rOrphan();
    ws56rOrder($store, $orphan);

    $report = ws56rReport();

    expect(ws56rBucketOf($report, $orphan))->toBe('backfillable');

    // Read-only: the report exists so a person decides, and the safe repair of
    // this bucket is the migration, not this command.
    expect($orphan->fresh()->business_id)->toBeNull();
});

test('a row whose business already holds that email is reported as a collision naming the other row', function () {
    [$owner, $business] = ws56rOwner('Kano Supplies');
    $store = ws56rStore($business);

    $email = 'ws56r-duplicate@example.test';

    // The person signed up properly after checkout had made the orphan row.
    $claimed = ws56rCustomer($business, ['email' => $email]);
    $orphan = ws56rOrphan(['email' => $email]);
    ws56rOrder($store, $orphan);

    $report = ws56rReport();

    expect(ws56rBucketOf($report, $orphan))->toBe('collision');

    $entry = collect($report['buckets']['collision'])
        ->firstWhere('account_id', $orphan->account_id);

    // The row an operator would merge into. Naming the wrong one would fold a
    // customer into a stranger.
    expect($entry['conflict'])->toContain($claimed->account_id);
});

test('a row whose orders span two businesses is reported with both named', function () {
    [$ownerA, $businessA] = ws56rOwner('Kano Supplies');
    [$ownerB, $businessB] = ws56rOwner('Lagos Lights');

    $orphan = ws56rOrphan();

    ws56rOrder(ws56rStore($businessA), $orphan);
    ws56rOrder(ws56rStore($businessB), $orphan);

    $report = ws56rReport();

    expect(ws56rBucketOf($report, $orphan))->toBe('multi_business');

    $entry = collect($report['buckets']['multi_business'])
        ->firstWhere('account_id', $orphan->account_id);

    // Both names, because the resolution is usually to split the person in two
    // and this is the list of businesses to split them across.
    expect($entry['businesses'])->toContain('Kano Supplies')->toContain('Lagos Lights');
});

test('a row with no orders and a row with no email are each reported as such', function () {
    $orphan = ws56rOrphan();
    $noEmail = ws56rOrphan(['email' => '']);

    $report = ws56rReport();

    expect(ws56rBucketOf($report, $orphan))->toBe('no_orders')
        ->and(ws56rBucketOf($report, $noEmail))->toBe('no_email');
});

test('a customer that already has a business is never reported', function () {
    [$owner, $business] = ws56rOwner('Kano Supplies');
    $store = ws56rStore($business);

    $healthy = ws56rCustomer($business);
    ws56rOrder($store, $healthy);

    $report = ws56rReport();

    expect(ws56rBucketOf($report, $healthy))->toBeNull();
});

test('the report writes nothing and always exits successfully', function () {
    [$owner, $business] = ws56rOwner('Kano Supplies');
    $store = ws56rStore($business);

    $orphan = ws56rOrphan();
    $order = ws56rOrder($store, $orphan);

    $before = [
        'business_id' => $orphan->business_id,
        'email' => $orphan->email,
        'first_name' => $orphan->first_name,
    ];

    // Zero even when rows are reported: a shell reads non-zero as "the step
    // failed", and a deploy that runs this after the migration would stop on
    // the news it ran to get.
    $this->artisan('customers:reconcile-orphans')->assertSuccessful();

    $fresh = $orphan->fresh();

    expect($fresh->business_id)->toBe($before['business_id'])
        ->and($fresh->email)->toBe($before['email'])
        ->and($fresh->first_name)->toBe($before['first_name'])
        ->and(Order::find($order->id))->not->toBeNull();
});
