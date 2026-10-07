<?php

namespace App\Repositories\Management\Pos;

use App\Models\Order;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use App\Support\Money\Naira;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-17 — query building for the management-side POS oversight API.
 *
 * Tenant scoping (business, plus the stores the user can reach, with deleted
 * stores excluded), the list filters/eager loads/aggregates, the drawer
 * lookups the close workflow needs and the per-store KPIs live here. Reads and
 * query building only: transaction boundaries and abort() calls stay with the
 * controller, and the drawer reconciliation stays on
 * PosSession::close()/calculateCashSalesTotal(), where the controller always
 * delegated it.
 */
final class PosSessionRepository
{
    /**
     * @var array<int, string>
     */
    public const LIST_RELATIONS = [
        'store:id,store_id,name,pos_enabled',
        'staff:id,name',
    ];

    /**
     * The business-wide session list: tenant scope, store/status filters, the
     * eager loads and the sales aggregate the summary rows read.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<PosSession>
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return $this->scopedQuery($user)
            ->with(self::LIST_RELATIONS)
            ->withCount('orders')
            ->withSum('orders as sales_total_amount', 'total')
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('opened_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * The per-store cash-register log, scoped by the caller's route-bound store
     * rather than the tenancy scope.
     *
     * The legacy screen paginated 20/page but rendered no pager, so only the
     * first 20 sessions per store were ever reachable. The meta block keeps
     * the full log reachable here.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<PosSession>
     */
    public function paginateForStore(int $storeId, array $filters): LengthAwarePaginator
    {
        return PosSession::query()
            ->where('store_id', $storeId)
            ->with(self::LIST_RELATIONS)
            ->withCount('orders')
            ->withSum('orders as sales_total_amount', 'total')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('opened_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * Open sessions visible to the user — the sidebar KPI. Scoped exactly like
     * the list so the number can never count a store the list cannot show.
     */
    public function openCountForUser(User $user): int
    {
        return $this->scopedQuery($user)
            ->where('status', PosSession::STATUS_OPEN)
            ->count();
    }

    /**
     * Today's POS takings across the user's stores.
     *
     * Legacy "Today's POS Sales" summed orders that belong to a POS session,
     * POS orders only — not every source=pos row ever made.
     */
    public function todaySalesForUser(User $user): int
    {
        return Naira::koboFromRounded(Order::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->whereNotNull('pos_session_id')
            ->whereDate('created_at', today())
            ->sum('total'));
    }

    /**
     * Today's POS takings for one store — the same POS-session predicate as
     * the business-wide KPI.
     */
    public function todaySalesForStore(int $storeId): int
    {
        return Naira::koboFromRounded(Order::query()
            ->where('store_id', $storeId)
            ->whereNotNull('pos_session_id')
            ->whereDate('created_at', today())
            ->sum('total'));
    }

    /**
     * Accessible, non-deleted stores, for the filter dropdown and nav rows.
     *
     * @return Collection<int, Store>
     */
    public function posStores(User $user): Collection
    {
        return $user->accessibleStores()
            ->where('stores.status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    /**
     * Open-session counts for the nav rows, keyed by store id.
     *
     * @param  Collection<int, mixed>  $storeIds
     * @return Collection<mixed, mixed>
     */
    public function openCountsForStores(Collection $storeIds): Collection
    {
        return PosSession::query()
            ->whereIn('store_id', $storeIds)
            ->where('status', PosSession::STATUS_OPEN)
            ->selectRaw('store_id, COUNT(*) as aggregate')
            ->groupBy('store_id')
            ->pluck('aggregate', 'store_id');
    }

    /**
     * The cashier's recent sessions across every store the reader can reach —
     * the activity block on the session detail. Scoped like the list; deleted
     * stores are excluded here too.
     *
     * @return Collection<int, PosSession>
     */
    public function staffRecentSessions(User $user, int $staffId): Collection
    {
        return PosSession::query()
            ->where('staff_id', $staffId)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->whereHas('store', fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED))
            ->with('store:id,store_id,name')
            ->latest('opened_at')
            ->limit(20)
            ->get();
    }

    /**
     * The open session a close call should count.
     *
     * When several cashiers hold sessions the caller can name the one being
     * counted; the UI passes session_code as soon as it is ambiguous.
     * Otherwise this is deliberately the store's latest open session, not the
     * caller's own — legacy let an owner close a drawer a cashier walked away
     * from, and that is the daily-use path.
     */
    public function findOpenSession(int $storeId, ?string $sessionCode): ?PosSession
    {
        $query = PosSession::where('store_id', $storeId)
            ->where('status', PosSession::STATUS_OPEN);

        if (! empty($sessionCode)) {
            return (clone $query)->where('session_code', $sessionCode)->first();
        }

        return $query->latest('opened_at')->first();
    }

    /**
     * Eager-load the list relations onto a freshly written session for its
     * open/close response.
     */
    public function loadSummary(PosSession $session): PosSession
    {
        return $session->load(self::LIST_RELATIONS);
    }

    /**
     * Eager-load the detail read model: financials, sales records with their
     * payments, and the staff and store identities.
     */
    public function loadDetail(PosSession $session): PosSession
    {
        return $session->load([
            'store:id,store_id,name,pos_enabled',
            'staff:id,name,account_code',
            'orders' => fn ($query) => $query
                ->withCount('items')
                ->with(['transactions.paymentMethod:id,name'])
                ->latest('created_at'),
        ]);
    }

    /**
     * Sessions of every accessible store, business-scoped.
     *
     * Deleted stores must not leak back into oversight screens.
     *
     * @return Builder<PosSession>
     */
    private function scopedQuery(User $user): Builder
    {
        return PosSession::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->whereHas('store', fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED));
    }
}
