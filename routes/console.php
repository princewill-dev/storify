<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessTrialExpirations;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Trial expiry reminders & store deactivation — runs when users are on trial
Schedule::job(new ProcessTrialExpirations)
    ->dailyAt('08:00')
    ->when(fn () => User::whereNotNull('trial_ends_at')->exists());
