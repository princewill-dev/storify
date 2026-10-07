<?php

namespace App\Services\Management;

use App\Models\StockLocation;
use App\Repositories\Management\StockVisibilityRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WS-29 — the min-level write workflows.
 *
 * Legacy hard-coded `min_quantity => 0` on every write, which is why its
 * low-stock card was always zero; these are the missing writes (inventory
 * audit §3.2 / verify #5) — the first things ever to set the column.
 *
 * Each workflow keeps its pre-refactor transaction boundary and its
 * lockForUpdate() at the same point in the sequence: the row is re-fetched
 * under a lock inside the transaction, never written from the route-bound
 * copy, and the bulk save stays all-or-nothing so a restock plan can never
 * half-apply. The locking fetches themselves live in the repository; the
 * transaction belongs to this layer, never to the repository.
 *
 * Deliberately not here: the 403 for a location outside the caller's circle
 * (the controller verifies every id before calling in, re-derived from the
 * authenticated user — never from the request payload), the Log::info audit
 * lines, the post-write refresh/reload and the response shaping, all of which
 * keep their pre-refactor place in the controller around these calls.
 */
final class StockMinLevelService
{
    public function __construct(private readonly StockVisibilityRepository $locations) {}

    /**
     * One location's min level, written under a row lock exactly as the
     * pre-refactor transaction did: re-fetch the id locked, set, save.
     */
    public function updateMinLevel(StockLocation $stockLocation, int $minQuantity): void
    {
        DB::transaction(function () use ($stockLocation, $minQuantity) {
            $locked = $this->locations->findForUpdate($stockLocation->id);
            $locked->min_quantity = $minQuantity;
            $locked->save();
        });
    }

    /**
     * Bulk counterpart for the editor's grid — all-or-nothing, so a save can
     * never half-apply a restock plan.
     *
     * @param  Collection<int|string, array{id: int|string, min_quantity: int|string}>  $levels  the validated items keyed by id
     */
    public function updateMinLevels(Collection $levels): void
    {
        DB::transaction(function () use ($levels) {
            $locations = $this->locations->lockByIds($levels->keys()->all());

            foreach ($locations as $location) {
                $location->min_quantity = (int) $levels[$location->id]['min_quantity'];
                $location->save();
            }
        });
    }
}
