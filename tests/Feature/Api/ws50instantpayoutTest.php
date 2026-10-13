<?php

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\InstantPayoutService;
use App\Services\Payments\Custody\PayoutHoldService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| WS-50 — the payout that asks, in the same request, whether it may.
|
| Under collect-and-pay-out the money is in the platform's provider balance the
| moment a charge succeeds, and Bachs says so itself: `available_balance` and
| nothing in `pending_balance`. So after a sale is recorded there is nothing left
| to wait for, and the gap between two five-minute cron ticks was the only delay
| in the chain — ours, not the provider's.
|
| These tests are about the three things that make moving it *early* safe rather
| than merely fast:
|
| **The restrictions are read first.** A hold, the business's own switch, a dead
| bank — all decided before a provider is spoken to, so a business somebody
| deliberately stopped costs no provider call at all and cannot be paid by a
| path that forgot to ask.
|
| **The money moves through the run's own code.** Every assertion here would
| hold for `custody:payouts` too; what is new is only *when*. That is why the
| tests reach for the same outcomes the sweep has — a payout row, a debited
| wallet, a promoted sale — rather than for anything instant-specific.
|
| **Nothing here can fail a request.** A provider that refuses, a balance that
| cannot be read, a spendable balance that does not cover the sale: every one of
| them leaves the sale payable and lets the run pay it later. The webhook has
| already recorded a real payment by the time this runs, and turning a slow
| provider into a failed webhook would have it redelivered.
*/

function ip50Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function ip50Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
        'services.bachs.connect_webhook_secret' => null,
    ]);
}

/**
 * A custody business with a bank and two stores, so one can be held while the
 * other pays.
 *
 * @return array{0: Business, 1: Store, 2: Store}
 */
function ip50Business(bool $autoPayout = true, bool $withBank = true): array
{
    [$owner, $business] = createBusinessOwner([], ['auto_payout' => $autoPayout]);

    $bank = $withBank ? StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
    ]) : null;

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
        'payout_bank_id' => $bank?->id,
    ])->save();

    $stores = [];

    foreach ([1, 2] as $index) {
        $stores[] = Store::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'name' => 'Store '.$index.' '.fake()->unique()->numerify('####'),
            'slug' => 'ip50-'.$index.'-'.fake()->unique()->numerify('######'),
            'status' => Store::STATUS_ACTIVE,
            'payment_mode' => 'manual',
        ]);
    }

    return [$business->fresh(), $stores[0], $stores[1]];
}

/**
 * A confirmed sale whose share is exactly `$kobo`.
 *
 * Zero fees, so the net is the gross and every figure asserted below is the
 * number the test itself chose. `fee_kobo` is **0 and not null** deliberately:
 * null is how a sale whose provider has not reported a fee yet is recorded, and
 * such a sale is not credited at all — see the test that pins it.
 */
function ip50Sale(Business $business, int $kobo, ?Store $store = null, string $mode = 'platform_managed'): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'IP50-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'API_IP50_'.strtoupper(Str::random(10)),
        'business_id' => $business->id,
        'order_id' => $order?->id,
        'amount' => $kobo / 100,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        'metadata' => ['payment_mode' => $mode, 'leg_method' => 'bachs'],
    ]);
}

/**
 * Credit a sale to the wallet and then offer to pay it — the whole new path.
 */
function ip50Collect(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = ip50Sale($business, $kobo, $store);

    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    app(InstantPayoutService::class)->afterCollection($sale);

    return $sale->fresh();
}

/**
 * A plate of fakes for everything the provider is asked.
 *
 * `$availableKobo` is what the platform balance is reported to hold, and it is
 * the interesting dial in half of these tests: the gate matures only what that
 * answer covers.
 */
function ip50Provider(int $availableKobo = 50000000, ?int $balanceStatus = null): void
{
    $balance = $balanceStatus !== null
        ? Http::response(['message' => 'Bad gateway'], $balanceStatus)
        : Http::response([
            'account_id' => 'org_ip50',
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => number_format($availableKobo / 100, 2, '.', ''),
                'pending_balance' => '0.00',
            ]],
            'pending_settlements_by_day' => [],
        ]);

    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => $balance,
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
}

/**
 * The body of the one request the provider received at `$path`, or [].
 *
 * `$exact` compares the whole path instead of looking for it inside one, which
 * the transfer lookup needs: `/v1/payouts` is a prefix of `/v1/payouts/id`, and
 * of the destinations call as well, so the loose form would hand back the
 * response to a different request.
 */
function ip50Sent(string $path, bool $exact = false): array
{
    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent, $path, $exact) {
        $url = $request->url();

        $matches = $exact
            ? str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', $path)
            : str_contains($url, $path);

        if (! $matches) {
            return false;
        }

        $sent ??= $request->data();

        return true;
    });

    return $sent ?? [];
}

/** @return Collection<int, Payout> */
function ip50Payouts(?Business $business = null): Collection
{
    return Payout::query()
        ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
        ->orderBy('id')
        ->get();
}

/* -------------------------------------------------- the sale pays itself out */

test('a collected sale is paid out in the request that collects it', function () {
    // The whole point, in one assertion set: no promote run, no payout run, no
    // waiting for a tick. One call records the sale, matures it and sends it.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    ip50Provider();

    $sale = ip50Collect($business, 100000, $store);

    $payout = ip50Payouts($business)->sole();
    $business->refresh();

    expect($payout->amount_kobo)->toBe(100000)
        ->and($payout->status)->toBe(Payout::STATUS_PROCESSING)
        // Nothing is left owed and nothing is left payable: the wallet is empty
        // because the money is either with the business or on its way.
        ->and((int) $business->pending_balance)->toBe(0)
        ->and((int) $business->available_balance)->toBe(0)
        ->and($sale->promoted_at)->not->toBeNull()
        // And the ledger of which sale the money came from was written in the
        // same move, so a later refund knows what to unwind.
        ->and($payout->allocations()->pluck('transaction_id')->all())->toBe([(int) $sale->id]);
});

test('the payout is sent to the business\'s own bank account', function () {
    // "Send the payout request back to the provider with the business's account
    // details" is the requirement, and this is the only place it can be checked:
    // the account is registered at the provider under the platform's own
    // organisation, with no account header, and the transfer is drawn on the
    // platform's balance.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    ip50Provider();

    ip50Collect($business, 100000, $store);

    $destination = ip50Sent('/v1/payouts/destinations');
    $transfer = ip50Sent('/v1/payouts', exact: true);

    expect($destination['account_number'])->toBe('0123456789')
        ->and($destination['bank_code'])->toBe('058')
        ->and($destination['account_name'])->toBe('Ada Trading Ltd')
        ->and($destination['currency'])->toBe('NGN')
        // Naira as a decimal string, which is what Bachs takes — kobo would send
        // a hundred times the money.
        ->and($transfer['amount'])->toBe('1000.00')
        ->and($transfer['destination'])->toBe('pd_new');
});

/**
 * A platform-managed sale waiting on its provider, as checkout leaves it.
 */
function ip50PendingSale(Business $business, Store $store, string $reference): Transaction
{
    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'IP50-'.$reference,
        'subtotal' => 1000,
        'total' => 1000,
        'status' => 'pending',
    ]);

    return Transaction::create([
        'reference' => $reference,
        'business_id' => $business->id,
        'order_id' => $order->id,
        'amount' => 1000,
        'currency' => 'NGN',
        'status' => TransactionStatus::PENDING,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ]);
}

/**
 * Everything the delivery makes the server ask Bachs for.
 */
function ip50WebhookFakes(string $reference): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => '500000.00',
                'pending_balance' => '0.00',
            ]],
        ]),
        'sandbox-api.bachs.io/v1/checkout-sessions/*' => Http::response([
            'checkout_id' => $reference,
            'status' => 'completed',
            'charge' => [
                'id' => 'chg_ip50',
                'status' => 'succeeded',
                'amount' => '1000.00',
                'currency' => 'NGN',
                // Priced at zero rather than left absent, and that distinction is
                // the whole reason the wallet credits this sale: a charge with no
                // fee reported is recorded as awaiting one and is not credited.
                'fees' => ['amount' => '0.00', 'currency' => 'NGN'],
            ],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
}

/**
 * Deliver a signed `collection.succeeded` for `$reference`, and return the response.
 *
 * Posted to `/webhooks/connect/bachs` and not to the merchant URL, and that is
 * the whole point rather than a detail: this delivery is about a charge
 * **Storify** made, signed with Storify's own secret, and it names the platform's
 * account. The merchant endpoint resolves `{scope}` by finding a *business's*
 * stored secret that verifies the signature, and a business under custody has no
 * gateway connection at all — so a platform delivery sent there fails to verify
 * against every candidate and is answered 401 forever.
 */
function ip50Deliver(string $reference, string $eventId = 'evt_ip50', string $secret = 'whsec_platform')
{
    $body = json_encode([
        'id' => $eventId,
        'type' => 'collection.succeeded',
        'data' => ['checkout_id' => $reference, 'reference' => $reference, 'status' => 'SUCCESS'],
    ]);

    $timestamp = time();

    // The raw string is what is signed, so the raw string is what is posted:
    // re-encoding the array would change spacing and break the signature over a
    // body that is otherwise identical.
    return test()->call('POST', '/webhooks/connect/bachs', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_BACHS_SIGNATURE_V2' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ], $body);
}

test('the webhook pays the business out in the request that settles the sale', function () {
    // The sequence asked for, literally: the provider's delivery of a successful
    // payment is what sends the payout, in the same request, with no run and no
    // five-minute tick in between. Nothing had touched this row beforehand — no
    // return leg, no credit, no promotion.
    ip50Platform();
    ip50Settings([
        'system_payment_processor' => 'bachs',
        'platform_commission_percent' => 0,
        'platform_commission_fixed_kobo' => 0,
    ]);

    [$business, $store] = ip50Business();

    $reference = 'API_IP50_HOOK_A';
    $transaction = ip50PendingSale($business, $store, $reference);

    ip50WebhookFakes($reference);

    ip50Deliver($reference, 'evt_ip50_a')->assertOk()->assertJsonPath('status', 'success');

    expect($transaction->fresh()->status)->toBe(TransactionStatus::CONFIRMED);

    $payout = ip50Payouts($business)->sole();
    $business->refresh();

    expect($payout->amount_kobo)->toBe(100000)
        ->and($payout->status)->toBe(Payout::STATUS_PROCESSING)
        ->and($payout->allocations()->pluck('transaction_id')->all())->toBe([(int) $transaction->id])
        ->and((int) $business->pending_balance)->toBe(0)
        ->and((int) $business->available_balance)->toBe(0);

    // Sent with the business's own account, from the platform's own balance.
    expect(ip50Sent('/v1/payouts/destinations')['account_number'])->toBe('0123456789');
});

test('the webhook does not pay a second time for a sale the return leg already paid', function () {
    // The other ordering, and the reason the two settlement paths cannot both
    // pay: a delivery arriving after the browser got back finds the row already
    // `CONFIRMED` and stops at that guard, which is *above* every line that
    // moves money. Without it the customer's return trip and the provider's
    // notification — two notifications of one payment — would each pay.
    ip50Platform();
    ip50Settings([
        'system_payment_processor' => 'bachs',
        'platform_commission_percent' => 0,
        'platform_commission_fixed_kobo' => 0,
    ]);

    [$business, $store] = ip50Business();

    $reference = 'API_IP50_HOOK_B';
    $transaction = ip50PendingSale($business, $store, $reference);

    ip50WebhookFakes($reference);

    // What the return leg leaves behind: confirmed, priced, credited, promoted
    // and paid — all before the delivery arrives.
    $transaction->forceFill([
        'status' => TransactionStatus::CONFIRMED->value,
        'paid_at' => now(),
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
    ])->save();

    app(CustodyService::class)->recordCollectedSale($transaction, 'bachs');
    app(InstantPayoutService::class)->afterCollection($transaction->fresh());

    expect(ip50Payouts($business))->toHaveCount(1);

    ip50Deliver($reference, 'evt_ip50_b')->assertOk();

    expect(ip50Payouts($business))->toHaveCount(1)
        ->and($business->fresh()->available_balance)->toBe(0)
        ->and($business->fresh()->pending_balance)->toBe(0);

    // And no second transfer left for Bachs.
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/payouts')))->toHaveCount(1);
});

/* ------------------------------------------------------------- the restrictions */

test('a system hold withholds the payout and leaves the money in the wallet', function () {
    // The central override. Nothing is sent, nothing is attempted at the
    // provider, and the money stays visibly owed — which is what makes lifting
    // the hold a no-op rather than a repair.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    app(PayoutHoldService::class)->holdSystem('Provider incident, pausing all payouts');

    ip50Provider();

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('withheld')
        ->and($result['reason'])->toBe('hold')
        ->and(ip50Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->available_balance)->toBe(100000);

    // Not merely unpaid: the provider was never asked. A hold has to cost no
    // provider call, or "we stopped this" would still be spending on it.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payouts'));
});

test('a business hold withholds it, and names the hold in what it reports', function () {
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    $hold = app(PayoutHoldService::class)->holdBusiness($business, 'KYC under review');

    ip50Provider();

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('withheld')
        ->and($result['reason'])->toBe('hold')
        ->and(ip50Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->available_balance)->toBe(100000)
        // The reason travels with the decision, so the business's own screen and
        // the run's report say the same thing about why its money is not moving.
        ->and(app(PayoutSweepService::class)->payoutBlocker($business->fresh())['code'])
        ->toBe('hold')
        ->and($hold->fresh()->released_at)->toBeNull();
});

test('the business\'s own switch being off withholds it', function () {
    // The one control that already existed and meant "let the money rest in the
    // wallet". Beside a hold rather than replaced by it: this is the business's
    // preference, and it must keep working for a business whose owner set it and
    // has no idea an operator-side hold exists.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business(autoPayout: false);

    ip50Provider();

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('withheld')
        ->and($result['reason'])->toBe('auto_payout_off')
        ->and(ip50Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->available_balance)->toBe(100000);
});

test('a business with no bank on file is owed the money and not paid', function () {
    // The sale is still collected, still credited, still matured — a missing bank
    // must not stop a store selling or a wallet accruing. It only stops the send.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business(withBank: false);

    ip50Provider();

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('withheld')
        ->and($result['reason'])->toBe('no_bank')
        ->and(ip50Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->available_balance)->toBe(100000)
        // Matured even so, because a missing bank changes where money can go and
        // not what is owed. That is what makes the bank arriving a fix rather
        // than a repair: the next run finds the balance already waiting.
        ->and($sale->fresh()->promoted_at)->not->toBeNull();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payouts'));
});

test('a store hold withholds that store\'s share and pays the rest at once', function () {
    // A dispute about one store's orders is not a business-wide outage. The
    // withheld share is expressed by the allocation walk skipping that store's
    // sales, so the amount sent can never exceed what the business actually has.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $open, $disputed] = ip50Business();

    $kept = ip50Sale($business, 100000, $open);
    $held = ip50Sale($business, 60000, $disputed);

    app(CustodyService::class)->recordCollectedSale($kept, 'bachs');
    app(CustodyService::class)->recordCollectedSale($held, 'bachs');

    app(PayoutHoldService::class)->holdStore($disputed, 'Chargeback under investigation');

    ip50Provider();

    // The second sale's own collection is the request that pays: by then the
    // business is holding both sales, and the hold narrows rather than blocks.
    $result = app(InstantPayoutService::class)->afterCollection($held->fresh());

    $payout = ip50Payouts($business)->sole();

    expect($result['status'])->toBe('attempted')
        ->and($payout->amount_kobo)->toBe(100000)
        ->and($payout->allocations()->pluck('transaction_id')->all())->toBe([(int) $kept->id])
        ->and((int) $business->fresh()->available_balance)->toBe(60000);
});

test('the switch turns it off without a deploy', function () {
    // The off state has to be exactly the old behaviour: the sale is credited and
    // the run pays it on its own schedule. Asserted by the absence of any
    // provider call at all, because "off" that still reached Bachs would spend on
    // the thing it was turned off to stop.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    config(['custody.instant_payout.enabled' => false]);

    [$business, $store] = ip50Business();

    ip50Provider();

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('disabled')
        ->and(ip50Payouts($business))->toHaveCount(0)
        // Still owed, still uncounted as payable — the run will find it.
        ->and((int) $business->fresh()->pending_balance)->toBe(100000)
        ->and((int) $business->fresh()->available_balance)->toBe(0);

    Http::assertNothingSent();
});

/* ------------------------------------------------- when there is nothing to pay */

test('a sale that was never collected is left alone', function () {
    // Twice over: a sale on the business's own keys is not Storify's money to
    // move, and a managed sale whose provider has not reported a fee yet is not
    // credited at all. Neither may produce a payout, and neither may cost a
    // provider call.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    ip50Provider();

    $ownKeys = ip50Sale($business, 100000, $store, 'own_keys');

    expect(app(InstantPayoutService::class)->afterCollection($ownKeys))
        ->toBe(['status' => 'nothing_credited']);

    $unpriced = ip50Sale($business, 100000, $store);
    $unpriced->forceFill(['fee_kobo' => null])->save();

    // The honest wait: no fee reported, so no credit, so nothing to pay.
    expect(app(CustodyService::class)->recordCollectedSale($unpriced->fresh(), 'bachs'))->toBeFalse();

    expect(app(InstantPayoutService::class)->afterCollection($unpriced->fresh()))
        ->toBe(['status' => 'nothing_credited']);

    expect(ip50Payouts($business))->toHaveCount(0);
    Http::assertNothingSent();
});

test('a sale that was paid and then taken back is not paid out', function () {
    // Credited and then unwound — a refund or a chargeback. `unwound_at` is what
    // the promote run excludes on, and the instant path has to agree with it or
    // the two would disagree about what is owed.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    ip50Provider();

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $sale->forceFill(['unwound_at' => now()])->save();

    expect(app(InstantPayoutService::class)->afterCollection($sale->fresh()))
        ->toBe(['status' => 'unwound']);

    expect(ip50Payouts($business))->toHaveCount(0);
    Http::assertNothingSent();
});

/* --------------------------------------------------------- failing safe and slow */

test('an unreadable balance falls back to the settlement window, not to paying blind', function () {
    // The one case the measurement cannot cover. The window is nothing for Bachs
    // because the balance is a better answer, so a balance that cannot be read
    // must not leave the window as no gate at all — it falls back to
    // `custody.default_hours`, and the sale waits there like any other.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    ip50Provider(balanceStatus: 500);

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('attempted')
        ->and($result['matured'])->toBe(0)
        ->and($result['outcome'])->toBeNull()
        ->and(ip50Payouts($business))->toHaveCount(0)
        // Still owed, not yet payable — and it will mature on its own once the
        // window passes, with nothing to repair.
        ->and((int) $business->fresh()->pending_balance)->toBe(100000)
        ->and((int) $business->fresh()->available_balance)->toBe(0);

    // The balance was asked for; the transfer was not.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payouts'));
});

test('a spendable balance that cannot cover the sale promotes nothing', function () {
    // A real zero is not an unreadable balance. The provider has said it holds
    // nothing, so nothing is matured — this is the direction that must fail
    // *closed*, because the alternative is paying out money the platform does not
    // have and the provider refusing every transfer until commission accrues.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    ip50Provider(availableKobo: 0);

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('attempted')
        ->and($result['matured'])->toBe(0)
        ->and(ip50Payouts($business))->toHaveCount(0)
        ->and((int) $business->fresh()->pending_balance)->toBe(100000)
        ->and((int) $business->fresh()->available_balance)->toBe(0);
});

test('a provider that refuses the payout leaves the sale payable for the run', function () {
    // The send is the one thing that can genuinely fail here, and it must not
    // take the settlement down with it. The reservation is released, the money is
    // back in the wallet and owed, and the next run sends it — rather than a 500
    // making the provider redeliver a payment we have already recorded.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    Http::fake([
        'sandbox-api.bachs.io/v1/balances' => Http::response([
            'balances' => [[
                'currency' => 'NGN',
                'available_balance' => '500000.00',
                'pending_balance' => '0.00',
            ]],
        ]),
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['message' => 'Transfer declined'], 422),
    ]);

    $sale = ip50Sale($business, 100000, $store);
    app(CustodyService::class)->recordCollectedSale($sale, 'bachs');

    $result = app(InstantPayoutService::class)->afterCollection($sale->fresh());

    expect($result['status'])->toBe('attempted')
        ->and($result['outcome'])->toBe('failed')
        ->and(ip50Payouts($business)->sole()->status)->toBe(Payout::STATUS_FAILED)
        // Back in the wallet, and owed: the run can still pay it.
        ->and((int) $business->fresh()->available_balance)->toBe(100000);
});

test('asking twice does not pay twice', function () {
    // The webhook and the return leg both reach this for one sale, and the
    // provider retries a webhook three times. `settle_at` is the credit's own
    // proof, so the second caller finds the wallet already credited, nothing
    // payable, and sends nothing.
    ip50Platform();
    ip50Settings(['system_payment_processor' => 'bachs']);

    [$business, $store] = ip50Business();

    ip50Provider();

    $sale = ip50Collect($business, 100000, $store);

    $second = app(InstantPayoutService::class)->afterCollection($sale);

    expect($second['status'])->toBe('attempted')
        ->and($second['matured'])->toBe(0)
        ->and($second['outcome'])->toBeNull()
        ->and(ip50Payouts($business))->toHaveCount(1)
        ->and(ip50Payouts($business)->sole()->amount_kobo)->toBe(100000);

    // One transfer, not two.
    $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/payouts'));

    expect($sent)->toHaveCount(1);
});
