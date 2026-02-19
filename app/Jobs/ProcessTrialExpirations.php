<?php

namespace App\Jobs;

use App\Mail\TrialExpiryReminderMail;
use App\Mail\TrialExpiredMail;
use App\Models\VendorSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessTrialExpirations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        $trialSubscriptions = VendorSubscription::active()
            ->whereHas('subscriptionPlan', fn ($q) => $q->where('is_trial', true))
            ->with(['vendor', 'subscriptionPlan'])
            ->get();

        if ($trialSubscriptions->isEmpty()) {
            Log::info('trial_expirations.no_active_trials');
            return;
        }

        Log::info('trial_expirations.processing', [
            'count' => $trialSubscriptions->count(),
        ]);

        foreach ($trialSubscriptions as $subscription) {
            try {
                $this->processSubscription($subscription);
            } catch (\Throwable $e) {
                Log::error('trial_expirations.subscription_error', [
                    'subscription_id' => $subscription->id,
                    'vendor_id' => $subscription->vendor_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processSubscription(VendorSubscription $subscription): void
    {
        $daysElapsed = (int) $subscription->starts_at->diffInDays(now());
        $trialDays = $subscription->subscriptionPlan->trial_days ?? 7;
        $daysRemaining = $trialDays - $daysElapsed;
        $vendor = $subscription->vendor;

        if (!$vendor || !$vendor->email) {
            return;
        }

        // Day 8+ — trial expired
        if ($daysRemaining <= 0 && !$subscription->trial_expired_sent_at) {
            $this->expireTrial($subscription);
            return;
        }

        // Day 5 — 2 days remaining
        if ($daysElapsed >= ($trialDays - 2) && $daysRemaining > 0 && !$subscription->trial_reminder_day5_sent_at) {
            Mail::to($vendor->email)->queue(new TrialExpiryReminderMail($subscription, min($daysRemaining, 2)));
            $subscription->update(['trial_reminder_day5_sent_at' => now()]);

            Log::info('trial_expirations.reminder_sent', [
                'subscription_id' => $subscription->id,
                'vendor_id' => $vendor->id,
                'day' => 'day5',
                'days_remaining' => $daysRemaining,
            ]);
        }

        // Day 6 — 1 day remaining
        if ($daysElapsed >= ($trialDays - 1) && $daysRemaining > 0 && !$subscription->trial_reminder_day6_sent_at) {
            Mail::to($vendor->email)->queue(new TrialExpiryReminderMail($subscription, min($daysRemaining, 1)));
            $subscription->update(['trial_reminder_day6_sent_at' => now()]);

            Log::info('trial_expirations.reminder_sent', [
                'subscription_id' => $subscription->id,
                'vendor_id' => $vendor->id,
                'day' => 'day6',
                'days_remaining' => $daysRemaining,
            ]);
        }

        // Day 7 — last day
        if ($daysElapsed >= $trialDays && $daysRemaining <= 0 && !$subscription->trial_reminder_day7_sent_at) {
            // This is the last day - will expire tomorrow
            Mail::to($vendor->email)->queue(new TrialExpiryReminderMail($subscription, 0));
            $subscription->update(['trial_reminder_day7_sent_at' => now()]);

            Log::info('trial_expirations.reminder_sent', [
                'subscription_id' => $subscription->id,
                'vendor_id' => $vendor->id,
                'day' => 'day7',
                'days_remaining' => 0,
            ]);
        }
    }

    private function expireTrial(VendorSubscription $subscription): void
    {
        $vendor = $subscription->vendor;

        // Expire the subscription
        $subscription->update([
            'status' => VendorSubscription::STATUS_EXPIRED,
            'trial_expired_sent_at' => now(),
        ]);

        // Deactivate all vendor stores
        $stores = $vendor->stores()
            ->where('status', \App\Models\Store::STATUS_ACTIVE)
            ->get();

        foreach ($stores as $store) {
            $store->update(['status' => \App\Models\Store::STATUS_PENDING]);
        }

        // Send expiry email
        Mail::to($vendor->email)->queue(new TrialExpiredMail($subscription));

        Log::info('trial_expirations.trial_expired', [
            'subscription_id' => $subscription->id,
            'vendor_id' => $vendor->id,
            'stores_deactivated' => $stores->count(),
        ]);
    }
}
