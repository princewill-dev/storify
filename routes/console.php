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
