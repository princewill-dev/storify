<?php

namespace App\Services\Payments\Custody;

use App\Models\Business;
use App\Models\Payout;
use App\Models\PayoutHold;
use App\Models\Store;
use App\Support\Money\Naira;
use Illuminate\Support\Collection;

/**
 * What a business's money looks like, in one shape, for every screen that shows it.
 *
 * Before this there was no business-facing answer at all. `businesses.pending_balance`
 * and `available_balance` were exposed in exactly one place — a reconciliation block
 * inside the accounting-integrity JSON, which is an auditor's screen rather than a
 * business's — while every store header, store dashboard and transaction detail
 * rendered `stores.balance`, a legacy per-store integer that a managed sale **never**
 * credits. So a business under custody was shown ₦0.00 by every screen it looked at
 * while its real money sat in the wallet.
 *
 * ## It is a read model, and it computes nothing the send does not
 *
 * Every figure here is either a column on `businesses` or the send's own arithmetic
 * ({@see PayoutSweepService::payableFor()}) read out loud. That is deliberate: the
 * number a business sees as "what will be sent to my bank" has to be the number the
 * send will actually move, or the dashboard becomes a second, wrong source of truth
 * about money. The per-store breakdown is the same walk, grouped — which is also why
 * it can never sum to more than the payable figure it is drawn from.
 *
 * The one figure that is not "what is waiting" is `in_flight`, and it is here because
 * the send's arithmetic alone cannot describe a wallet. A payout debits
 * `available_balance` before the provider is called, so mid-transfer both `payable` and
 * `available` read zero while real money is moving; a payload built only from those two
 * tells a business its wallet is empty at the exact moment it should say a transfer is
 * on its way. See {@see self::inFlightPayload()}.
 *
 * ## Why `balance_source` exists
 *
 * Whether a business's money lives in the wallet or in `stores.balance` is a property
 * of *how it was charged*, not of which screen is asking: a business charged on its own
 * provider account is settled by the provider into its own bank and its per-store
 * balance is real; one charged on Storify's account has money in the wallet instead and
 * its store balances will stay at zero forever. A client that renders one figure for
 * both is wrong for one of them, and cannot tell which. So the payload says which.
 */
final class WalletSummaryService
{
    /** The money is in `businesses.pending_balance` / `available_balance`. */
    public const SOURCE_WALLET = 'wallet';

    /** The money is in `stores.balance`, credited by the provider's own settlement. */
    public const SOURCE_STORE = 'store';

    /**
     * The money is in `stores.pending_balance` / `available_balance` — this
     * store's own custody wallet.
     *
     * A third answer because there are three places money can be, now that a
     * store can hold its own takings as well as having the own-gateway balance
     * `store` names. Reading `store` for a custody store would draw the money
     * the business already has at its own provider; reading `wallet` would draw
     * the business's, which under this regime holds only what no store accounts
     * for.
     */
    public const SOURCE_STORE_WALLET = 'store_wallet';

    /**
     * How many in-flight transfers the operator queue lists.
     *
     * One default sweep run's worth, so the cap bites only when something is actually
     * wrong. The totals beside it are never capped — a truncated list with a truncated
     * total would understate an incident by exactly the amount that made it one.
     */
    private const QUEUE_IN_FLIGHT_LIMIT = 100;

    public function __construct(
        private readonly PayoutSweepService $sweep,
        private readonly PayoutHoldService $holds,
        private readonly PayoutAllocationService $allocations,
    ) {}

    /**
     * Where this business's money is kept, for a client choosing which figure to draw.
     *
     * `custody_enabled` and not "has a wallet balance": a business that has just been
     * switched over has an empty wallet and store balances that are now frozen at
     * whatever they were, and the truth about it is the flag, not the numbers. The flag
     * is also the only answer that is stable between a sale being charged and its share
     * appearing in the wallet a few minutes later.
     */
    public function balanceSource(Business $business): string
    {
        return self::sourceFor($business);
    }

    /**
     * The same answer, for a caller that has a store and not this service.
     *
     * A store row carries its business, and the console's store directory is a
     * cross-tenant page: computing a wallet block per row would walk twenty
     * businesses' sale histories to draw one column, so those rows send
     * `balance_source` and no `wallet` — which means the flag cannot come from a
     * block that is not there. Static, so a resource can ask without the service
     * and its three dependencies being constructed to answer a boolean.
     *
     * A store with no business at all — an individual listing — is
     * {@see self::SOURCE_STORE}: it has no wallet to be paid from.
     */
    public static function sourceFor(?Business $business): string
    {
        return $business !== null && (bool) $business->custody_enabled
            ? self::SOURCE_WALLET
            : self::SOURCE_STORE;
    }

    /**
     * The same question, asked about a *store*.
     *
     * Diverges from {@see sourceFor()} only once stores hold their own wallets.
     * Before that a store of a custody business is paid from the business's
     * wallet and the two answers are the same; after it, the store's money is on
     * the store and pointing the screen at the business would show it a figure
     * that is not its own.
     *
     * Kept as a separate method rather than folded into `sourceFor()` because
     * the *business's* wallet block is still `wallet` under the flag — the two
     * callers genuinely want different answers, and one method returning both
     * would have to be told which caller it had.
     */
    public static function sourceForStore(?Business $business): string
    {
        if ($business === null || ! (bool) $business->custody_enabled) {
            return self::SOURCE_STORE;
        }

        return config('custody.per_store_wallets', false)
            ? self::SOURCE_STORE_WALLET
            : self::SOURCE_WALLET;
    }

    /**
     * The wallet block, plus what is payable, what is withheld and why.
     *
     * @return array<string, mixed>
     */
    public function forBusiness(Business $business): array
    {
        $currency = (string) config('custody.currency', 'NGN');

        $pending = (int) $business->pending_balance;
        $available = (int) $business->available_balance;

        $payable = $this->sweep->payableFor($business);

        $hold = $this->holds->holdOn($business);
        $blocker = $this->sweep->payoutBlocker($business);

        return [
            'currency' => $currency,
            'balance_source' => $this->balanceSource($business),

            // The wallet's two halves, as amounts and in kobo. Both are sent because
            // the SPA formats the amount and the client-side maths for "adding a sale
            // to what is on screen" wants the integer — a float that has been through
            // a currency formatter is not a number to add to.
            'pending' => Naira::floatFromKobo($pending),
            'pending_kobo' => $pending,
            'available' => Naira::floatFromKobo($available),
            'available_kobo' => $available,
            'balance' => Naira::floatFromKobo($pending + $available),
            'balance_kobo' => $pending + $available,

            // **What would actually go out**, which is the walk's number and not the
            // available balance: a store hold withholds that store's sales, and a
            // wallet with a refund deficit behind it is worth less than the sales that
            // appear to be behind it. The two are equal in the ordinary case, and where
            // they differ this is the one that is true.
            //
            // Note what is *not* in it: money already inside a transfer. That has left
            // the wallet, so it is absent here and present in `in_flight` instead, and a
            // screen that shows only this figure says ₦0 during a send.
            'payable' => Naira::floatFromKobo($payable['payable_kobo']),
            'payable_kobo' => $payable['payable_kobo'],

            // Held back on purpose, and the figure that makes a hold legible on a
            // business's own screen: money it can see, that is not being sent, with a
            // reason attached.
            'withheld' => Naira::floatFromKobo($payable['withheld_kobo']),
            'withheld_kobo' => $payable['withheld_kobo'],
            // Money no sale accounts for — a hand-adjusted balance. Sent because it
            // is the one figure an operator reconciling the wallet needs and cannot
            // derive, and it is indistinguishable from sale-backed money otherwise.
            'unattributed' => Naira::floatFromKobo($payable['unattributed_kobo']),
            'unattributed_kobo' => $payable['unattributed_kobo'],

            // Money that has already left the wallet and is not yet known to have
            // arrived. The third state a wallet can be in, and the one the payload was
            // missing: `available` is money we are holding, `in_flight` is money the
            // bank has been asked for, and `pending` is money the provider has not
            // settled to us yet.
            'in_flight' => $this->inFlightPayload($business),

            'custody_enabled' => (bool) $business->custody_enabled,
            'auto_payout' => (bool) $business->auto_payout,

            // Why the money is not on its way, in the words the run's own report
            // uses. Null means nothing is stopping it and the payable figure will go
            // out — which is the answer a business is actually after, and is a
            // different answer from "there is nothing to send".
            'blocker' => $blocker,
            'hold' => $hold instanceof PayoutHold ? $this->holdPayload($hold) : null,

            'stores' => $this->storeBreakdown(
                $business,
                $payable['plan'],
                $this->withheldByStore($business, $payable['plan']),
            ),
        ];
    }

    /**
     * A compact wallet line for one store, keyed by store id.
     *
     * Shipped alongside `balance` rather than replacing it: a business charged on its
     * own account has real money in `stores.balance` and no wallet to speak of, and
     * deleting the field would blank the figure on its screens. `balance_source` is
     * what tells a client which of the two to render, and both are always present so
     * a client can switch between them without a second request.
     *
     * **One walk for every store**, computed here rather than per store: the figure is
     * a slice of the business's payable plan, and a store list that ran the walk once
     * per row would read a business's whole sale history twenty times to draw twenty
     * numbers. The caller with a list of stores passes the business once.
     *
     * @return array<int, array<string, mixed>> keyed by store id
     */
    public function storeBlocks(Business $business): array
    {
        $currency = (string) config('custody.currency', 'NGN');
        $payable = $this->sweep->payableFor($business);

        $holds = $this->holds->storeHolds($business)->keyBy('store_id');

        $attributed = $this->attributeByStore($payable['plan']);
        $withheld = $this->withheldByStore($business, $payable['plan']);

        return Store::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->mapWithKeys(function (Store $store) use ($attributed, $withheld, $holds, $business, $currency) {
                $id = (int) $store->id;
                $hold = $holds->get($id);
                $share = (int) ($attributed[$id] ?? 0);
                $held = $hold instanceof PayoutHold;

                return [$id => [
                    'store_id' => $id,
                    'store_name' => $store->name,
                    'currency' => $currency,
                    'balance_source' => $this->balanceSource($business),
                    // This store's share of what the send would move — the walk's
                    // own rows, filtered to it. Zero for a store with no matured sales,
                    // which is a different statement from "this store earned nothing";
                    // a held store's zero means its money is in `withheld_kobo` beside
                    // it, and the two keys are read together or not at all.
                    'attributed' => Naira::floatFromKobo($share),
                    'attributed_kobo' => $share,
                    // Earned, owed, and deliberately not on its way.
                    'withheld' => Naira::floatFromKobo((int) ($withheld[$id] ?? 0)),
                    'withheld_kobo' => (int) ($withheld[$id] ?? 0),
                    // The business's whole wallet, so a store screen can show the money
                    // this store is part of without a second call.
                    'business_pending_kobo' => (int) $business->pending_balance,
                    'business_available_kobo' => (int) $business->available_balance,
                    'held' => $held,
                    'hold_reason' => $held ? $hold->reason : null,
                ]];
            })
            ->all();
    }

    /**
     * What each held store earned and is not being sent, keyed by store id.
     *
     * The same FIFO walk run **without** the exclusions, minus the exclusions' own
     * result: a held store's share of what would have gone. Only walked when
     * something is actually held, because it is a second pass over the business's
     * matured sales and the ordinary case has nothing to compare against.
     *
     * Without it a held store's block reads `attributed: 0` beside its hold reason,
     * which is indistinguishable from a store that has simply sold nothing — and
     * the money it is being kept from would be visible nowhere on its own screen.
     *
     * Capped at zero per store: the held store's share can only be *added back* by
     * lifting the hold, never subtracted, so a negative here would mean the two
     * walks disagreed about the same sales rather than that a store owed anything.
     *
     * @param  Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>  $plan
     * @return Collection<int, int> keyed by store id
     */
    private function withheldByStore(Business $business, Collection $plan): Collection
    {
        if ($this->holds->heldStoreIds($business) === []) {
            return collect();
        }

        $attributed = $this->attributeByStore($plan);

        return $this->attributeByStore(
            $this->allocations->plan($business, $business->payoutableBalance()),
        )->map(fn (int $full, int $storeId) => max(0, $full - (int) ($attributed[$storeId] ?? 0)));
    }

    /**
     * A plan, grouped into "how much of this is store X's".
     *
     * The one join between a sale and a store, shared by the payable breakdown and
     * the withheld one so the two cannot group differently. Lines with no store —
     * a POS-managed leg, or a sale taken before multi-store existed — are dropped
     * rather than bucketed: they are real money and they are in the wallet total,
     * but attributing them to a store would be inventing a fact.
     *
     * @param  Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>  $plan
     * @return Collection<int, int> keyed by store id
     */
    private function attributeByStore(Collection $plan): Collection
    {
        return $plan
            ->filter(fn (array $line) => $line['store_id'] !== null)
            ->groupBy('store_id')
            ->map(fn (Collection $lines) => (int) $lines->sum('amount_kobo'));
    }

    /**
     * The same block for one store — one walk, for a screen showing one store.
     *
     * A store that is not listed is not this business's, which cannot happen through
     * a route scoped by `authorizeStore()`; the default is the honest answer for a
     * store with nothing attributed rather than a second reason to differ.
     *
     * @return array<string, mixed>
     */
    public function storeWalletBlock(Business $business, Store $store): array
    {
        // Under per-store wallets the store's own columns are where its money is,
        // so the block reports those. The two keys keep their names: a caller
        // reads them as "the figures for the scope `balance_source` names", which
        // is what they have always been — and renaming them would break every
        // client for a change of meaning they do not have to make.
        if (config('custody.per_store_wallets', false) && $business->custody_enabled) {
            return [
                'store_id' => (int) $store->id,
                'store_name' => $store->name,
                'currency' => (string) config('custody.currency', 'NGN'),
                'balance_source' => self::SOURCE_STORE_WALLET,
                'attributed' => 0.0,
                'attributed_kobo' => 0,
                'withheld' => 0.0,
                'withheld_kobo' => 0,
                'business_pending_kobo' => (int) $store->pending_balance,
                'business_available_kobo' => (int) $store->available_balance,
                'held' => false,
                'hold_reason' => null,
            ];
        }

        $blocks = $this->storeBlocks($business);

        return $blocks[(int) $store->id] ?? [
            'store_id' => (int) $store->id,
            'store_name' => $store->name,
            'currency' => (string) config('custody.currency', 'NGN'),
            'balance_source' => $this->balanceSource($business),
            'attributed' => 0.0,
            'attributed_kobo' => 0,
            'withheld' => 0.0,
            'withheld_kobo' => 0,
            'business_pending_kobo' => (int) $business->pending_balance,
            'business_available_kobo' => (int) $business->available_balance,
            'held' => false,
            'hold_reason' => null,
        ];
    }

    /**
     * Each store's share of what is payable, plus its hold if it has one.
     *
     * Built from the plan the send would move, not from a second walk with no cap:
     * a store's share of a wallet that cannot cover all its sales is not the sum of
     * its sales, and a figure computed the other way would not add up to the number
     * the business is told it is about to receive. A store with nothing attributed
     * is still listed — "this store has earned nothing payable" is information.
     *
     * @param  Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>  $plan
     * @param  Collection<int, int>  $withheld  keyed by store id
     * @return array<int, array<string, mixed>>
     */
    private function storeBreakdown(Business $business, Collection $plan, Collection $withheld): array
    {
        $holds = $this->holds->storeHolds($business)->keyBy('store_id');

        $attributed = $this->attributeByStore($plan);

        $stores = Store::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get(['id', 'name']);

        return $stores->map(function (Store $store) use ($attributed, $withheld, $holds) {
            $id = (int) $store->id;
            $hold = $holds->get($id);
            $share = (int) ($attributed[$id] ?? 0);
            $held = $hold instanceof PayoutHold;

            return [
                'id' => $id,
                'name' => $store->name,
                'attributed' => Naira::floatFromKobo($share),
                'attributed_kobo' => $share,
                'withheld' => Naira::floatFromKobo((int) ($withheld[$id] ?? 0)),
                'withheld_kobo' => (int) ($withheld[$id] ?? 0),
                'held' => $held,
                'hold_reason' => $held ? $hold->reason : null,
            ];
        })->values()->all();
    }

    /**
     * A hold as the business's own wallet block carries it.
     *
     * `over` is the hold's own description — "store 42", "business 7" — which is
     * the right shape for a log line and the wrong shape for a sentence shown to a
     * business. `store_id` is sent beside it, non-null only for a store hold, so a
     * screen can name *which* store from the `stores` array it already has rather
     * than echoing an id at somebody whose store has a name.
     *
     * @return array<string, mixed>
     */
    private function holdPayload(PayoutHold $hold): array
    {
        return [
            'id' => (int) $hold->id,
            'scope' => $hold->scope,
            'source' => $hold->source,
            'reason' => $hold->reason,
            'over' => $hold->describe(),
            'store_id' => $hold->scope === PayoutHold::SCOPE_STORE ? (int) $hold->store_id : null,
            'placed_at' => $hold->placed_at?->toISOString(),
        ];
    }

    /**
     * One business's money that is inside a transfer already.
     *
     * The figure the wallet could not previously show, and the reason this method
     * exists at all. A payout debits `available_balance` at
     * {@see PayoutSweepService::reserve()} — which runs *before* the provider is
     * called, deliberately, so a second sweep cannot reserve the same money twice. So
     * from the moment a sale is sent, `available_kobo` and `payable_kobo` both read
     * zero while a real amount is on its way to a bank. A screen built only from those
     * two says "nothing here" about money that is moving, which is the single thing a
     * business opening its wallet wants to know.
     *
     * `since` is the oldest transfer's `requested_at`, and the whole list is sent
     * rather than the total alone, because per-store wallets can have several transfers
     * moving at once and the client cannot invent the per-transfer detail it is asked
     * to show. `requested_at` rather than `created_at` because it is the column
     * {@see PayoutSweepService::reconcile()} schedules on, so "how long has this been
     * moving" reads back with the system's own clock instead of a second one built on
     * the client — which is what lets the wallet stop calling a transfer normal once it
     * has been unanswered too long.
     *
     * Two things are deliberately absent. `bank_name`, because the row snapshots a
     * `bank_code` and not a name, and inventing one would mean reverse-matching a
     * code that may since have been reassigned — the single-destination case already
     * has `destination.bank_name` in the same payload, read from the bank on file.
     * And statuses outside pending/processing, because {@see
     * PayoutSweepService::fail()} credits the wallet back in the same transaction that
     * flips the row, so a refused transfer leaves this list and returns to
     * `payable_kobo` together and the two figures cannot disagree.
     *
     * @return array<string, mixed>
     */
    private function inFlightPayload(Business $business): array
    {
        $inFlight = $this->sweep->inFlightFor($business);
        $kobo = (int) $inFlight->sum('amount_kobo');
        $since = $inFlight->first()?->requested_at;

        return [
            'amount' => Naira::floatFromKobo($kobo),
            'amount_kobo' => $kobo,
            'count' => $inFlight->count(),
            'since' => $since?->toIso8601String(),

            // Whether the oldest of these has gone unanswered longer than a transfer
            // should take — the reconciler cannot chase a row the provider never gave
            // an id for, so one can sit here indefinitely and the wallet needs a point
            // at which it stops promising "usually minutes". Decided here rather than
            // on the client because the threshold is a policy figure the platform
            // owns: a business is told a transfer is late, it is not asked to work out
            // whether it is.
            'stale' => $since !== null
                && $since->lt(now()->subHours((int) config('custody.processing_display_hours', 24))),

            'destinations' => $inFlight->map(function (Payout $payout) {
                $number = (string) $payout->account_number;

                return [
                    'reference' => $payout->reference,
                    'amount' => Naira::floatFromKobo((int) $payout->amount_kobo),
                    'amount_kobo' => (int) $payout->amount_kobo,
                    'status' => $payout->status,
                    'bank_code' => $payout->bank_code,
                    'account_last_four' => strlen($number) <= 4 ? $number : substr($number, -4),
                    'account_name' => $payout->account_name,
                    'store_id' => $payout->store_id === null ? null : (int) $payout->store_id,
                    'requested_at' => $payout->requested_at?->toIso8601String(),
                    'sent_at' => $payout->sent_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * What the whole platform has moving right now, for the operator queue.
     *
     * The totals are whole and only the row list is capped — see
     * {@see self::QUEUE_IN_FLIGHT_LIMIT} — so an operator reading "38 transfers" is
     * reading the true figure even when the list below it is short.
     *
     * @return array<string, mixed>
     */
    private function platformInFlight(): array
    {
        $query = $this->sweep->inFlightQuery();

        $kobo = (int) $query->sum('amount_kobo');
        $count = (int) $query->count();

        $transfers = $query
            ->with(['business:id,name,business_code'])
            ->orderBy('requested_at')
            ->orderBy('id')
            ->limit(self::QUEUE_IN_FLIGHT_LIMIT)
            ->get();

        $oldest = $transfers->first()?->requested_at;

        return [
            'amount' => Naira::floatFromKobo($kobo),
            'amount_kobo' => $kobo,
            'count' => $count,
            'oldest_since' => $oldest?->toIso8601String(),
            // Same threshold the business's own wallet uses, so an operator and a
            // business are never looking at the same transfer and disagreeing about
            // whether it is late.
            'stale' => $oldest !== null
                && $oldest->lt(now()->subHours((int) config('custody.processing_display_hours', 24))),
            'truncated' => $count > $transfers->count(),
            'transfers' => $transfers->map(fn (Payout $payout) => [
                'business_id' => (int) $payout->business_id,
                'business_name' => $payout->business?->name,
                'business_code' => $payout->business?->business_code,
                'reference' => $payout->reference,
                'amount' => Naira::floatFromKobo((int) $payout->amount_kobo),
                'amount_kobo' => (int) $payout->amount_kobo,
                'status' => $payout->status,
                'requested_at' => $payout->requested_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * The platform-wide view an operator needs: who is owed what, who cannot take it,
     * what is held, and what is already moving.
     *
     * Read-only by design, and there is no "run it now" beside it. Money leaves the
     * wallet from one service, entered two ways — the queued job a collected sale
     * dispatches, and the operator's retry on this console — and both call
     * {@see PayoutSweepService::sweepBusiness()}, so neither is a second way to move
     * money and there is no "pay me now" endpoint to build. What an operator needs
     * during an incident is to *see* the queue and to place a hold, and both are here.
     *
     * `in_flight` is the part the queue alone cannot see. `candidates()` lists
     * businesses holding money, and a business whose money is entirely reserved has
     * `available_balance` of zero — so it is not a candidate, and a transfer stuck
     * unanswered by the provider would be invisible on the one screen an operator
     * opens when a business says its payout never arrived. The rows are capped and
     * `truncated` says so, because this list can grow to one row per sale during a
     * provider outage and an unbounded admin payload is its own incident.
     *
     * @return array{businesses: array<int, array<string, mixed>>, totals: array<string, mixed>, holds: array<int, array<string, mixed>>, in_flight: array<string, mixed>}
     */
    public function platformQueue(): array
    {
        $currency = (string) config('custody.currency', 'NGN');
        $businesses = [];
        $payableTotal = 0;
        $stuckTotal = 0;

        foreach ($this->sweep->candidates() as $business) {
            $available = (int) $business->available_balance;
            $blocker = $this->sweep->payoutBlocker($business);
            $payable = $blocker === null ? $this->sweep->payableFor($business) : null;

            $payableKobo = $payable['payable_kobo'] ?? 0;

            $payableTotal += $payableKobo;
            $stuckTotal += $available - $payableKobo;

            $businesses[] = [
                'id' => (int) $business->id,
                'name' => $business->name,
                // The code the admin routes bind on, sent because every other
                // surface addresses a business by it: a queue row that could not
                // open the business's console would be a list an operator has to
                // leave to act on, during the incident this screen exists for.
                'business_code' => $business->business_code,
                'currency' => $currency,
                'available_kobo' => $available,
                'payable_kobo' => $payableKobo,
                'withheld_kobo' => $payable['withheld_kobo'] ?? 0,
                'blocker' => $blocker,
                'destination_failures' => $this->sweep->destinationFailures($business),
            ];
        }

        return [
            'businesses' => $businesses,
            'totals' => [
                'currency' => $currency,
                'payable_kobo' => $payableTotal,
                'stuck_kobo' => $stuckTotal,
                'businesses' => count($businesses),
            ],
            'holds' => $this->holds->openHolds()->map(fn (PayoutHold $hold) => [
                'id' => (int) $hold->id,
                'scope' => $hold->scope,
                'source' => $hold->source,
                'business_id' => (int) $hold->business_id,
                'store_id' => (int) $hold->store_id,
                'over' => $hold->describe(),
                'reason' => $hold->reason,
                'placed_at' => $hold->placed_at?->toISOString(),
            ])->values()->all(),
            'in_flight' => $this->platformInFlight(),
        ];
    }
}
