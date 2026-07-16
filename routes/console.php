<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessScheduledFamilyPackDeliveries;
use App\Jobs\SendPaymentReminder;
use App\Jobs\CancelUnpaidDelivery;
use App\Jobs\ProcessTrialExpirations;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Family Pack Schedules
Schedule::job(new ProcessScheduledFamilyPackDeliveries)->daily();

// Payment Reminders
Schedule::job(new SendPaymentReminder('pre_delivery'))->dailyAt('09:00');
Schedule::job(new SendPaymentReminder('delivery_day'))->dailyAt('09:00');
Schedule::job(new SendPaymentReminder('overdue'))->dailyAt('09:00');

// Auto-cancel unpaid deliveries
Schedule::job(new CancelUnpaidDelivery)->dailyAt('23:59');

// Trial expiry reminders & store deactivation — runs when users are on trial
Schedule::job(new ProcessTrialExpirations)
    ->dailyAt('08:00')
    ->when(fn () => User::whereNotNull('trial_ends_at')->exists());
