<?php

namespace App\Http\Controllers\Api\V1\Management\Pos;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Pos\ClosePosSessionRequest;
use App\Http\Requests\Management\Pos\OpenPosSessionRequest;
use App\Http\Requests\Management\Pos\PosSessionIndexRequest;
use App\Http\Requests\Management\Pos\StorePosSessionIndexRequest;
use App\Http\Resources\Management\Pos\PosSessionDetailResource;
use App\Http\Resources\Management\Pos\PosSessionSummaryResource;
use App\Http\Resources\Management\Pos\PosStoreResource;
use App\Http\Resources\Management\Pos\PosStoreRowResource;
use App\Models\PosSession;
use App\Models\Store;
use App\Repositories\Management\Pos\PosSessionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta) and the access guards stay here; the list filters live in
 * the Management\Pos FormRequests, the tenant scoping, drawer lookups and KPI
 * aggregates in App\Repositories\Management\Pos\PosSessionRepository, and the
 * row/detail shapes in App\Http\Resources\Management\Pos. No service: every
 * write is a single-row create/update inside its own transaction with an
 * audit log line — no multi-table workflow, ledger or notification — so one
 * would be indirection without benefit.
 *
 * Every money figure returned here is an integer in kobo.
 */
class SessionController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly PosSessionRepository $repository) {}

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

    public function index(PosSessionIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        if (isset($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $sessions = $this->repository->paginateForUser($this->user($request), $filters);

        return $this->ok([
            'sessions' => PosSessionSummaryResource::collection($sessions->getCollection())->resolve($request),
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
    public function storeIndex(StorePosSessionIndexRequest $request, Store $store): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        $filters = $request->validated();

        $sessions = $this->repository->paginateForStore($store->id, $filters);

        return $this->ok([
            'store' => (new PosStoreResource($store))->resolve($request),
            'sessions' => PosSessionSummaryResource::collection($sessions->getCollection())->resolve($request),
            'stats' => [
                'open_sessions' => PosSession::where('store_id', $store->id)
                    ->where('status', PosSession::STATUS_OPEN)->count(),
                'total_sessions' => PosSession::where('store_id', $store->id)->count(),
                'today_pos_sales' => $this->repository->todaySalesForStore($store->id),
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
            'store' => (new PosStoreResource($store))->resolve($request),
            'session' => $this->detail($request, $session),
        ]);
    }

    /**
     * Open a cash-register session with an opening float (kobo).
     */
    public function open(OpenPosSessionRequest $request, Store $store): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        $user = $this->user($request);

        if (! $store->pos_enabled) {
            return $this->error('POS is not enabled for this store.', 422);
        }

        $data = $request->validated();

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

        $this->repository->loadSummary($session);

        return $this->ok([
            'session' => (new PosSessionSummaryResource($session))->resolve($request),
            'open_sessions_count' => $this->openCountForStore($store),
        ], 'POS session opened.', 201);
    }

    /**
     * Close a cash-register session and reconcile the drawer.
     */
    public function close(ClosePosSessionRequest $request, Store $store): JsonResponse
    {
        $this->authorizePosStore($request, $store);

        $data = $request->validated();

        $session = $this->repository->findOpenSession($store->id, $data['session_code'] ?? null);

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

        $this->repository->loadSummary($session);

        return $this->ok([
            'session' => (new PosSessionSummaryResource($session))->resolve($request),
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
            ['store' => (new PosStoreResource($store->fresh()))->resolve($request)],
            'POS terminal enabled for this store.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Request $request): array
    {
        $user = $this->user($request);

        return [
            'open_sessions' => $this->repository->openCountForUser($user),
            'today_pos_sales' => $this->repository->todaySalesForUser($user),
            'pos_stores' => $this->repository->posStores($user)->where('pos_enabled', true)->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storeRows(Request $request): array
    {
        $stores = $this->repository->posStores($this->user($request));

        $openCounts = $this->repository->openCountsForStores($stores->pluck('id'));

        return $stores->map(fn (Store $store) => (new PosStoreRowResource(
            $store,
            (int) ($openCounts[$store->id] ?? 0),
        ))->resolve($request))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, PosSession $session): array
    {
        $this->repository->loadDetail($session);

        return (new PosSessionDetailResource(
            $session,
            $this->repository->staffRecentSessions($this->user($request), (int) $session->staff_id),
        ))->resolve($request);
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
}
