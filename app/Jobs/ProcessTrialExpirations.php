<?php

namespace App\Jobs;

use App\Mail\TrialExpiredMail;
use App\Mail\TrialExpiryReminderMail;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionTrialSettings;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * WS-09 — trial reminders, the day-0 notice and the +3-day wind-down.
 *
 * Ported from legacy `App\Jobs\ProcessTrialExpirations` with its two defects
 * fixed rather than cloned:
 *  - the legacy job ignored its own `trial_reminder_day5/6/7_sent_at` markers,
 *    so the same reminder went out on every scheduler tick inside the window;
 *  - the expiry stage paused the stores and mailed again on every tick.
 * Every stage here is recorded once per trial (see `oncePerTrial`), so a
 * daily — or even hourly — schedule is safe.
 *
 * Stage map for the platform trial: 3 days left fires the day-5 reminder,
 * 2 days left day-6, 1 day left day-7, the expiry day gets its own day-0
 * notice, and at expiry + PAUSE_AFTER_EXPIRY_DAYS the business's active stores
 * are set back to `pending` (the storefront goes dark) and TrialExpiredMail is
 * queued. Users with an active subscription and non-owners are skipped
 * entirely, and the whole job is a no-op when the platform trial is disabled.
 */
class ProcessTrialExpirations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Days before expiry at which daily reminders start. */
    public const REMINDER_WINDOW_DAYS = 3;

    /** Days after expiry before the business's stores are paused. */
    public const PAUSE_AFTER_EXPIRY_DAYS = 3;

    /**
     * Marker column per reminder stage. The columns predate the
     * `users.trial_ends_at` trial model and are named for a 7-day trial
     * (day 5/6/7 == 3/2/1 days left). The day-0 notice has no column of its
     * own and relies on the cache guard.
     *
     * @var array<string, string|null>
     */
    private const REMINDER_COLUMNS = [
        'day5' => 'trial_reminder_day5_sent_at',
        'day6' => 'trial_reminder_day6_sent_at',
        'day7' => 'trial_reminder_day7_sent_at',
        'day0' => null,
    ];

    public function handle(SubscriptionTrialSettings $trialSettings): void
    {
        if (! $trialSettings->get()['enabled']) {
            return;
        }

        $users = User::query()
            ->whereNotNull('trial_ends_at')
            ->where('role', User::ROLE_BUSINESS_OWNER)
            ->whereDoesntHave('business.subscriptions', fn ($query) => $query->active())
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Log::info('trial_expirations.processing', ['count' => $users->count()]);

        foreach ($users as $user) {
            try {
                $this->processUser($user);
            } catch (Throwable $e) {
                Log::error('trial_expirations.user_error', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processUser(User $user): void
    {
        // A payment can land between the query and the loop.
        if ($user->business?->hasActiveSubscription()) {
            return;
        }

        $endsAt = $user->trial_ends_at;

        if ($endsAt === null) {
            return;
        }

        if ($endsAt->isFuture()) {
            // Rounded, not floored: timestamps lose sub-second precision in
            // MySQL, so a trial ending "in 2 days" can diff to 1.9999 and
            // flooring would skip a day of the reminder cadence.
            $daysLeft = (int) round(Carbon::now()->diffInDays($endsAt, false));

            $slot = match ($daysLeft) {
                self::REMINDER_WINDOW_DAYS => 'day5',
                2 => 'day6',
                1 => 'day7',
                default => null,
            };

            if ($slot !== null) {
                $this->sendReminderOnce($user, $slot, $daysLeft);
            }

            return;
        }

        // Lapsed. The stores stay live through the grace window; the final
        // day of that window is the day-0 "expires today" notice.
        if ($endsAt->copy()->addDays(self::PAUSE_AFTER_EXPIRY_DAYS)->isFuture()) {
            $this->sendReminderOnce($user, 'day0', 0);

            return;
        }

        $this->expireTrialOnce($user);
    }

    private function sendReminderOnce(User $user, string $slot, int $daysRemaining): void
    {
        if (! $user->email) {
            return;
        }

        $this->oncePerTrial($user, 'reminder_'.$slot, self::REMINDER_COLUMNS[$slot] ?? null, function () use ($user, $daysRemaining) {
            Mail::to($user->email)->queue(new TrialExpiryReminderMail($user, $daysRemaining));

            Log::info('trial_expirations.reminder_sent', [
                'user_id' => $user->id,
                'days_remaining' => $daysRemaining,
            ]);
        });
    }

    private function expireTrialOnce(User $user): void
    {
        $this->oncePerTrial($user, 'expired', 'trial_expired_sent_at', function () use ($user) {
            if ($user->business_id !== null) {
                // Legacy paused `$user->stores()` (stores owned by the user), so
                // a store created by a staff member stayed live after the trial
                // lapsed. Pause every store the business owns.
                $stores = Store::query()
                    ->where('business_id', $user->business_id)
                    ->where('status', Store::STATUS_ACTIVE)
                    ->get();

                foreach ($stores as $store) {
                    $store->update(['status' => Store::STATUS_PENDING]);
                }
            } else {
                $stores = collect();
            }

            if ($user->email) {
                Mail::to($user->email)->queue(new TrialExpiredMail($user));
            }

            Log::info('trial_expirations.trial_expired', [
                'user_id' => $user->id,
                'stores_deactivated' => $stores->count(),
            ]);
        });
    }

    /**
     * Run a stage exactly once per trial.
     *
     * The legacy markers live on subscriptions, but a trial has no
     * subscription row (the trial state is `users.trial_ends_at`), which is
     * why the shipped legacy job never read them. Record the send on the
     * marker subscription when the user has one, and fall back to a cache key
     * scoped to the trial's end date when they do not — either way the stage
     * fires once per trial, not once per scheduler tick.
     */
    private function oncePerTrial(User $user, string $key, ?string $column, Closure $callback): void
    {
        $marker = $this->markerSubscription($user);

        if ($column !== null && $marker !== null && $marker->{$column} !== null) {
            return;
        }

        $stamp = $user->trial_ends_at?->toISOString() ?? 'none';
        $cacheKey = "trial_lifecycle:{$key}:{$user->id}:{$stamp}";

        // Keyed by stage and trial end, so a long retention is safe: a stage
        // is never re-issued for the same trial, however often the scheduler
        // ticks. (A shorter TTL would let the day-0 notice repeat on the third
        // day of the grace window.)
        if (! Cache::add($cacheKey, Carbon::now()->toISOString(), Carbon::now()->addDays(30))) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $e) {
            // The claim is taken before the send. Release it so a transient
            // mail/queue failure retries on the next tick instead of dropping
            // this stage of the trial silently.
            Cache::forget($cacheKey);

            throw $e;
        }

        if ($column !== null && $marker !== null) {
            $marker->forceFill([$column => Carbon::now()])->save();
        }
    }

    private function markerSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }
}
