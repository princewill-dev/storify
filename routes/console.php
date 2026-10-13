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

// Mature collected sales from the pending half of a business's wallet to the
// payable half, once the provider's balance actually covers them.
//
// First of the two custody runs, and registered first for the same reason it has
// always run first: a sale promoted here is a sale the payout run below can send in
// the same tick. Laravel runs same-minute entries in registration order, so putting
// this one first is what makes the two-in-one-tick ordering work rather than a
// coincidence of the clock.
//
// **Five minutes, not an hour, and that is the whole point of the change.** The
// delay a business felt was three stacked waits — an assumed 48h settlement window,
// then an hourly promote, then an hourly payout — and the providers were not the
// slow part. With the window measured against the provider's own balance, the
// cadence is now the dominant delay, so it stops being an hour.
//
// This and `custody:payouts` below are the only money-movement sweeps left.
// There used to be three more here — `connect:payouts`, `connect:requirements`
// and `connect:payees` — which withdrew from a business's own account at the
// provider and kept that account's requirements submitted. The accounts do not
// exist any more, so neither do the runs.
Schedule::command('custody:promote')
    // A three-minute lock, not the ten the hourly schedule used: the gap between
    // runs is now five minutes, and a lock that outlived it would silently skip
    // ticks rather than delay them.
    ->everyFiveMinutes()
    ->withoutOverlapping(3);

// Pay every business Storify collects for out of its own provider balance, then
// chase every payout that is still unresolved.
//
// Deliberately *not* guarded by a `when()`: the cheapest way to discover the sweep
// has stopped is a business asking where its money is.
Schedule::command('custody:payouts')
    ->everyFiveMinutes()
    ->withoutOverlapping(3);
