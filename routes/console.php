<?php

use App\Jobs\ProcessTrialExpirations;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Trial expiry reminders & store deactivation — runs when users are on trial
Schedule::job(new ProcessTrialExpirations)
    ->dailyAt('08:00')
    ->when(fn () => User::whereNotNull('trial_ends_at')->exists());

// Booked plan downgrades, moved onto their new plan once the paid period ends.
//
// A downgrade is recorded rather than applied — the period it lands in was paid
// for at the dearer plan's price — so this run is the only thing that ever keeps
// that promise. Not guarded by a `when()`: with nothing booked there is nothing
// to find, and a stopped scheduler should show up as an owner stuck on a plan
// they have left rather than as a silently skipped condition.
Schedule::command('subscriptions:apply-scheduled')
    ->dailyAt('00:20')
    ->withoutOverlapping();

// Weekly ledger integrity scan (report only — backfill with ledger:reconcile --post)
Schedule::command('ledger:reconcile')
    ->weeklyOn(1, '06:00');

// Daily exchange rates — what lets a naira store be charged through a USD
// gateway. Not guarded by a `when()`: with no rate the USD provider is simply
// withheld, and a missing job is a failure worth logging every day, not a
// condition to skip.
Schedule::command('rates:fetch')
    ->dailyAt('05:15')
    ->withoutOverlapping();

// ## The money path is not on this schedule, and that is deliberate
//
// There were two custody runs here — `custody:promote` every five minutes and
// `custody:payouts` every five minutes — and they are gone. Paying a business used
// to mean waiting for them, which meant a sale collected at 12:05:16 was paid at
// 12:10 for no reason but our clock.
//
// What replaced them is one queued job, `App\Jobs\SettlePayouts`, dispatched by the
// three paths that collect a sale (the provider's webhook, the storefront's return
// leg, the POS till). It matures the business's due sales, reads the restrictions and
// sends, all on a worker rather than on the request thread, and it owns the retry
// schedule (`custody.retry_backoff_minutes`) and the admin alert when that is spent.
//
// **Both commands still exist and are still useful, as tools.** `custody:promote`
// and `custody:payouts` are what an operator reaches for during an incident —
// `--dry-run` and the formatted report are the point, and nothing calls them on a
// clock. So: never re-add them here. A second, out-of-band payer racing the job would
// not double-pay (the reservation refuses to overdraw) but it would make the log
// unreadable and put a provider call back on somebody's schedule for no benefit.
//
// The trade this makes, stated rather than hidden: a worker that is not running, or a
// job whose insert failed, means a sale is credited and payable and nothing sends it.
// `queue:work` is load-bearing for money now, not just for email.
//
// There used to be three more runs here — `connect:payouts`, `connect:requirements`
// and `connect:payees` — which withdrew from a business's own account at the provider
// and kept that account's requirements submitted. The accounts do not exist any more,
// so neither do those runs.
