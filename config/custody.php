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
| the one bucket the payout sweep draws on — when it is genuinely payable.
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
    | would leave the sweep trying to send a figure the balance cannot cover, and
    | Bachs refusing it with `INSUFFICIENT_BALANCE` on every run until commission
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
    | Whether a collected sale is paid out as it is collected, rather than at the
    | next tick of the two runs.
    |
    | On by default. The runs are every five minutes, and that gap is the only
    | delay left in the chain once the provider has released the money — Bachs
    | reports a successful charge in `available_balance` immediately, and the
    | settlement window above is already nothing for it. So a business that sells
    | at 12:05:16 waits until 12:10 for no reason but our schedule.
    |
    | With this on, the settlement path offers the same three steps the run
    | performs — mature the business's due sales, read the restrictions, send the
    | payout — from `InstantPayoutService`, in the request that recorded the sale.
    | Maturation comes first because a hold stops money being sent rather than
    | changing what the wallet records as owed, which is also how the promote run
    | already behaves. The runs keep their five-minute cadence and become what
    | they should always have been: reconciliation, and the net for a provider
    | that could not be reached while somebody was waiting.
    |
    | Off, the platform behaves exactly as it did before this existed. It is a
    | switch rather than a deleted branch because this puts a provider call on the
    | settlement path, and if that turns out to cost more than it is worth during a
    | provider incident, paying on the next tick is a worse experience rather than
    | a broken one.
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
    | How long a payout may sit in `processing` before the reconciler goes and asks
    | the provider what happened to it. An accepted transfer normally resolves in
    | minutes via webhook; this is for the delivery that never arrived.
    */
    'reconcile_after_minutes' => 30,

    /*
    | How many payout rows one reconcile run will chase.
    */
    'reconcile_batch' => 100,

    /*
    | How many businesses one payout sweep will pay at a time.
    |
    | A working-set bound rather than a correctness one: the sweep is idempotent
    | per business and the next run picks up whatever is left. It exists so the
    | first run after a weekend of trading does not open one enormous transaction
    | and hold row locks for the duration.
    */
    'payout_batch' => 100,

    /*
    | How many times the sweep will ask the provider to accept the *same* payout
    | destination and be refused, before it stops asking and waits for a person.
    |
    | Only failures the provider attributed to the destination count — a bank code
    | it will not resolve, an account it will not open. Everything else, including
    | the one refusal that is genuinely transient (`INSUFFICIENT_BALANCE` while a
    | promote batch is still settling), keeps retrying on every run, because that
    | one fixes itself and stopping would be withholding money a business is owed.
    |
    | The point is that a destination which cannot work should not be attempted
    | every hour forever: it burns provider calls, it makes the log unreadable, and
    | it hides a business that needs somebody to look at it behind a run that
    | reports a failure every hour until nobody reads them any more. Three is
    | deliberately small — the money stays in the wallet, visibly owed, and the run
    | reports it as blocked rather than as failed.
    |
    | Recovery, both of them deliberate: naming a different bank resets the count
    | on its own, because the failures are matched to the destination that was
    | tried; and `custody:retry-payouts` clears it for a destination that is right
    | and a provider that was wrong. Zero or less disables the ceiling.
    */
    'max_destination_failures' => 3,

];
