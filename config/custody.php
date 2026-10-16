<?php

/*
| Custody — when a business's money becomes Storify's to hand over.
|
| A collected sale is owed to the business the moment it is confirmed, but the
| provider may not have released it *to Storify* yet. Paying out before that happens
| means Storify advancing money it does not have, and several businesses doing it at
| once overdraws the platform's own provider balance — which the provider reports as
| a negative balance and then chases.
|
| So a credit lands in `pending_balance` and only matures into `available_balance` —
| the one bucket the send draws on — when it is genuinely payable.
|
| **What decides that is a measurement, not a clock.** The provider is asked what it
| will actually let the platform spend (`PayoutRail::availableBalance()`), and a run
| matures only up to what that answer covers — see
| `PayoutSweepService::promotionGate()`. The hours below are the floor underneath
| that: how long to wait even when the provider says the money is already there, and
| therefore how much room a dispute has to appear before the money leaves.
|
| They are set to nothing for the providers that have a rail, because both report a
| spendable balance and that is a better answer than any number here. `default_hours`
| is the one that still matters: it is what a run falls back to when the balance
| cannot be read at all, so an unaskable provider degrades to the slow, clock-driven
| path rather than to an unverified instant payout.
|
| Raising a provider's hours is the lever if disputes turn out to arrive after the
| money has gone. A payout that has left cannot be recalled, and the refund is
| Storify's to carry.
*/
return [

    /*
    | The currency the wallet, the custody accounts and the payout rail are all
    | denominated in.
    |
    | **One currency, not one per business**, because the wallet is a single ledger
    | of record: `pending_balance` and `available_balance` are columns of kobo, the
    | platform's custody accounts hold one balance between every business, and a
    | payout row is written with this currency attached — see
    | `PayoutSweepService::currency()`. A wallet that could hold two currencies
    | would need a rate at the moment of every credit, and a rate is a number
    | somebody can be wrong about.
    |
    | The consequence is the rule in `PaymentGatewayResolver::custodyCanHold()`: a
    | sale is only collected while the provider charges in *this* currency. A store
    | priced in anything else cannot be collected for — the business's share is
    | `amount − provider fee − commission`, and on a converted charge the first
    | term is the store's currency while the other two are this one. Neither the
    | wallet nor the journal can hold that sum, so rather than convert it (to what,
    | at which rate, dated when?) both refuse it: the resolver will not charge, and
    | `CustodyService::recordCollectedSale()` will not credit a row that arrived
    | anyway.
    */
    'currency' => 'NGN',

    /*
    | Hours after `paid_at` before a collected sale becomes eligible, whatever the
    | provider's balance says.
    |
    | Keyed by the provider that collected it, because the windows differ and one
    | business may sell through more than one. A provider absent from this map falls
    | back to `default_hours` rather than to zero: an unknown provider must not
    | become an instant payout.
    */
    'settlement_hours' => [
        // Nothing. Bachs publishes `available_balance` and `pending_balance`
        // separately and refuses a transfer drawn on the pending one, so the gate
        // is exact here and a clock would only be a worse version of it. A charge
        // that has arrived but not settled is invisible to the balance and
        // therefore unmatured on its own.
        'bachs' => 0,

        // Nothing. Paystack credits its balance when it accepts the charge, and the
        // transfer draws on that balance rather than on the T+1 settlement to a
        // bank account — so the bank-settlement window never enters into whether a
        // business can be paid out of it.
        'paystack' => 0,
    ],

    /*
    | Used for a provider with no entry above — and, more importantly, for a run
    | that could not read the balance at all.
    |
    | Not zero, and not a short default, because this is now the whole gate in the
    | one case the gate cannot cover: a provider that cannot be asked. A number here
    | that was as short as the entries above would turn "the balance is unreadable"
    | into "pay everything instantly with nothing verified", which is the exact
    | failure the settlement window used to exist to prevent.
    */
    'default_hours' => 72,

    /*
    | Whether a promote run is gated on the provider's own balance.
    |
    | On by default. Turning it off restores the old behaviour exactly: the
    | settlement window above and nothing else. It is a switch rather than a deleted
    | code path because the gate depends on a provider endpoint, and if that
    | endpoint turns out to disagree with reality the platform needs a way to keep
    | paying while somebody works out why.
    */
    'balance_gate' => [
        'enabled' => env('CUSTODY_BALANCE_GATE', true),
    ],

    /*
    | Kobo held back from the provider's spendable balance when working out what a
    | run may mature.
    |
    | Bachs charges its payout fee **on top** of the amount sent — `total_debited` is
    | the amount plus the fee — so a gate that matured exactly what the balance holds
    | would leave the send trying to move a figure the balance cannot cover, and
    | Bachs refusing it with `INSUFFICIENT_BALANCE` on every attempt until commission
    | accrued again.
    |
    | Generous on purpose, and the asymmetry is what sets it: leaving this much
    | unmatured costs a sale maturing one run later, while being exact costs a payout
    | that does not send. The cost of a wrong figure here is bounded either way — an
    | under-funded send is *refused*, which releases the reservation and retries, so
    | nothing is lost by getting it wrong.
    */
    'payout_fee_headroom_kobo' => 10000,

    /*
    | Whether a collected sale is paid out as it is collected, rather than when
    | somebody asks for it.
    |
    | On by default, and it is the switch for the whole money path: with it off,
    | `SettlePayouts` is dispatched by nobody and a collected sale simply sits in
    | the wallet until an operator runs `custody:payouts` by hand. There is no
    | schedule to fall back on, so **off means nothing is sent automatically** —
    | which is why the deploy notes call this out as a thing that must not be set
    | to false on the host.
    |
    | On, the settlement path offers the same three steps the command performs —
    | mature the business's due sales, read the restrictions, send the payout —
    | through `InstantPayoutService::settle()`. Maturation comes first because a
    | hold stops money being sent rather than changing what the wallet records as
    | owed.
    |
    | **The send is queued, not inline.** The request that recorded the sale (the
    | provider's webhook, the storefront's return leg, the POS till) dispatches the
    | job and returns; the provider round trip, the retry schedule and the failure
    | alert all happen on a worker. That keeps checkout fast and is the only reason
    | `queue:work` has to be running for money to move.
    */
    'instant_payout' => [
        'enabled' => env('CUSTODY_INSTANT_PAYOUT', true),
    ],

    /*
    | How many settled transactions one promote run will mature at a time.
    |
    | The promote job is idempotent and re-runnable, so this is a working-set
    | bound rather than a correctness one: it keeps the first run after a busy
    | weekend from opening one enormous transaction.
    */
    'promote_batch' => 500,

    /*
    | How long a payout may sit in `processing` before `custody:payouts` will ask
    | the provider what happened to it. An accepted transfer normally resolves in
    | minutes via the provider's own webhook, which is now the resolver; this is
    | for the delivery that never arrived.
    */
    'reconcile_after_minutes' => 30,

    /*
    | How many payout rows one reconcile run will chase.
    */
    'reconcile_batch' => 100,

    /*
    | How long an unresolved transfer is shown as ordinary before the wallet starts
    | saying it is taking longer than usual.
    |
    | A display threshold, not a behaviour one: nothing is sent, retried or failed
    | because of it. It exists because resolving a row needs a provider id to chase,
    | and a send that timed out before returning one has nothing to chase — so such a
    | transfer waits on its webhook, and a wallet that kept saying "on its way,
    | usually minutes" forever would be lying to the only person who can raise it. A
    | day is far longer than any real transfer of this size and far shorter than the
    | point at which somebody notices on their own.
    */
    'processing_display_hours' => 24,

    /*
    | How many businesses one send will pay at a time.
    |
    | A working-set bound rather than a correctness one: the send is idempotent
    | per business and a later run picks up whatever is left. It exists so the
    | first run after a weekend of trading does not open one enormous transaction
    | and hold row locks for the duration.
    */
    'payout_batch' => 100,

    /*
    | How many times the send will ask the provider to accept the *same* payout
    | destination and be refused, before it stops asking and waits for a person.
    |
    | Only failures the provider attributed to the destination count — a bank code
    | it will not resolve, an account it will not open. Everything else, including
    | the one refusal that is genuinely transient (`INSUFFICIENT_BALANCE` while a
    | promote batch is still settling), keeps being retried on the schedule below,
    | because that one fixes itself and stopping would be withholding money a
    | business is owed.
    |
    | The point is that a destination which cannot work should not be attempted
    | forever: it burns provider calls, it makes the log unreadable, and it hides a
    | business that needs somebody to look at it behind a stream of failures until
    | nobody reads them any more. Three is deliberately small — the money stays in
    | the wallet, visibly owed, and the send reports it as blocked rather than as
    | failed.
    |
    | Recovery, both of them deliberate: naming a different bank resets the count
    | on its own, because the failures are matched to the destination that was
    | tried; and `custody:retry-payouts` clears it for a destination that is right
    | and a provider that was wrong. Zero or less disables the ceiling.
    */
    'max_destination_failures' => 3,

    /*
    | How many sends in a row may fail, for any reason, before the platform stops
    | trying and waits for a person.
    |
    | The ceiling above covers the failures the provider blames on the *account*.
    | This one covers everything else: a provider that is down, a balance that has
    | not settled, a refusal with a code nobody has seen. Every one of those
    | returned the money to the wallet, and this is how many times the platform
    | asks again before it stops.
    |
    | **Both ceilings here are the job's own budget.** `SettlePayouts::tries()`
    | reads this key and the worker enforces it, so the queue giving up and the
    | business being marked blocked are the same moment — and the last failure
    | alerts the admins, because from then on only a person moves the money.
    |
    | Counted per business and per run of failures rather than per destination,
    | because a platform problem is not a fact about anybody's bank account. The
    | window resets itself the moment a send lands, and an operator can reset it by
    | hand.
    |
    | Zero or less disables the ceiling.
    */
    'retry_attempts' => (int) env('CUSTODY_PAYOUT_RETRY_ATTEMPTS', 3),

    /*
    | How long to wait before each of those retries, in minutes, oldest first.
    |
    | A growing gap rather than a fixed one, so a provider having a bad minute is
    | retried quickly and a provider having a bad afternoon is not hammered. The
    | last value repeats for any failure beyond the end of the list — it is a
    | schedule for the first few attempts, not a bound on them.
    |
    | **Read by the queue worker, not by a loop of ours.** `SettlePayouts::backoff()`
    | hands these minutes to the worker as seconds, and the worker is what waits
    | between attempts (`Worker::calculateBackoff()`) — so a payout retry is a real
    | delayed job rather than a `sleep()` inside a request. The send's own
    | refusal-backoff reads the same list, which is what keeps the two clocks
    | agreeing: a business cannot be waiting out one while the job gives up on the
    | other.
    |
    | Overridable as a comma list, and note it is **minutes** where `QUEUE_BACKOFF`
    | is seconds: `10,30,60` there is ten seconds, and the same string here is ten
    | minutes. A busy provider is a production fact, and answering it should not
    | need a deploy.
    */
    'retry_backoff_minutes' => array_values(array_filter(array_map(
        static fn (string $minutes): int => (int) trim($minutes),
        explode(',', (string) env('CUSTODY_PAYOUT_BACKOFF', '5,30,60')),
    ), static fn (int $minutes): bool => $minutes > 0)),

    /*
    | How long to wait before looking again at money the provider has simply not
    | released yet, in minutes, oldest first — and for rarer reasons than
    | `retry_backoff_minutes` above.
    |
    | **The two lists answer different questions and must not be confused.** The
    | one above is for a provider that is *broken*: it refused, or it could not be
    | read. That is an incident, it is worth three quick attempts, and it is worth
    | an admin alert when they run out.
    |
    | This one is for a provider that answered perfectly and does not have the
    | money yet. A card charge arrives at Bachs as pending and only becomes
    | spendable when the provider settles it, which the provider does on its own
    | schedule — so a sale can be collected, recorded, owed, and genuinely
    | unsendable for hours. Nothing is wrong. The five-minute clock is simply
    | shorter than the thing it is waiting for, and retrying at five minutes then
    | thirty then sixty would spend the whole budget inside the first hour and
    | alert a human about a state that fixes itself. Before this list existed it
    | did exactly that.
    |
    | So this path is quiet: no alert while it is pending, and a re-check on the
    | clock below instead. These are deliberately hours rather than minutes, and
    | the last one is deliberately long: with `settlement_hours` at 0 the moment
    | the provider releases the money the next check sends it, so the only cost of
    | waiting is latency on a payment that could not have been made earlier anyway.
    |
    | The chain running out is what a person is told about, because a charge that
    | has not settled in this long is no longer routine. The default checks at an
    | hour, four hours and twelve hours — seventeen hours in total, which is well
    | past a next-day settlement cycle and well inside the point at which somebody
    | would be asking anyway.
    |
    | A business's own next sale re-checks it too, and sooner — `settle()` promotes
    | everything the business is owed, not just the sale that triggered it — so
    | this list is the safety net for a business that has stopped selling, not the
    | only way the money is ever moved.
    */
    'settlement_lag_backoff_minutes' => array_values(array_filter(array_map(
        static fn (string $minutes): int => (int) trim($minutes),
        explode(',', (string) env('CUSTODY_PAYOUT_SETTLEMENT_BACKOFF', '60,240,720')),
    ), static fn (int $minutes): bool => $minutes > 0)),

    /*
    | Whether a collected sale is credited to the store that earned it, or to the
    | business as a whole.
    |
    | Off, every bucket is the business's and the platform behaves exactly as it
    | did before stores had wallets. On, a sale lands on its own store, settles
    | there, and is paid out to that store's payout account — falling back to the
    | business default when the store names none.
    |
    | **One switch, read in one place.** `CustodyBucket` is the only thing that
    | reads this, and credit, promote, refund and the send all resolve the
    | bucket through it. They share `transactions.custody_store_id`, which records
    | where a sale's money went, so flipping some of them and not the others would
    | credit to one bucket and settle from another — money stranded on a store
    | with nothing to promote it. That is why this is a flag and not a rollout
    | order.
    |
    | Flipping it is safe for existing balances: money already collected stays on
    | the business, which is what the business bucket now means — money no store
    | accounts for. Nothing is redistributed, because the history does not say
    | which store earned a lump credited before the column existed.
    */
    'per_store_wallets' => env('CUSTODY_PER_STORE_WALLETS', false),

];
