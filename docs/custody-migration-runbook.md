# Custody migration runbook

Moving businesses from **split-at-source** (each business's share settles directly into
its own account at the provider) to **collect-and-pay-out** (Storify charges on its own
credentials, holds the gross, and pays each business from its wallet).

The one principle that decided every question below: **a business must never have money
owed to it that neither rail can deliver.** Money in flight is fine. Money in an account
nobody is watching is not.

> **Status: the migration is complete.** Stage 5 ran, the connected-account machinery is
> deleted, and every business is on the new rail. There is no pilot to flip and no old
> account to drain — the sections that described those are kept below as a record of what
> was done and why, marked as history where they no longer describe something an operator
> can run. What an operator still has is the last three sections: the rollback lever, the
> sweeps, and the numbers to watch.

## What changes, and what does not

| | Before (split-at-source) | After (custody) |
|---|---|---|
| Who charges | the platform, naming the business's provider account | the platform, on its own account |
| Where the gross lands | the business's account at the provider | Storify's provider balance |
| Who the provider pays | the business, directly | nobody — Storify pays out |
| A refund before payout | the business's account | reversed out of its wallet |
| A refund or chargeback after payout | the business's account goes negative | **Storify's balance** goes negative |
| Merchant of record | the business | **Storify** |

The last line is not an implementation detail. Holding customer funds on behalf of third
parties has to be permissible under the licence you operate under, and the provider's terms
have to permit a platform holding pooled funds. No amount of engineering closes this; it
was a precondition to the migration and it remains a precondition to operating it.

## Preconditions for accepting money

1. **Legal sign-off** on the above.
2. The API is **hand-deployed**. `php artisan migrate --force` and `php artisan optimize:clear`
   must have run on the server, or nothing below is live.
3. `custody:promote` and `custody:payouts` are in the cron schedule
   (`php artisan schedule:list` shows both). If they are not running, a collected business
   is never paid — there is no fallback underneath custody.
4. The platform's own credentials are complete in `config/services.php` for whichever
   processor is selected. A business's own keys are never read under custody, so the
   platform's key *is* the rail.

## Putting a business back on the rail

```bash
php artisan custody:enable "{business_code}" --dry-run
```

Read the output. Then:

```bash
php artisan custody:enable "{business_code}"
```

This is now the exception rather than the switchover: `businesses.custody_enabled` defaults
`true`, so a newly created business is collected for from its first sale. The command exists
for the business that was taken off (see [Rolling a business back](#rolling-a-business-back))
and needs putting back.

It **warns** about both of these and does neither of them for you:

- **No payout bank on file**, or a bank row with a blank `bank_code`/`account_number`. The
  business can sell — Storify simply holds the money — but the sweep will skip it on every
  pass (five minutes) until an account is named.
- **A wallet that is not zero**, which is reachable on a business flipped on, off, and on
  again. A rollback does not clear the wallet, so a number that was already there is money
  somebody is still owed.

Neither is a refusal, and there is no `--force` any more. Refusing to flip a business with
no bank would leave it on **no rail at all** — it could not charge through the platform and
it has nothing else — which would stop it selling to protect it from a delay.

> **History.** This command used to refuse while the business's own account at the provider
> still held money, and `connect:payouts` was how you drained it. Both are gone: there is no
> per-business account any more, so there is no balance of the business's to leave behind
> and nothing to detect. Every charge lands in Storify's balance and every business is paid
> from its wallet, so a flip moves no money and strands none.

### Then watch one full cycle

On any business you have just put back on the rail, confirm:

1. A sale confirms, and the business's **wallet** shows it — `pending`, not `available`.
   `php artisan custody:promote --dry-run` should report it inside its settlement window.
2. Once the provider has released the money (it reports the charge in `available_balance`,
   not `pending_balance` — and until then, and only then, `custody.settlement_hours`), the
   promote run matures it and `custody:payouts --dry-run` lists the business as payable. The
   wait here is the provider's, not ours; if the money is available and still not maturing,
   read the promote run's own output before assuming a bug.
3. The next `custody:payouts` run pays it, and **the money reaches the bank**. This is the
   step that must be seen with your own eyes; everything before it is a number in a table.
4. Sell something and refund it before payout. The wallet reverses and the books balance.
5. Sell something, let it pay out, then refund it. `available_balance` goes **negative** —
   that is correct and intended: the business has the cash, Storify refunded the customer
   from its own balance, and the debt is recovered from the business's next sale.

## Rolling a business back

```bash
php artisan custody:disable "{business_code}" --dry-run
php artisan custody:disable "{business_code}"
```

Available at any moment and from any state — this is the command to reach for when
something is wrong. It does three things and deliberately not a fourth:

1. Stops Storify collecting for that business.
2. **Leaves the wallet exactly as it is.** A business rolled back after a sale is still owed
   that money, and clearing the wallet would keep it.
3. Prints what is still owed, in `available` and `pending`, and reports an exit success.

It does **not** clear the wallet, and it does **not** move money anywhere. The money is in
Storify's own provider balance, which is the platform's account — there is nowhere to move
it back to. Paying it out is what settles it, and **the payout sweep is what pays it out**:

**The sweep keeps finding this business, and keeps paying it.** The flag decides how a
business is *charged*; it says nothing about money already taken. Everything in the wallet
goes on maturing through `custody:promote` and being paid by `custody:payouts` exactly as
before, and so do sales collected before the rollback that settle afterwards — the sweep asks
whether *Storify collected the sale*, which is recorded on the transaction, not whether the
business is still being collected for. So a rollback taken mid-incident does what an operator
needs from it: no new money is taken, and everything already taken is still honoured.

It also cannot undo charges already collected. A business rolled back for an hour has had
that hour's sales processed by Storify, and will be paid for them out of the wallet.

A business left rolled back is a business that cannot take payments at all: it is no longer
collected for, so the platform cannot charge on its behalf, and no other rail exists. The
enforcement pre-flight (`businesses_awaiting_custody`) is what surfaces them.

## What the sweeps are, and where the money moves

Two commands move money, and they are the only two:

| Command | Schedule | What it does |
|---|---|---|
| `custody:promote` | every five minutes | Matures settled sales from `pending_balance` to `available_balance` |
| `custody:payouts` | every five minutes | Pays custody businesses from `available_balance`, and reconciles payouts in flight |

Both ran hourly until recently, and the hour was never the providers' doing. Three waits
stacked: an *assumed* 48h settlement window that nothing measured, then an hourly promote,
then an hourly payout. A business could wait two days for money the provider had already
released. The window is now measured against the provider's own balance (below) and the
cadence is five minutes, which leaves the provider's own settlement as the only delay left
in the chain.

The order is load-bearing: a sale promoted in one tick is a sale the payout in the same tick
can send. Laravel runs same-minute entries in registration order, so `custody:promote` is
registered first. Both are `withoutOverlapping(3)` — a five-minute gap behind a longer lock
would silently *skip* ticks rather than delay them.

### The promote run is gated on the provider's balance

`custody:promote` no longer matures a sale because a clock decided it was old enough. It
asks the provider what it is actually holding — `available_balance`, the money the provider
will let us draw on — subtracts what that money is already spoken for (every business's
wallet balance, every payout still in flight) and the fee headroom
`custody.payout_fee_headroom_kobo`, then matures sales oldest-first until the budget runs
out. The headroom exists because Bachs debits the amount **plus its fee**, so a platform
gated down to exactly what it means to send would be refused at the last step. The
settlement window in `custody.settlement_hours` is now a floor rather than the control.

The failure modes are deliberately asymmetric:

| What the provider said | What the run does |
|---|---|
| A number | Matures sales up to it, oldest first. It never skips the head of the queue to reach a smaller newer sale — FIFO, or the accounting is not a queue. |
| **Zero** | Matures nothing. That is a real statement and it is believed. |
| Nothing at all — unreachable, an error, or an answer about another currency | The window alone decides, falling back to `custody.default_hours` (72). Warns: "the provider's balance could not be read", and logs `custody.promote` with `gate.measured: false`. |

An unreadable balance must never be read as zero: a DNS blip is not the provider saying we
are broke, and treating it as such would freeze every payout on the platform. `default_hours`
is what makes that fallback slow rather than instant — **do not lower it.** When a run
withholds sales because the provider has not released the money, it says so: they stay
payable and mature on a later run, and nothing is lost.

`custody.balance_gate.enabled` turns the gate off and restores the old clock-only behaviour.
It is a switch rather than a deleted branch so that an incident can be worked around without
a deploy — but with it off, sales mature against a window nobody verified again.

```bash
php artisan custody:promote --dry-run
```

Reads the same predicate and the same budget the real run uses, so the table it prints is
what the next run would move, including when the provider has not funded everything due.

### The exposure a near-zero window carries

Money paid out before a chargeback lands comes out of Storify's own balance, and Bachs
refuses transfers while any currency balance is negative — so a bad dispute week is a
platform-wide payout outage, not one business's bookkeeping problem. The levers back are
`custody.settlement_hours` per provider (raise it; the platform then matures less than the
provider would allow) and the payout holds below.

### Holds: stopping a payout without moving one

```bash
php artisan custody:hold                                                    # what is held, and why
php artisan custody:hold --business="{business_code}" --reason="Chargeback investigation, ticket 8812"
php artisan custody:hold --store={store_id} --reason="Orders under review"
php artisan custody:hold --system --reason="Bachs balance negative"
php artisan custody:release --business="{business_code}"
php artisan custody:release --business="{business_code}" --source=suspension
```

Three widths, because the questions are different: the platform (the rail itself is
suspect), one business (all of its stores), and one store. The store width withholds **that
store's share and keeps paying the business for the rest**, which is the entire reason the
narrowest width exists — a dispute about one store's sales is not a reason to stop a business
that has done nothing wrong. `--reason` is required: a hold nobody can explain is a hold
nobody lifts.

A hold does exactly one thing — it makes `custody:payouts` skip the money — and that is what
makes it safe. It never clears a wallet, never stops a charge, and never strands a payout
already with the provider, because reconciliation does not read holds. So lifting it is the
whole remedy: the next run pays everything that accumulated behind it, with nothing to
reverse and nothing to reconcile by hand.

Suspending a business or a store places a hold automatically, and reactivating releases it.
`--source=suspension` lifts that one by hand without touching a hold a different person
placed for a different reason; without it, `custody:release` lifts everything open at that
width.

The run reports holds as their own line, with the reason, and never folds them into `held`
(the owner switched automatic payouts off) or `skipped` (nothing owed, or another run took
the balance first). A business that is owed money and not paid should be explained by that
line, and if it is not, that is the bug.

An admin holding `admin.finance` has the same controls through the console:
`/admin/payout-queue`, `/admin/payout-holds` and `/admin/businesses/{code}/wallet`. There is
deliberately no "pay now" among them; a second way to move money is a second way to move it
twice.

`custody:payouts` **gives up on a bank account** after
`config('custody.max_destination_failures')` refusals of it (3 by default) that are the
provider refusing to register the destination — counted since money last reached that
account, so naming a different bank clears the count by itself. The money stays in the
wallet, so nothing is lost by waiting, and the run reports the business as `blocked` rather
than `failed`:

```bash
php artisan custody:retry-payouts "{business_code}" --dry-run
php artisan custody:retry-payouts "{business_code}"
```

Run that once you have looked at the destination and satisfied yourself it is right — the
ceiling is a guess about the *account* made from what the provider said, and the provider
can be the one that is wrong. It retires the recorded refusals (marking those payout rows
`cancelled`) and the next run tries the bank again from a clean count. It moves no money:
each of those rows returned its reservation to the wallet when it failed, so the money has
been payable the whole time. Running it on a genuinely unusable account costs three more
refusals to reach the same ceiling.

There is deliberately no way to clear the ceiling for every business at once — the judgement
being recorded is about one destination, and a fleet-wide version of it would be a reflex
rather than a decision.

Everything else that used to be here is deleted, not disabled: `connect:payouts`,
`connect:requirements` and `connect:payees`, the services behind them
(`BachsConnectAccountService`, `PaystackOnBehalf`, `BachsOnBehalf`,
`ConnectRequirementService`, `ManagedAccount`, `ConnectConnections`,
`PlatformPayoutService`), the `gateway_account_id` columns, and
`businesses.payout_entity_type`. `businesses.custody_enabled` defaults `true`.

**The provider-side accounts are not deleted by any of this.** A business's old Bachs
account still exists at Bachs and still belongs to that business. Nothing in the platform
reads or writes it any more; closing it is a conversation with the business and the
provider, and is not part of this codebase's job.

## The numbers to watch

| Where | What it means |
|---|---|
| `custody:promote` → `unpriced` | Sales that settled before the provider said what it charged. A number that only grows means businesses are not being credited. |
| `custody:promote` → "the provider's balance could not be read" | The gate fell back to the clock. Money is still moving, on a window nobody verified — worth a look at the provider's API before it becomes routine. |
| `custody:promote` → "the provider has not released the money behind them" | Salable money is behind a provider that has not funded it. Normal briefly and not a bug; if it is standing, the provider is the conversation. |
| `custody:payouts` → `on hold` | Deliberate: an operator, a suspension, or a rail incident. The holds are listed with their reasons, and the money is in the wallet the whole time. If this line names a business nobody meant to hold, that is the bug. |
| `custody:payouts` → `held` | The *business's own* switch (`auto_payout`), not an operator's. Only its owner can turn it back on. |
| `custody:payouts` → `skipped` | Storify is holding money it cannot hand over: no payout bank, or another run took the balance. The first needs chasing. |
| `custody:payouts` → `blocked` | The provider keeps refusing this business's bank, so the sweep has stopped asking. Fix the destination or run `custody:retry-payouts`; the money is safe in the wallet until then. |
| `custody:payouts` → `unresolved` | Payouts the provider accepted but never confirmed. Normal briefly; their wallet reservations stay held. |
| `ledger:reconcile` | Weekly scan. Under custody it also compares each business's wallet against its `1040 Funds Held by Storify` ledger lines. |
| Enforcement pre-flight | Two lists an admin works through: `businesses_awaiting_custody` (not on the new rail — an operator's step) and `businesses_cannot_be_paid` (trading happily with no bank named, so the money is accumulating). |
