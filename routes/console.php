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

// Withdraw settled money from businesses' connected provider accounts to their
// banks. Hourly rather than daily because the provider settles on its own
// schedule and this is what turns "sold" into "in their bank" — the promise the
// managed model makes is that nobody has to click anything. A run with nothing
// settled costs one balance read per account and moves no money; a payout
// requested too early is simply refused. Deliberately *not* guarded by a
// `when()`: the cheapest way to discover the sweep has stopped is a business
// asking where its money is.
Schedule::command('connect:payouts')
    ->hourly()
    // Ten minutes, not the scheduler's default of twenty-four hours: a run that
    // hangs on a provider call must not block the next hour's, and the next run
    // is what catches whatever is left.
    ->withoutOverlapping(10);

// Fill in whatever the provider is still asking connected accounts for — a
// field it has just started requiring, a business that completed KYC after
// registering, a submission that failed while the provider was unreachable.
//
// Scheduled rather than event-driven because the requirements move on the
// provider's side: nothing of ours changes when Bachs decides it wants a
// product description, so there is no event of ours to hook. Every pass reads
// the account first and submits only what is currently outstanding, so a run
// with nothing to do costs one read per account and writes nothing.
//
// After the payout sweep rather than before it, so that an account cleared by
// this run is paid by the *next* sweep — a payout in the same minute as the
// notification that says the money is movable is the case most likely to be
// refused for being a moment too early.
Schedule::command('connect:requirements')
    ->hourlyAt(20)
    // Shorter than the gap between runs, for the same reason the sweep's is:
    // one account stuck on an unresponsive provider call must not skip the hour.
    ->withoutOverlapping(10);

// Point each business's account at the bank account it named, for the two ways
// those come apart after the fact: an admin changing the processor, which leaves
// businesses named at one provider and enforced at another, and a registration
// whose destination call failed while the account itself was created.
//
// It creates no accounts — a provider's entity type and terms acceptance are
// decided once, and terms are not accepted on anybody's behalf, so account
// creation stays in the business's own session. A business with no account at
// the processor in force is reported and skipped, and the step it sees on its
// next visit is what closes that.
//
// Cheap enough to run every hour: it compares the stored destination with the
// account on file and makes no provider call unless the two disagree, so a
// platform with nothing out of step costs one local read per connection. Not
// guarded by a `when()` for the same reason the sweep is not — the cheapest way
// to learn this has stopped running is a business whose money is not moving.
Schedule::command('connect:payees')
    // After the requirement pass and well clear of the sweep's own minute, so a
    // destination registered here is picked up by the next hour's payout rather
    // than racing it.
    ->hourlyAt(40)
    ->withoutOverlapping(10);
