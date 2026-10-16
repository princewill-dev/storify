<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\PlacePayoutHoldRequest;
use App\Models\Business;
use App\Models\PayoutHold;
use App\Models\Store;
use App\Services\Payments\Custody\PayoutHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AD-19 — stopping payouts on purpose.
 *
 * The operator's side of `businesses.auto_payout`, which until now was read by the
 * sweep and writable by nothing: a payment rail that can be paused only by editing
 * a column in the database is not a safeguard. Three widths, because the questions
 * are different — a rail incident, a dispute about a business, a dispute about one
 * of its stores — and the store width withholds that store's share while the
 * business is paid everything else.
 *
 * ## Holds are placeable here and liftable here. Nothing here moves money.
 *
 * There is no "run the sweep now" route, deliberately, and the reasoning is
 * recorded at `ad12-money-config.php` where the three that used to exist are
 * described: every payout now moves Storify's own balance, and an admin lever that
 * pushed one would be a second way to move money. A hold is a stop. The stop and
 * the *undo* of the stop are the whole surface; when a hold lifts, the lift itself
 * queues the same `SettlePayouts` job a collected sale dispatches, and the business
 * is paid with nothing to reconcile.
 *
 * The retry route that now exists next door does not change that, and the
 * distinction is worth having straight because lifting a hold and pressing retry
 * are the two obvious ways to unstick a business. A retry re-attempts money the
 * wallet already says is owed, to the account already named, for an amount the
 * wallet decides — it conjures nothing. It also cannot cross a hold: the hold is
 * read by `PayoutSweepService::payoutBlocker()` before any send, so a retry on a
 * held business returns "on hold" and sends nothing. Lifting the hold is still the
 * only way past a hold, and pressing retry is not a way around one.
 *
 * Every hold and release writes an `activity_logs` row from inside
 * {@see PayoutHoldService} — subject, reason and actor — so the audit trail is a
 * property of the write rather than of this controller being called.
 */
class PayoutHoldController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly PayoutHoldService $holds,
    ) {}

    /**
     * Every hold currently in force, widest scope first.
     *
     * Open holds and not a history: the released rows are on the activity log,
     * where a record of who lifted what belongs. A list that showed both would
     * make "what is stopping money right now" a question about reading dates.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok([
            'holds' => $this->holds->openHolds()
                ->map(fn (PayoutHold $hold) => $this->payload($hold))
                ->values()
                ->all(),
        ]);
    }

    public function store(PlacePayoutHoldRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();
        $actor = $request->user();

        $hold = match ($data['scope']) {
            PayoutHold::SCOPE_SYSTEM => $this->holds->holdSystem($data['reason'], $actor),
            PayoutHold::SCOPE_BUSINESS => $this->holds->holdBusiness(
                Business::query()->findOrFail($data['business_id']),
                $data['reason'],
                $actor,
            ),
            PayoutHold::SCOPE_STORE => $this->holds->holdStore(
                Store::query()->findOrFail($data['store_id']),
                $data['reason'],
                $actor,
            ),
        };

        Log::warning('api.admin.payout_hold_placed', [
            'actor_user_id' => $actor?->id,
            'hold_id' => $hold->id,
            'scope' => $hold->scope,
            'business_id' => (int) $hold->business_id,
            'store_id' => (int) $hold->store_id,
            'reason' => $hold->reason,
        ]);

        return $this->ok(
            ['hold' => $this->payload($hold->fresh(['business', 'store', 'placedBy']))],
            // Pre-existing hold or a new one, the caller's intent is satisfied
            // either way; the message says which so a double-click is legible.
            $hold->wasRecentlyCreated ? 'Payouts held.' : 'Payouts were already held.',
            $hold->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Lift the hold named by the route.
     *
     * Released through the service's condition-based entry points rather than by a
     * second release-by-id path, because they are the same row: the unique index
     * permits exactly one open hold per (scope, subject, source), so naming those
     * four things names this hold and nothing else. `id` still comes back on the
     * response, so a client that lost the row it clicked still learns what changed.
     *
     * A hold already lifted by somebody else answers 200 with `released: false`:
     * the caller's intent — "payouts are no longer held for this subject" — holds,
     * and an error would send them looking for a failure that did not happen.
     */
    public function destroy(Request $request, PayoutHold $payoutHold): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $actor = $request->user();

        $released = match ($payoutHold->scope) {
            PayoutHold::SCOPE_SYSTEM => $this->holds->releaseSystem($actor),
            PayoutHold::SCOPE_BUSINESS => $this->holds->releaseBusiness(
                Business::query()->findOrFail($payoutHold->business_id),
                $actor,
                $payoutHold->source,
            ),
            // A store hold whose store has since been deleted has nothing left to
            // pay out, so there is nothing to release; the row is reported as
            // already lifted rather than 404'd, because it is on a screen the
            // operator is looking at and the honest answer is "that is over".
            PayoutHold::SCOPE_STORE => ($store = Store::query()->find($payoutHold->store_id)) !== null
                ? $this->holds->releaseStore($store, $actor, $payoutHold->source)
                : 0,
        };

        Log::warning('api.admin.payout_hold_released', [
            'actor_user_id' => $actor?->id,
            'hold_id' => $payoutHold->id,
            'scope' => $payoutHold->scope,
            'business_id' => (int) $payoutHold->business_id,
            'store_id' => (int) $payoutHold->store_id,
            'released' => $released,
        ]);

        return $this->ok([
            'id' => (int) $payoutHold->id,
            'released' => $released > 0,
        ], $released > 0 ? 'Payout hold released.' : 'That hold had already been released.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PayoutHold $hold): array
    {
        return [
            'id' => (int) $hold->id,
            'scope' => $hold->scope,
            // Manual or suspension. Two holds can be open on one subject, and this
            // is what tells an operator which of them reactivating a business will
            // lift — the suspension's, never theirs.
            'source' => $hold->source,
            'business_id' => (int) $hold->business_id > 0 ? (int) $hold->business_id : null,
            'business_name' => $hold->business?->name,
            'store_id' => (int) $hold->store_id > 0 ? (int) $hold->store_id : null,
            'store_name' => $hold->store?->name,
            // "on this business", "on this store", "on the platform" — one
            // definition, shared with the run's report and the business's own
            // wallet payload.
            'over' => $hold->describe(),
            'reason' => $hold->reason,
            'placed_at' => $hold->placed_at?->toISOString(),
            'placed_by' => $hold->placed_by !== null ? (int) $hold->placed_by : null,
            'placed_by_name' => $hold->placedBy?->name,
        ];
    }
}
