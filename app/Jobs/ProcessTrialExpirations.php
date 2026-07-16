<?php

namespace App\Jobs;

use App\Mail\TrialExpiryReminderMail;
use App\Mail\TrialExpiredMail;
use App\Models\User;
use App\Models\Setting;
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
        $settings = Setting::first();
        $trialEnabled = $settings?->trial_enabled ?? true;
        $trialDays = (int) ($settings?->trial_days ?? 7);

        if (!$trialEnabled) {
            return;
        }

        $users = User::whereNotNull('trial_ends_at')
            ->whereDoesntHave('business.subscriptions', fn ($q) => $q->where('status', 'active')->where('expires_at', '>', now()))
            ->where('role', '!=', 'staff')
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Log::info('trial_expirations.processing', ['count' => $users->count()]);

        foreach ($users as $user) {
            try {
                $this->processUser($user, $trialDays);
            } catch (\Throwable $e) {
                Log::error('trial_expirations.user_error', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processUser(User $user, int $trialDays): void
    {
        if (!$user->trial_ends_at) {
            return;
        }

        $daysRemaining = (int) now()->diffInDays($user->trial_ends_at, false);
        $daysSinceExpiry = $daysRemaining < 0 ? abs($daysRemaining) : 0;

        if ($daysRemaining > 0) {
            if ($daysRemaining <= 3 && $daysRemaining >= 1) {
                $this->sendReminder($user, $daysRemaining);
            }
            return;
        }

        $windDownDays = 3;
        if ($daysSinceExpiry >= $windDownDays) {
            $this->expireTrial($user);
        } elseif ($daysSinceExpiry >= 0) {
            $this->sendReminder($user, 0);
        }
    }

    private function sendReminder(User $user, int $daysRemaining): void
    {
        if (!$user->email) {
            return;
        }

        Mail::to($user->email)->queue(new TrialExpiryReminderMail($user, $daysRemaining));

        Log::info('trial_expirations.reminder_sent', [
            'user_id' => $user->id,
            'days_remaining' => $daysRemaining,
        ]);
    }

    private function expireTrial(User $user): void
    {
        $stores = $user->stores()
            ->where('status', \App\Models\Store::STATUS_ACTIVE)
            ->get();

        foreach ($stores as $store) {
            $store->update(['status' => \App\Models\Store::STATUS_PENDING]);
        }

        if ($user->email) {
            Mail::to($user->email)->queue(new TrialExpiredMail($user));
        }

        Log::info('trial_expirations.trial_expired', [
            'user_id' => $user->id,
            'stores_deactivated' => $stores->count(),
        ]);
    }
}
