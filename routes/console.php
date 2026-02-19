<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessScheduledFamilyPackDeliveries;
use App\Jobs\SendPaymentReminder;
use App\Jobs\CancelUnpaidDelivery;
use App\Jobs\ProcessTrialExpirations;
use App\Models\VendorSubscription;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Family Pack Schedules
Schedule::job(new ProcessScheduledFamilyPackDeliveries)->daily();

// Payment Reminders
Schedule::job(new SendPaymentReminder('pre_delivery'))->dailyAt('09:00'); // -1 day
Schedule::job(new SendPaymentReminder('delivery_day'))->dailyAt('09:00'); // 0 day
Schedule::job(new SendPaymentReminder('overdue'))->dailyAt('09:00');      // +1 day

// Auto-cancel unpaid deliveries
Schedule::job(new CancelUnpaidDelivery)->dailyAt('23:59');

// Trial expiry reminders & store deactivation — only runs when there are active trials
Schedule::job(new ProcessTrialExpirations)
    ->dailyAt('08:00')
    ->when(fn () => VendorSubscription::active()
        ->whereHas('subscriptionPlan', fn ($q) => $q->where('is_trial', true))
        ->exists()
    );

