<?php

namespace App\Services\Payments\Custody;

use App\Jobs\SettlePayouts;
use App\Models\Business;
use App\Models\PayoutHold;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stopping payouts on purpose, at the width the investigation needs.
 *
 * Three scopes, and they exist because the answers are genuinely different
 * questions:
 *
 * | Scope | Stops | Asked when |
 * |---|---|---|
 * | `system` | every payout on the platform | something is wrong with the rail itself |
 * | `business` | one business's payouts, every store | a dispute about that business |
 * | `store` | that store's share, and pays the rest | a dispute about one store's orders |
 *
 * The store scope is deliberately not a softer version of the business one: the
 * agreed behaviour is that a held store's sales are excluded from the walk that
 * builds a payout, and **the business is paid everything else normally**. Withholding
 * a whole business's money because one of its stores is being looked at would
 * punish sales that have nothing to do with the question.
 *
 * ## It stops money, and it never moves it
 *
 * Nothing here reads or writes a balance. A held business is still charged, still
 * credited, still promoted — the money is still counted, still owed, and still
 * visible on its wallet. All that changes is that the send skips it, so lifting
 * the hold pays it out with nothing to undo. That is also why a
 * hold cannot strand a `processing` row: the reconciler and the webhook path never
 * consult a hold, so a payout already at the provider still resolves.
 *
 * ## Idempotent by construction, and per source
 *
 * Placing a hold on something already held returns the existing hold rather than
 * failing, and the unique index on the table makes that true even when two people
 * press the button at once. Suspending a business places a hold; suspending it
 * twice is one hold, which is why reactivating it cannot leave a stray one behind.
 *
 * "Already held" means already held *by the same source*, and that distinction is
 * the whole reason a subject can carry two open holds at once. An operator can hold
 * a business to look into something and an admin can then suspend it; those are two
 * decisions with two reasons, and collapsing them into one row would mean
 * reactivating the business either silently lifts the operator's hold or silently
 * does nothing and leaves the business unpayable with the reason buried in a field
 * nobody reads. So the index covers the source, and `release()` narrows by it:
 * reactivation lifts the suspension's hold and leaves the operator's alone.
 *
 * Holding a subject that is already held for the *same* reason twice is the no-op
 * that keeps a double-click from writing two rows.
 */
final class PayoutHoldService
{
    /**
     * Stop every payout on the platform.
     *
     * The incident switch. Its own method rather than a scope argument elsewhere
     * because it is reached for in a hurry and by somebody who is not thinking
     * about business ids.
     */
    public function holdSystem(string $reason, ?User $actor = null): PayoutHold
    {
        return $this->place(PayoutHold::SCOPE_SYSTEM, 0, 0, $reason, PayoutHold::SOURCE_MANUAL, $actor);
    }

    public function holdBusiness(Business $business, string $reason, ?User $actor = null, string $source = PayoutHold::SOURCE_MANUAL): PayoutHold
    {
        return $this->place(PayoutHold::SCOPE_BUSINESS, (int) $business->id, 0, $reason, $source, $actor);
    }

    /**
     * Withhold one store's share, and keep paying the business the rest.
     *
     * The business id is recorded on the row as well as the store id, so the send
     * can answer "what is held for this business" in one query rather than by
     * joining through `stores` on every candidate.
     */
    public function holdStore(Store $store, string $reason, ?User $actor = null, string $source = PayoutHold::SOURCE_MANUAL): PayoutHold
    {
        return $this->place(PayoutHold::SCOPE_STORE, (int) $store->business_id, (int) $store->id, $reason, $source, $actor);
    }

    public function releaseSystem(?User $actor = null): int
    {
        return $this->release(PayoutHold::SCOPE_SYSTEM, 0, 0, null, $actor);
    }

    /**
     * Lift a business hold.
     *
     * `$source` narrows it: passing `SOURCE_SUSPENSION` lifts only the hold a
     * suspension placed, which is what reactivation does. Passing null lifts
     * whatever is open, which is what an operator does when they have finished
     * looking.
     */
    public function releaseBusiness(Business $business, ?User $actor = null, ?string $source = null): int
    {
        return $this->release(PayoutHold::SCOPE_BUSINESS, (int) $business->id, 0, $source, $actor);
    }

    public function releaseStore(Store $store, ?User $actor = null, ?string $source = null): int
    {
        return $this->release(PayoutHold::SCOPE_STORE, (int) $store->business_id, (int) $store->id, $source, $actor);
    }

    /**
     * The hold stopping this business being paid, at whatever width.
     *
     * System before business, because a system hold is the wider statement and the
     * one an operator needs to hear about — "payouts are off everywhere" is a
     * different message from "this business is held". Within a width the oldest
     * wins, so a business held twice for two reasons reports the same one on every
     * run rather than a different one each time.
     */
    public function holdOn(Business $business): ?PayoutHold
    {
        return PayoutHold::query()->open()
            ->where('scope', PayoutHold::SCOPE_SYSTEM)
            ->orderBy('id')
            ->first()
            ?? PayoutHold::query()->open()
                ->where('scope', PayoutHold::SCOPE_BUSINESS)
                ->where('business_id', $business->id)
                ->orderBy('id')
                ->first();
    }

    /**
     * The store-scope holds on this business.
     *
     * Returned as rows rather than ids so the run log can say *why* a store is
     * being withheld — a count of withheld stores with no reason against it is the
     * message that sends somebody looking for a bug.
     *
     * @return Collection<int, PayoutHold>
     */
    public function storeHolds(Business $business): Collection
    {
        return PayoutHold::query()->open()
            ->where('scope', PayoutHold::SCOPE_STORE)
            ->where('business_id', $business->id)
            ->get();
    }

    /**
     * Just the store ids, for the walk that builds a payout.
     *
     * @return array<int, int>
     */
    public function heldStoreIds(Business $business): array
    {
        return $this->storeHolds($business)
            ->pluck('store_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Every hold currently in force, widest first.
     *
     * The console's answer to "what is held and by whom" — the list that makes the
     * difference between an investigation and an unexplained outage.
     *
     * @return Collection<int, PayoutHold>
     */
    public function openHolds(): Collection
    {
        return PayoutHold::query()
            ->open()
            ->orderByRaw("CASE scope WHEN 'system' THEN 0 WHEN 'business' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get();
    }

    /**
     * Write a hold, or return the one already in force.
     *
     * The uniqueness is enforced by the database rather than by the read above it,
     * because two admins looking at the same incident is not a rare event and the
     * read-then-write is exactly the shape that races. A duplicate insert is
     * therefore caught rather than prevented, and the winner's row is returned.
     */
    private function place(
        string $scope,
        int $businessId,
        int $storeId,
        string $reason,
        string $source,
        ?User $actor,
    ): PayoutHold {
        $reason = trim($reason);
        $reason = $reason !== '' ? Str::limit($reason, 255, '') : 'No reason recorded';

        $existing = $this->find($scope, $businessId, $storeId, $source);

        if ($existing !== null) {
            Log::info('custody.hold.already_open', [
                'hold_id' => $existing->id,
                'scope' => $scope,
                'business_id' => $businessId,
                'store_id' => $storeId,
            ]);

            return $existing;
        }

        $attributes = [
            'scope' => $scope,
            'business_id' => $businessId,
            'store_id' => $storeId,
            'reason' => $reason,
            'source' => $source,
            'placed_by' => $actor?->id,
            'placed_at' => now(),
            'open_marker' => PayoutHold::OPEN,
        ];

        try {
            $hold = PayoutHold::query()->create($attributes);
        } catch (QueryException $e) {
            // Lost the race. The other writer's row is the hold; this call's job is
            // to make sure one exists, which it now does.
            Log::info('custody.hold.raced', [
                'scope' => $scope,
                'business_id' => $businessId,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return $this->find($scope, $businessId, $storeId, $source)
                ?? throw $e;
        }

        // Inside no transaction of the caller's, so the audit row is written after
        // the hold exists rather than before. Every hold is a deliberate act by a
        // person, so this is rare enough to record synchronously.
        ActivityRecorder::record(
            action: 'payout_hold_placed',
            description: 'Payouts held on '.$hold->describe().': '.$reason,
            subject: $scope === PayoutHold::SCOPE_STORE ? $hold->store : $hold->business,
            new: ['scope' => $scope, 'reason' => $reason, 'source' => $source],
            actor: $actor,
            businessId: $businessId > 0 ? $businessId : null,
        );

        Log::warning('custody.hold.placed', [
            'hold_id' => $hold->id,
            'scope' => $scope,
            'business_id' => $businessId,
            'store_id' => $storeId,
            'source' => $source,
            'reason' => $reason,
            'placed_by' => $actor?->id,
        ]);

        return $hold;
    }

    /**
     * Release every matching open hold, and answer how many there were.
     *
     * By condition rather than by model, so two releases racing end with one of
     * them reporting a row and the other reporting none, rather than both
     * reporting one.
     */
    private function release(
        string $scope,
        int $businessId,
        int $storeId,
        ?string $source,
        ?User $actor,
    ): int {
        $released = PayoutHold::query()
            ->open()
            ->where('scope', $scope)
            ->where('business_id', $businessId)
            ->where('store_id', $storeId)
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->update([
                // NULL, not 0: this is the column the unique index tolerates any
                // number of, and it has to stay that way or a subject could only
                // ever be held once in its lifetime.
                'open_marker' => null,
                'released_at' => now(),
                'released_by' => $actor?->id,
            ]);

        if ($released === 0) {
            return 0;
        }

        ActivityRecorder::record(
            action: 'payout_hold_released',
            description: 'Payout hold lifted on '.match ($scope) {
                PayoutHold::SCOPE_SYSTEM => 'the whole platform',
                PayoutHold::SCOPE_BUSINESS => 'business '.$businessId,
                default => 'store '.$storeId,
            },
            // The subject is a model or nothing: a system hold is not about any
            // one business, and pointing the audit row at one would make the
            // platform-wide switch look like a decision about whoever happened to
            // be first.
            subject: match ($scope) {
                PayoutHold::SCOPE_BUSINESS => Business::query()->find($businessId),
                PayoutHold::SCOPE_STORE => Store::query()->find($storeId),
                default => null,
            },
            old: ['scope' => $scope, 'store_id' => $storeId, 'source' => $source],
            actor: $actor,
            businessId: $businessId > 0 ? $businessId : null,
        );

        Log::info('custody.hold.released', [
            'scope' => $scope,
            'business_id' => $businessId,
            'store_id' => $storeId,
            'source' => $source,
            'holds' => $released,
            'released_by' => $actor?->id,
        ]);

        $this->resume($scope, $businessId);

        return $released;
    }

    /**
     * Say that money which was withheld on purpose may now be sent.
     *
     * ## Why a lift has to say so
     *
     * Lifting a hold is the one thing in the wallet that makes money payable without
     * a customer paying. It used to be noticed by the five-minute run; the run is
     * gone, and paying is now driven by a sale being collected — so without this,
     * the balance that accumulated behind a hold would sit there until the business's
     * *next* sale, which on a quiet shop is days. The screen would meanwhile tell
     * them their money is on its way automatically, because it is: just not yet.
     *
     * All this does is enqueue the same {@see SettlePayouts} job a sale enqueues. It
     * sends nothing itself, decides no amount, and is safe to call when there is
     * nothing owed — the job reads the wallet and finds nothing to do.
     *
     * `SCOPE_STORE` wakes the whole business rather than the store, because there is
     * no per-store job: the send is one per destination, and a business whose other
     * money was already payable is unaffected by being woken with this one.
     *
     * A **system** hold is the platform-wide switch, so it wakes whoever is actually
     * holding money — {@see PayoutSweepService::candidates()}, the same list the
     * command used — rather than every business on the platform. It is resolved
     * through the container rather than injected, because `PayoutSweepService`
     * depends on this service and a constructor dependency in the other direction
     * would be a cycle.
     */
    private function resume(string $scope, int $businessId): void
    {
        if ($scope !== PayoutHold::SCOPE_SYSTEM) {
            if ($businessId > 0) {
                SettlePayouts::forBusiness($businessId);
            }

            return;
        }

        foreach (app(PayoutSweepService::class)->candidates() as $business) {
            SettlePayouts::forBusiness((int) $business->id);
        }
    }

    /**
     * The open hold this placement would duplicate, if there is one.
     *
     * Keyed on the source as well as the subject, because those are the columns the
     * unique index covers and the two have to agree: a suspension hold and an
     * operator's own hold on the same business are two holds, and looking for only
     * one of them here would hand back the other's row and lose the reason that was
     * just given.
     */
    private function find(string $scope, int $businessId, int $storeId, string $source): ?PayoutHold
    {
        return PayoutHold::query()
            ->open()
            ->where('scope', $scope)
            ->where('business_id', $businessId)
            ->where('store_id', $storeId)
            ->where('source', $source)
            ->first();
    }
}
