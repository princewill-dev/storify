<?php

namespace App\Http\Controllers\Api\V1\Management\Pos;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * WS-17 — owner-facing POS oversight.
 *
 * The POS-audience API (routes/api/v1/pos.php) is self-scoped to the signed-in
 * cashier, so it cannot back the business dashboard: an owner there would see
 * only their own sessions. This controller reads and writes the same
 * pos_sessions rows across every store the user can reach, and deliberately
 * delegates reconciliation to PosSession::close()/calculateCashSalesTotal()
 * rather than recomputing the cash-leg math a second time.
 *
 * Every money figure returned here is an integer in kobo.
 */
class SessionController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * Sidebar/dashboard summary: KPI counts plus one row per accessible store
     * so the POS nav group can show live session counts per store.
     */
    public function overview(Request $request): JsonResponse
    {
        return $this->ok([
            'stats' => $this->stats($request),
            'stores' => $this->storeRows($request),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([PosSession::STATUS_OPEN, PosSession::STATUS_CLOSED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (isset($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $sessions = $this->sessionQuery($request)
            ->with(['store:id,store_id,name,pos_enabled', 'staff:id,name'])
            ->withCount('orders')
            ->withSum('orders as sales_total_amount', 'total')
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('opened_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok([
            'sessions' => $sessions->getCollection()->map(fn (PosSession $session) => $this->summary($session))->all(),
            'stats' => $this->stats($request),
            'stores' => $this->storeRows($request),
        ], null, 200, $this->paginationMeta($sessions));
    }

    /**
     * Global session detail: financials, sales records, transactions and the
     * cashier's recent sessions across the business.
     */
    public function show(Request $request, PosSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        return $this->ok(['session' => $this->detail($request, $session)]);
    }

    /**
     * Per-store cash-register log with expected/actual/difference columns.
     */
    public function storeIndex(Request $request, Store $store): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([PosSession::STATUS_OPEN, PosSession::STATUS_CLOSED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sessions = PosSession::where('store_id', $store->id)
            ->with(['store:id,store_id,name,pos_enabled', 'staff:id,name'])
            ->withCount('orders')
            ->withSum('orders as sales_total_amount', 'total')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            // The legacy screen paginated 20/page but rendered no pager, so
            // only the first 20 sessions per store were ever reachable. The
            // meta block keeps the full log reachable here.
            ->latest('opened_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $todaySales = (int) round((float) Order::query()
            ->where('store_id', $store->id)
            ->whereNotNull('pos_session_id')
            ->whereDate('created_at', today())
            ->sum('total') * 100);

        return $this->ok([
            'store' => $this->storePayload($store),
            'sessions' => $sessions->getCollection()->map(fn (PosSession $session) => $this->summary($session))->all(),
            'stats' => [
                'open_sessions' => PosSession::where('store_id', $store->id)
                    ->where('status', PosSession::STATUS_OPEN)->count(),
                'total_sessions' => PosSession::where('store_id', $store->id)->count(),
                'today_pos_sales' => $todaySales,
            ],
        ], null, 200, $this->paginationMeta($sessions));
    }

    /**
     * Per-store session detail with the reconciliation facts and sales summary.
     */
    public function storeShow(Request $request, Store $store, PosSession $session): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        if ((int) $session->store_id !== (int) $store->id) {
            // The session exists, just not under this store — a 404 here keeps
            // the store-scoped URL honest instead of leaking the session.
            abort(404, 'Session not found for this store.');
        }

        $this->authorizeSession($request, $session);

        return $this->ok([
            'store' => $this->storePayload($store),
            'session' => $this->detail($request, $session),
        ]);
    }

    /**
     * Open a cash-register session with an opening float (kobo).
     */
    public function open(Request $request, Store $store): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        $user = $this->user($request);

        if (! $store->pos_enabled) {
            return $this->error('POS is not enabled for this store.', 422);
        }

        $data = $request->validate([
            'opening_balance' => ['required', 'integer', 'min:0'],
        ]);

        // The legacy guard (and the Electron POS API) is per cashier, not per
        // store, so two cashiers can legitimately hold sessions on one shop
        // floor. The verified audit calls that out and asks for those sessions
        // to be surfaced rather than collapsed — the list and the store card
        // show every open session, and this guard only stops the same user
        // opening twice. Do not tighten it to per-store without also teaching
        // the POS app to refuse.
        $existing = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->exists();

        if ($existing) {
            return $this->error('You already have an open session for this store.', 422);
        }

        $session = DB::transaction(fn () => PosSession::create([
            'store_id' => $store->id,
            'business_id' => $store->business_id,
            'staff_id' => $user->id,
            'opened_at' => now(),
            'opening_balance' => (int) $data['opening_balance'],
            'status' => PosSession::STATUS_OPEN,
        ]));

        Log::info('api.management.pos_session_opened', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'session_code' => $session->session_code,
        ]);

        $session->load(['store:id,store_id,name,pos_enabled', 'staff:id,name']);

        return $this->ok([
            'session' => $this->summary($session),
            'open_sessions_count' => $this->openCountForStore($store),
        ], 'POS session opened.', 201);
    }

    /**
     * Close a cash-register session and reconcile the drawer.
     */
    public function close(Request $request, Store $store): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        $data = $request->validate([
            'closing_balance_actual' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'session_code' => ['nullable', 'string', 'max:64'],
        ]);

        $query = PosSession::where('store_id', $store->id)
            ->where('status', PosSession::STATUS_OPEN);

        if (! empty($data['session_code'])) {
            // When several cashiers hold sessions the caller can name the one
            // being counted; the UI passes this as soon as it is ambiguous.
            $session = (clone $query)->where('session_code', $data['session_code'])->first();
        } else {
            // Deliberately the store's latest open session, not the caller's
            // own — legacy let an owner close a drawer a cashier walked away
            // from, and that is the daily-use path.
            $session = $query->latest('opened_at')->first();
        }

        if (! $session) {
            return $this->error('No open session found for this store.', 422);
        }

        $otherOpenSessions = PosSession::where('store_id', $store->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->whereKeyNot($session->getKey())
            ->count();

        DB::transaction(fn () => $session->close(
            (int) $data['closing_balance_actual'],
            $data['notes'] ?? null,
        ));

        Log::info('api.management.pos_session_closed', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'session_code' => $session->session_code,
            'difference' => $session->difference,
        ]);

        $session->load(['store:id,store_id,name,pos_enabled', 'staff:id,name']);

        return $this->ok([
            'session' => $this->summary($session),
            // The verify pass flagged that closing "the latest open session"
            // leaves an owner unable to tell which drawer was counted; say so.
            'other_open_sessions' => $otherOpenSessions,
        ], 'POS session closed.');
    }

    /**
     * Enable the POS terminal for a store. Idempotent — legacy re-enabling an
     * enabled store just flashed success again.
     */
    public function enable(Request $request, Store $store): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        if (! $store->pos_enabled) {
            DB::transaction(fn () => $store->update(['pos_enabled' => true]));

            Log::info('api.management.pos_enabled', [
                'user_id' => $this->user($request)->id,
                'store_id' => $store->id,
            ]);
        }

        return $this->ok(
            ['store' => $this->storePayload($store->fresh())],
            'POS terminal enabled for this store.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Request $request): array
    {
        return [
            'open_sessions' => $this->sessionQuery($request)
                ->where('status', PosSession::STATUS_OPEN)
                ->count(),
            // Legacy "Today's POS Sales" summed orders that belong to a POS
            // session, POS orders only — not every source=pos row ever made.
            'today_pos_sales' => (int) round((float) Order::query()
                ->where('business_id', $this->user($request)->business_id)
                ->whereIn('store_id', $this->accessibleStoreIds($request))
                ->whereNotNull('pos_session_id')
                ->whereDate('created_at', today())
                ->sum('total') * 100),
            'pos_stores' => $this->posStores($request)->where('pos_enabled', true)->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storeRows(Request $request): array
    {
        $stores = $this->posStores($request);

        $openCounts = PosSession::query()
            ->whereIn('store_id', $stores->pluck('id'))
            ->where('status', PosSession::STATUS_OPEN)
            ->selectRaw('store_id, COUNT(*) as aggregate')
            ->groupBy('store_id')
            ->pluck('aggregate', 'store_id');

        return $stores->map(fn (Store $store) => [
            ...$this->storePayload($store),
            'open_sessions_count' => (int) ($openCounts[$store->id] ?? 0),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PosSession $session): array
    {
        // withSum() pre-computes the sales column on list queries; the model
        // method is the fallback for single rows (open/close responses).
        $salesTotal = $session->sales_total_amount !== null
            ? (int) round((float) $session->sales_total_amount * 100)
            : $session->calculateSalesTotal();

        $cashSalesTotal = $session->calculateCashSalesTotal();

        return [
            'id' => $session->id,
            'session_code' => $session->session_code,
            'status' => $session->status,
            'is_open' => $session->isOpen(),
            'store' => $session->store ? $this->storePayload($session->store) : null,
            'staff' => $session->staff ? [
                'id' => $session->staff->id,
                'name' => $session->staff->name,
            ] : null,
            'opened_at' => $session->opened_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(),
            'opening_balance' => (int) $session->opening_balance,
            // Open sessions show the drawer as it stands now (float + confirmed
            // cash legs); closed sessions show the frozen reconciliation.
            'expected_close' => $session->isOpen()
                ? (int) $session->opening_balance + $cashSalesTotal
                : (int) $session->closing_balance_expected,
            'actual_close' => $session->closing_balance_actual !== null ? (int) $session->closing_balance_actual : null,
            'difference' => $session->difference !== null ? (int) $session->difference : null,
            'sales_total' => $salesTotal,
            'cash_sales_total' => $cashSalesTotal,
            'orders_count' => (int) ($session->orders_count ?? $session->orders()->count()),
            'notes' => $session->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, PosSession $session): array
    {
        $session->load([
            'store:id,store_id,name,pos_enabled',
            'staff:id,name,account_code',
            'orders' => fn ($query) => $query
                ->withCount('items')
                ->with(['transactions.paymentMethod:id,name'])
                ->latest('created_at'),
        ]);

        $orders = $session->orders;

        $transactions = $orders
            ->flatMap(fn (Order $order) => $order->transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'order_number' => $order->order_number,
                'amount' => (int) round((float) $transaction->amount * 100),
                'status' => $transaction->status instanceof TransactionStatus
                    ? $transaction->status->value
                    : $transaction->status,
                'payment_method' => $transaction->paymentMethod?->name,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
            ]))
            ->values()
            ->all();

        return [
            ...$this->summary($session),
            'duration_seconds' => $session->closed_at && $session->opened_at
                ? (int) $session->opened_at->diffInSeconds($session->closed_at)
                : null,
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'items_count' => (int) ($order->items_count ?? $order->items->count()),
                'total' => (int) round((float) $order->total * 100),
                'payment_status' => $order->transactions->first()?->status instanceof TransactionStatus
                    ? $order->transactions->first()->status->value
                    : $order->transactions->first()?->status,
                'payment_method' => data_get($order->meta, 'payment_method')
                    ?? $order->transactions->first()?->paymentMethod?->name,
                'reference' => $order->transactions->first()?->reference,
                'created_at' => $order->created_at?->toISOString(),
            ])->values()->all(),
            'transactions' => $transactions,
            'staff_recent_sessions' => PosSession::query()
                ->where('staff_id', $session->staff_id)
                ->whereIn('store_id', $this->accessibleStoreIds($request))
                ->whereHas('store', fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED))
                ->with('store:id,store_id,name')
                ->latest('opened_at')
                ->limit(20)
                ->get()
                ->map(fn (PosSession $recent) => [
                    'id' => $recent->id,
                    'session_code' => $recent->session_code,
                    'store' => $recent->store ? [
                        'id' => $recent->store->id,
                        'store_id' => $recent->store->store_id,
                        'name' => $recent->store->name,
                    ] : null,
                    'status' => $recent->status,
                    'is_open' => $recent->isOpen(),
                    'opened_at' => $recent->opened_at?->toISOString(),
                    'closed_at' => $recent->closed_at?->toISOString(),
                ])->values()->all(),
        ];
    }

    private function sessionQuery(Request $request): Builder
    {
        return PosSession::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereIn('store_id', $this->accessibleStoreIds($request))
            // Deleted stores must not leak back into oversight screens.
            ->whereHas('store', fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED));
    }

    /**
     * Accessible, non-deleted stores, for the filter dropdown and nav rows.
     *
     * @return Collection<int, Store>
     */
    private function posStores(Request $request)
    {
        return $this->user($request)->accessibleStores()
            ->where('stores.status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    private function authorizeStoreId(Request $request, int $storeId): void
    {
        if (! $this->user($request)->accessibleStores()
            ->where('stores.status', '!=', Store::STATUS_DELETED)
            ->whereKey($storeId)
            ->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }

    private function authorizeSession(Request $request, PosSession $session): void
    {
        $user = $this->user($request);

        if ((int) $session->business_id !== (int) $user->business_id) {
            abort(403, 'You do not have access to this POS session.');
        }

        $this->authorizeStoreId($request, (int) $session->store_id);
    }

    /**
     * Store access plus a deleted-store guard: the shared authorizeStore()
     * trait still counts a soft-deleted store as accessible to its owner, and
     * a deleted store must not be operable or show up in oversight.
     */
    private function authorizePosStore(Request $request, Store $store): void
    {
        $this->authorizeStore($request, $store);

        if ($store->status === Store::STATUS_DELETED) {
            abort(404, 'This store no longer exists.');
        }
    }

    private function openCountForStore(Store $store): int
    {
        return PosSession::where('store_id', $store->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'pos_enabled' => (bool) $store->pos_enabled,
        ];
    }
}
