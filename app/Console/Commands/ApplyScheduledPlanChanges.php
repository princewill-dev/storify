<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\ActivityRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Move every subscription whose booked downgrade has come due onto the plan it
 * booked.
 *
 * A downgrade is recorded rather than applied — `PlanSelectionService::schedule()`
 * — because the period it takes effect in has already been paid for at the old
 * plan's price, and taking it away early would be taking back time somebody
 * bought. That leaves exactly one thing needing to happen on a clock, and this
 * is it: without a run, a booked downgrade is a promise the platform never keeps
 * and the business is billed for the dearer plan forever.
 *
 * ## What it deliberately does not do
 *
 * It does not extend, truncate or otherwise touch `expires_at`. The plan a
 * business is on does not decide when its paid period ends; the money that
 * bought the period does. A downgrade therefore takes effect inside the period
 * it lands in, and the next renewal is priced at the new plan.
 *
 * It does not touch a subscription whose downgrade was cancelled — an upgrade
 * clears the booked pair, so there is nothing here to find.
 *
 * A booked plan that is later deleted is handled by the schema rather than here:
 * `subscriptions.scheduled_plan_id` is a `nullOnDelete` foreign key, so the
 * booking clears itself and the business simply stays on the plan it has. The
 * null check below only guards the window between this run's eager load and its
 * own re-read, and it skips rather than throwing: a fatal on a nightly sweep
 * would stop every other subscription in the batch.
 *
 * ## Why it is safe to run on any cadence
 *
 * Each row is re-read under `lockForUpdate` inside its own transaction and the
 * conditions are re-checked there, so a second run — or a run overlapping an
 * admin assigning a plan by hand — finds nothing to do rather than applying a
 * stale decision over a newer one.
 */
class ApplyScheduledPlanChanges extends Command
{
    protected $signature = 'subscriptions:apply-scheduled';

    protected $description = 'Move subscriptions onto their booked plan once the paid period ends';

    public function handle(): int
    {
        $applied = 0;

        Subscription::query()
            // The booked downgrade, and the plan it names, in one query rather
            // than a lazy load per row.
            ->with('scheduledPlan')
            ->whereNotNull('scheduled_plan_id')
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($subscriptions) use (&$applied) {
                foreach ($subscriptions as $subscription) {
                    if ($this->applyOne($subscription->id) === 'applied') {
                        $applied++;
                    }
                }
            });

        $this->info("Applied {$applied} scheduled plan change(s).");

        Log::info('subscriptions.scheduled_plan_changes_applied', ['applied' => $applied]);

        return self::SUCCESS;
    }

    /**
     * @return string 'applied' or 'skipped'
     */
    private function applyOne(int $subscriptionId): string
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = Subscription::query()->lockForUpdate()->find($subscriptionId);

            // Gone, or already dealt with by a concurrent run or an admin.
            if ($subscription === null || $subscription->scheduled_plan_id === null) {
                return 'skipped';
            }

            $target = $subscription->scheduledPlan;

            if ($target === null) {
                Log::warning('subscriptions.scheduled_plan_missing', [
                    'subscription_id' => $subscription->id,
                    'scheduled_plan_id' => $subscription->scheduled_plan_id,
                ]);

                return 'skipped';
            }

            $current = $subscription->subscriptionPlan;

            $subscription->update([
                'subscription_plan_id' => $target->id,
                'scheduled_plan_id' => null,
                'scheduled_at' => null,
            ]);

            ActivityRecorder::record(
                action: 'subscription_plan_downgrade_applied',
                description: "Subscription moved to '{$target->name}' for business "
                    ."'{$subscription->business?->name}' at the end of the paid period "
                    ."(was '".($current?->name ?? 'unknown')."')",
                subject: $subscription,
                old: ['plan' => $current?->name],
                new: ['plan' => $target->name],
            );

            Log::info('subscription.plan_change_applied', [
                'subscription_id' => $subscription->id,
                'old_plan_id' => $current?->id,
                'new_plan_id' => $target->id,
            ]);

            return 'applied';
        });
    }
}
