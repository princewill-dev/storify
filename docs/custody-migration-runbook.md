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
> commands that force a payout, and the numbers to watch.

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
3. **Two workers are consuming the queue.** Paying a business is a queued job
   (`App\Jobs\SettlePayouts`, on the `default` queue), so with no `queue:work` running
   nothing settles at all: a sale is credited and payable and no send is ever attempted.
   The queue is load-bearing for money, not just for email. A second worker drains
   `payout-checks`, the lane that asks the provider what became of each transfer:

   ```bash
   php artisan queue:work
   php artisan queue:work --queue=payout-checks
   ```

   Keep them separate. A status check may spend thirty seconds on one provider call, and
   nine per payout; sharing the lane would let a slow provider hold up every send behind
   it. With no worker on `payout-checks`, a transfer the provider accepted and then went
   quiet about sits in `processing` until someone runs `custody:payouts` by hand.

   `CUSTODY_INSTANT_PAYOUT` must not be `false` (off, a sale simply waits), and the retry
   budget the worker respects is `CUSTODY_PAYOUT_BACKOFF` — minutes, `5,30,60` by default.
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
  business can sell — Storify simply holds the money — but the send skips it until an account
  is named.
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
3. The queued settlement pays it, and **the money reaches the bank**. This is the
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
it back to. Paying it out is what settles it, and **the queued send is what pays it out**
(`App\Jobs\SettlePayouts`, dispatched the moment a sale is collected — there is no scheduled
run behind it any more):

**The settlement path keeps finding this business, and keeps paying it.** The flag decides how
a business is *charged*; it says nothing about money already taken. Everything in the wallet
goes on maturing and being paid out exactly as before, and so do sales collected before the
rollback that settle afterwards — the send asks whether *Storify collected the sale*, which is
recorded on the transaction, not whether the business is still being collected for. So a
rollback taken mid-incident does what an operator needs from it: no new money is taken, and
everything already taken is still honoured.

It also cannot undo charges already collected. A business rolled back for an hour has had
that hour's sales processed by Storify, and will be paid for them out of the wallet.

A business left rolled back is a business that cannot take payments at all: it is no longer
collected for, so the platform cannot charge on its behalf, and no other rail exists. The
enforcement pre-flight (`businesses_awaiting_custody`) is what surfaces them.

## Where the money moves, and the commands that force it

Two commands can pay a business out, and both are **tools** now rather than a schedule. Paying a business
is a queued job — `App\Jobs\SettlePayouts`, dispatched when a sale is collected, when a payout
hold lifts, when an operator presses Retry, and by these commands — and this is what an
operator reaches for during an incident:

| Command | When it runs | What it does |
|---|---|---|
| `custody:promote` | by hand, or as part of a settlement | Matures settled sales from `pending_balance` to `available_balance` |
| `custody:payouts` | by hand | Pays custody businesses from `available_balance`, and reconciles payouts in flight |

**Neither is in the cron schedule any more**, and neither should be put back: a second,
out-of-band payer racing the job would not double-pay (the reservation refuses to overdraw)
but it would put a provider call back on somebody's clock for no benefit.

Both ran hourly until recently, and the hour was never the providers' doing. Three waits
stacked: an *assumed* 48h settlement window that nothing measured, then an hourly promote,
then an hourly payout. A business could wait two days for money the provider had already
released. The window is now measured against the provider's own balance (below), and the
payout itself is made the moment a sale settles — so the provider's own settlement is the
only delay left in the chain.

The order is load-bearing even without a schedule: a sale has to be matured before it can be
sent. The queued settlement does the three steps in that order — mature, read the
restrictions, send — and `custody:payouts` runs them the same way when an operator reaches for
it.

**Both are the net rather than the ordinary path.** A sale is usually paid out as it settles —
see "Payouts are immediate" below — and the commands exist to catch what that missed: a
provider that could not be reached, a sale that arrived with no fee reported, a balance that
could not be read. A run that finds nothing to do is the normal state of a healthy platform,
not a sign a worker has stopped.

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
payable and mature on a later attempt, and nothing is lost.

`custody.balance_gate.enabled` turns the gate off and restores the old clock-only behaviour.
It is a switch rather than a deleted branch so that an incident can be worked around without
a deploy — but with it off, sales mature against a window nobody verified again.

```bash
php artisan custody:promote --dry-run
```

Reads the same predicate and the same budget the real run uses, so the table it prints is
what a real promotion would move, including when the provider has not funded everything due.

### The exposure a near-zero window carries

Money paid out before a chargeback lands comes out of Storify's own balance, and Bachs
refuses transfers while any currency balance is negative — so a bad dispute week is a
platform-wide payout outage, not one business's bookkeeping problem. The levers back are
`custody.settlement_hours` per provider (raise it; the platform then matures less than the
provider would allow) and the payout holds below.

### The fee has to be the sale's cost, and the sales that settled without one

`net = amount − provider fee − commission` assumes the provider's fee came **out of the
charge**. That is only true while the Bachs organisation is set to bear it. Check it before
trusting any wallet figure:

```bash
curl -s -H "Authorization: Bearer $BACHS_API_KEY" \
  https://sandbox-api.bachs.io/v1/accounts/{org_id} | jq .fee_handling
# account_pays_fee  ← the platform bears it, and the formula above holds
# customer_pays_fee ← the customer pays it on top; see below
```

Bachs reports the two in different fields — `fee_handling` when reading, `fee_preference`
when writing, `org_pays` / `customer_pays`:

```bash
curl -s -X POST -H "Authorization: Bearer $BACHS_API_KEY" -H 'Content-Type: application/json' \
  -d '{"fee_preference":"org_pays"}' https://sandbox-api.bachs.io/v1/accounts/{org_id}
```

**Under `customer_pays_fee` every managed sale under-credits its business by the provider's
fee.** The charge is grossed up — a ₦24,725.00 order was collected as ₦25,095.88 with a
₦370.88 fee and settled at ₦24,725.00 — so the platform received the whole order and the
fee was never the sale's cost at all. Subtracting it hands the business ₦370.88 less than
the platform is holding for it and keeps the difference. The tell is on the payment:
`amount` above what was billed, with `settlement_amount` equal to it.

A sale that settled with no fee on it — `fee_kobo IS NULL`, which is also what a writer
that read the wrong field of the payload leaves behind — is credited by nothing. The wallet
wait is deliberate, but `PaymentWebhookProcessor::settle()` short-circuits on the
transaction's status, so the delivery that would have carried the fee is a no-op by the time
it arrives, and `custody:promote` selects `whereNotNull('fee_kobo')` and can never reach it:

```bash
php artisan custody:repair-sale-fees                    # what it would price, and writes nothing
php artisan custody:repair-sale-fees --apply
php artisan custody:repair-sale-fees --apply --business={id} --limit=50
```

It re-reads the provider's charge and writes `fee_kobo` and `net_kobo`, which puts the sale
back into the next `custody:promote` pass — **the repair moves no money itself**. It derives
the fee the sale bore from the provider's own numbers rather than from the setting, so the
grossed-up sales above are priced without it; it skips a sale the provider says is not paid,
one it has still not priced, and one with no commission recorded (pricing that at today's
rate would price an old sale at the wrong one), and names each rather than guessing. It
reads each charge on its own terms, so it prices a grossed-up row the way it was charged
whether or not the organisation's fee handling has been corrected yet; that correction only
governs what is charged from then on.

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
whole remedy: the next attempt pays everything that accumulated behind it, with nothing to
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

The queue lists **every business the platform owes money to**, which is deliberately wider than
the set it could pay today: a business whose whole balance is still settling at the provider has
nothing available and nothing payable, and appears anyway — with the amount in **Settling** and
nothing under **Stopping it** — because the alternative is a business that is owed money and is
on no screen at all. Set expectations against the **Settling** column before treating an empty
**Ready to send** as a problem.

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
`cancelled`) and the next attempt tries the bank again from a clean count. It moves no money:
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

## Payouts are immediate

A sale is paid out as it settles. The request that records the sale — the provider's webhook,
the storefront's return leg, the POS till — dispatches `App\Jobs\SettlePayouts` and returns;
the send happens on a worker seconds later. There is no five-minute sweep to wait for, and
what used to be its runs are now the operator's tools.

Nothing about *how* the money moves changed. The same three steps happen in that job, through
`PayoutSweepService`'s own code — mature the business's due sales, read the
restrictions, send the payout — so a trigger and a manual run landing on the same business at
the same instant cannot pay it twice: the claims (`settle_at`, `promoted_at`, the payout
reservation) are the same, and the loser finds the claim gone.

Two settlement paths reach it, and **whichever hears about the payment first is the one that
pays**:

| Path | Reaches the payout |
|---|---|
| The customer's browser returning to the confirmation page | `CheckoutPaymentService::verify()` |
| The provider's `collection.succeeded` delivery | `ConnectWebhookController` → `PaymentWebhookProcessor::settle()` |

For one sale only one of them gets there. A delivery that arrives after the customer's return
trip finds the row already confirmed and stops at that guard, which is above every line that
moves money; in the other order the return trip is the one that finds it confirmed. So the
payout is made once. **The delivery must go to `/webhooks/connect/bachs`, not to a merchant
webhook URL**: it is signed with the platform's own secret, and the merchant endpoint resolves
its scope by finding a *business's* stored secret — which a business under custody does not
have, so a platform delivery sent there is answered 401 forever.

### The restrictions are read before any transfer, and only the transfer

A **system** hold, a **business** hold or `auto_payout` off, and a destination that is missing
or the provider keeps refusing — the same list the wallet page and `--dry-run` report, read in
the same order. When one is set the payout is not attempted, and no transfer is asked for.

Maturation happens **first and regardless**: a hold stops money being sent, it does not change
what the wallet records as owed, and `custody:promote` reads no hold either. So a held
business's wallet still matures and its screen still shows the money as owed — the hold is
what stops the send, and lifting it pays on the next attempt with nothing to repair.

A **store** hold is not on that list: it withholds that store's share and pays the business for
the rest, immediately.

### When to turn it off

`custody.instant_payout.enabled` (`CUSTODY_INSTANT_PAYOUT`). Off, nothing is sent as a sale
settles: the sale is still collected and credited, and it sits payable in the wallet until an
operator runs `custody:payouts` by hand — there is no schedule to fall back on.

Turn it off when the instant path is costing more than it is worth — a provider incident where
the extra balance read and transfer on the settlement path are the thing making it worse. It
is a worse experience and not a broken one: the money stays payable in the wallet, and one
deliberate `custody:payouts` run releases everything that accumulated.

It **never fails a request**. A provider that cannot be reached, a balance that cannot be read,
a spendable balance that does not cover the sale, a transfer the provider refuses: each is
logged, the reservation is released, and the sale stays payable for a later attempt. That is
deliberate — the settlement itself is already committed by the time the payout is attempted,
and turning a slow provider into a failed webhook would have it redeliver a payment we have
already recorded, to pay a business we can still pay.

### When the provider is down

Payouts do not retry forever. `App\Jobs\SettlePayouts` gets three attempts — the backoff in
`custody.retry_backoff_minutes` is `5`, then `30`, then `60` minutes — and then it stops. **Only
the first two gaps elapse before the budget runs out**, because the third attempt is the last one
the worker will make: the wall-clock window is about thirty-five minutes, not the ninety-five the
three figures add up to. Read the list as "the first three attempts start 5 and 30 minutes after
the ones before them", not as a total.

After that, the money does not leave the wallet: it stays in `available_balance`, still showing as
owed, and the last failure writes a `failed_jobs` row and sends the admin alert email. Nothing is
lost and nobody is paid twice; the platform is simply waiting for a person.

Once the provider is back, press **Retry** in the admin console (or run `custody:retry-payouts`).
The buttons are on the admin **Payouts** screen (AD-19): **`Retry`** on a business's row in the
queue, and **`Retry all failed payouts`** in the header for the whole set. Both queue the attempt
and neither sends anything inline; that clears the recorded refusals and queues a fresh attempt
from a clean count — the money has been payable the whole time, so there is nothing to reconstruct.

### When the provider is *not* down, and has simply not paid yet

The two are not the same event and are no longer treated as one. Bachs and Paystack both hold a
card charge as pending before making it available, on their own schedule, which is **hours**. So a
sale can be collected, recorded, owed and genuinely unsendable for most of a day while the provider
is perfectly healthy — and the send finds an empty balance and sends nothing.

That state used to spend the whole thirty-five-minute retry budget and then email an operator about
a payment that had never been sendable. It no longer does. A provider that was read successfully,
refused nothing, left nothing payable and is holding a sale in `pending_balance` is treated as
**waiting rather than failing**: no alert, no failure, and a re-check scheduled from
`custody.settlement_lag_backoff_minutes` — an hour, then four hours, then twelve. When that chain
runs out, roughly seventeen hours after the sale, a person *is* told, because a charge that has not
settled by then is not routine any more.

**Nothing needs doing about any of this**, and that is the point of the separate clock. The money
is the business's, it is in the wallet, and the send follows the moment the provider releases it.
Two things will get it there sooner than the chain: the business's own next sale, which re-checks
everything it is owed and not merely that sale, and an operator pressing **Retry**. Both are safe —
neither can pay anything the provider has not released.

The queue on the admin **Payouts** screen lists these businesses, with the money in a **Settling**
column and *"Still settling with the provider"* under **Stopping it**. That row is a fact, not a
fault: it is there so that when a business rings to ask where its money is, the answer is on the
screen rather than inferred from a log.

### `queue:work` is load-bearing for money

A worker is not only for email any more. Every payout — the one made as a sale settles, every
retry, every hold lifted — runs on the queue, so a stopped worker means **nothing settles at
all**: sales are still collected and credited, the wallet still shows the money as owed, and no
send is ever attempted. If payouts have gone quiet platform-wide, the worker is the first thing
to check.

There are **two** workers, and the second one is easy to forget because its absence produces no
error:

```bash
php artisan queue:work                            # sends, email, SettlePayouts
php artisan queue:work --queue=payout-checks      # asks the provider about transfers it accepted
```

If `payout-checks` has no worker, sales still pay out. What stops is reconciliation: a transfer
the provider accepted and never confirmed stays `processing` with nobody asking again, and the
"unconfirmed" alert never fires. Check for a growing `processing` count before looking anywhere
else. The queue is named in every `custody.payout.check_queued` log line, so a misconfigured
deployment says so itself.

A provider that stops answering is handled separately: `custody.payout_lookup_breaker_seconds`
(60 by default) opens after one unreachable look-up, and checks in that window are answered
without an HTTP call. The ladder still runs and still alerts — the breaker saves the worker,
never the warning.

## The one-time clear-up

Three commands, all for history rather than for running the platform: one repairs the bank codes
already on file, the second closes out the payouts left open by the breakage, and the third — only
on a platform that is still being tested — takes the balances off the books entirely. Run them in
this order — the first is what makes the *next* payout go out with the right number, the second
gives back the money that was reserved against sends nothing was ever going to make, and the third
is only ever about erasing test money, so it must be the last thing done to a database.

All three are safe to run with a worker going, and none of them moves money to a provider.

### 1. Work out every stored bank account's code at each provider

A business names its bank once, out of the picker — which is Paystack's list, and its code is what
`store_banks` holds. Payouts leave through whichever processor is selected, which numbers the same
institutions differently: Opay is `999992` at Paystack and `305` at Bachs, PalmPay `999991` versus
`100033`, Moniepoint `50515` versus `090405`. Legacy banks agree by luck (Zenith `057`, GTB `058`);
the fintechs do not, and a shop banking with Opay is the likeliest merchant on the platform. That
mismatch is what made payouts fail with `BANK_NOT_PAYABLE`, which the platform then filed as a
provider fault.

```bash
php artisan custody:resolve-payout-banks            # what it would do, and writes nothing
php artisan custody:resolve-payout-banks --apply
```

Accounts named *after* this change are worked out as they are created, so this is a repair for the
backlog. `--business={id}` and `--provider={code}` narrow it.

Per account per provider the run reports one of:

| | |
|---|---|
| `resolved` | registered at the provider and payable; the code may have been translated |
| `would-resolve` | a dry run worked the code out; nothing was registered |
| `unchanged` | already resolved and payable — the provider is not asked again, deliberately |
| `refused` | this provider cannot pay that bank. The business needs a different account |
| `unchecked` | the provider could not be reached, or answered with no banks at all. Run it again |

An answer that came back empty is `unchecked` and not `refused`: both providers carry hundreds of
banks, so a list with none in it means we failed to read the list, not that the business's bank has
been withdrawn. Said the other way round — `refused` is the only outcome worth telling a business
about, and it is only ever reached from a list that actually arrived.

**`--apply` registers a destination at the provider**, which is the point — it also stops the
accumulation of a new destination on every single payout, because Bachs does not deduplicate and
the registration now happens once per account. An account already resolved is left alone rather
than re-registered, including under `--provider`. For the same reason, a business pointing at a
*different* one of its own accounts is checked against the row already on file rather than by asking
the provider again.

On each row: a non-null `failure_reason` with `is_usable` false is the provider's own refusal, and
`is_usable` null with a reason is our failure to get an answer. Only the first says anything about
the business's bank, so it is the only one a business is ever shown.

The command never deletes a row. An account whose bank cannot be resolved keeps its row with the
reason on it: a missing row cannot be told apart from one nobody has got to yet, which is the state
this exists to end. To force an account to be re-checked, delete its `payout_bank_codes` row first —
that is deliberate, because re-registering at Bachs creates a second destination.

### 2. Close out the payouts that nothing is going to send

A payout row is a claim on money — the reservation comes off the wallet and the sales behind it are
committed, and only the row reaching a terminal status gives either back. So a row left open is
money the business cannot see, holding the promotion budget down and making it look blocked, and
once the queue has used its three attempts and alerted, nothing else will ever move it.

```bash
php artisan custody:close-outstanding-payouts                 # lists them and asks first
php artisan custody:close-outstanding-payouts --force         # for a scripted run
```

| Status | What the command does |
|---|---|
| `pending` | reserved and never handed to a provider — closes it and **credits the money back to the wallet** |
| `failed` | already had its money back — closes the row so it stops counting against the business |
| `processing` | **left alone.** Its money may still be in flight |

`processing` is the one that matters. That row *was* given to a provider, and closing it credits the
business for a transfer that may still land — they would be paid twice, by us. Those rows are listed
and left open, and their route out is the provider's webhook or `custody:payouts`, which asks the
provider what became of them. `--include-processing` overrides it, once you have confirmed with the
provider that the transfer is gone.

`--older-than=5` (the default) skips anything touched in the last five minutes, because a row being
sent right now has just been stamped as sent: closing that one would credit the money back while the
transfer goes out anyway. `--older-than=0` disables the guard — only for a run you know is quiet.

### 3. Take every open settlement off the books (test platforms only)

**Do not run this on a live platform.** A collected sale *is* the business's money until the customer
is given it back, and the only mark that says otherwise is the one a refund leaves — so clearing a
balance means marking sales unwound for customers who were never refunded. On real money that is an
accounting event, not maintenance. It exists because a test database's balances get in the way of
reading what the payout path actually does.

```bash
php artisan custody:void-test-sales                            # lists everything and asks first
php artisan custody:void-test-sales --force                    # for a scripted run
php artisan custody:void-test-sales --older-than=0 --force     # when you know nothing is sending
```

It makes two movements, in this order, and the order is the whole design:

1. **Every open payout is closed** (the command in §2, same query, same rules) — the money reserved
   against a transfer that will never complete goes back to the wallets it came from, and the sales
   behind it stop being claimed.
2. **Every sale still standing is voided** through the same routine a refund uses, so the wallet and
   the journal move together and a sale cannot be voided twice.

The two cancel: the close credits the wallets, the void debits them again, and what is left is the
platform owing nothing rather than the platform having paid out. Run either half alone and you get
the wrong thing — a business paid, or a debt that does not exist.

Per sale, one of:

| | |
|---|---|
| `took back from available` | the money had matured; it is debited from the spendable balance |
| `took back from pending` | credited but not yet matured, so it comes out of the pending balance |
| `of those, N held no balance` | collected by the provider and never credited — nothing moved, and the sale is marked only so the catch-up pass cannot credit it later |
| `already paid` | a payout landed and the money is in the business's bank account. Untouched, deliberately: debiting the wallet would leave them owing Storify for money they received |
| `in flight` | a payout is still carrying it. Untouched until the provider says what became of it |

The closing lines print what the platform owed before and after, and — when a balance is still
standing — the businesses still holding it, by name. A remainder is not a failure: it is money on a
sale the command deliberately left alone, or a negative left behind by an earlier refund.

`--business={id}` narrows it. `--include-processing` also closes payouts already handed to a
provider, which may still land — the one flag that can pay a business twice, so it names the amount
at risk before asking. `--older-than=5` (the default) is the same quiet window §2 keeps.

A voided sale reads as **`refunded`**, because `unwound_at` is the only mark that stops money being
credited for it later. The journal says which it was: the reference posted is the command's own name
(`Test cleanup by custody:void-test-sales`), so nobody reading the ledger months later mistakes a
wiped test balance for a customer who was repaid.

All three commands are idempotent. Running any of them twice moves no money the second time.

## The numbers to watch

| Where | What it means |
|---|---|
| `custody:promote` → `unpriced` | Sales that settled before the provider said what it charged. A number that only grows means businesses are not being credited. |
| `custody:promote` → "the provider's balance could not be read" | The gate fell back to the clock. Money is still moving, on a window nobody verified — worth a look at the provider's API before it becomes routine. |
| `custody:promote` → "the provider has not released the money behind them" | Salable money is behind a provider that has not funded it. Normal briefly and not a bug; if it is standing, the provider is the conversation. The `Gate (NGN)` line above it prints the arithmetic that produced the shortfall — provider balance, less what is owed, less transfers in flight, less the fee headroom — so which term consumed the budget is readable rather than inferred. |
| `custody:payouts` → `on hold` | Deliberate: an operator, a suspension, or a rail incident. The holds are listed with their reasons, and the money is in the wallet the whole time. If this line names a business nobody meant to hold, that is the bug. |
| `custody:payouts` → `held` | The *business's own* switch (`auto_payout`), not an operator's. Only its owner can turn it back on. |
| `custody:payouts` → `skipped` | Storify is holding money it cannot hand over: no payout bank, or another run took the balance. The first needs chasing. |
| `custody:payouts` → `blocked` | The provider keeps refusing this business's bank, so the send has stopped asking. Fix the destination, or press **Retry** on the admin Payouts screen (or run `custody:retry-payouts`); the money is safe in the wallet until then. |
| `custody:payouts` → `unresolved` | Payouts the provider accepted but never confirmed. Normal briefly; their wallet reservations stay held. |
| `custody.payout.instant_withheld` | A sale was paid for and the payout was not attempted, with the reason. Expected if a hold or `auto_payout` is off; a run of these for one business is that business's money not moving. |
| `custody.payout.instant` with `outcome: null` | Nothing was sent for a sale that *was* settled. Read `matured` beside it: zero means the promotion gate held the money back — a provider balance that could not cover it, or one that could not be read — and a later attempt will pick it up. The `gate` object on the same line is the arithmetic behind that answer: `available`, `liability`, `in_flight` and `headroom`, and their `budget_kobo` result. |
| `custody.payout.retry` | The send is coming back. Read `gate` for why the budget was what it was, and `measured`: `false` is a provider that could not be read and is the provider being down; `true` with an `available` of zero is a provider that answered and is simply holding nothing yet. |
| `custody.payout.settling` | Not an error. The provider has not released the charge and the platform is waiting on the settlement clock rather than the retry one; `check` and `of` say how far down the chain this is. A run of these for one business that never reaches `of` is worth a look at the provider's dashboard. |
| `custody.promote.balance_unreadable` | Both the instant path and the run fell back to the clock. Money is still moving, on a window nobody verified. |
| `ledger:reconcile` | Weekly scan. Under custody it also compares each business's wallet against its `1040 Funds Held by Storify` ledger lines. |
| Enforcement pre-flight | Two lists an admin works through: `businesses_awaiting_custody` (not on the new rail — an operator's step) and `businesses_cannot_be_paid` (trading happily with no bank named, so the money is accumulating). |
