<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosCloseSessionRequest;
use App\Http\Requests\Pos\PosOpenSessionRequest;
use App\Http\Resources\Pos\PosClosedSessionResource;
use App\Http\Resources\Pos\PosSessionResource;
use App\Models\PosSession;
use App\Models\Store;
use App\Repositories\Pos\PosSessionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The POS terminal's cash-register session (status / open / close).
 *
 * Layering: the HTTP shape stays here — status codes, message strings and the
 * terminal's own `success` + `data` envelope, which is deliberately NOT
 * ApiController::ok() (this is the terminal API, and callers parse the
 * `success` key). Validation lives in the `App\Http\Requests\Pos\Pos*Session`
 * classes, the store + cashier scoping of the open session in
 * App\Repositories\Pos\PosSessionRepository, and the payload blocks in
 * PosSessionResource / PosClosedSessionResource.
 *
 * No service: the only writes are a single-row create and the model's own
 * `close()` reconciliation, with no transaction, ledger or notification to
 * coordinate — a service here would be indirection without benefit.
 *
 * Deliberate non-changes:
 * - `close()` remains the model method: `closing_balance_expected`,
 *   `difference` and `closed_at` are the reconciliation the model has always
 *   written, and `closing_balance_expected` still includes the confirmed cash
 *   legs that calculateCashSalesTotal() sums.
 * - The open guard stays per cashier (store + staff), not per store: two
 *   cashiers may legitimately hold sessions on one shop floor.
 * - Extracting validation to FormRequests resolves it before the controller
 *   body, so a request that is both malformed and refused by a 400 guard here
 *   (`pos_enabled`, duplicate open, no open session) now answers 422 instead
 *   of 400. That is the extraction-wide, already-accepted consequence; a
 *   valid payload gets the same 400 and message as before, and auth,
 *   EnsurePosStoreAccess and route-binding 403/404 still precede everything.
 */
class SessionController extends Controller
{
    public function __construct(private readonly PosSessionRepository $repository) {}

    public function status(Request $request, Store $store): JsonResponse
    {
        $session = $this->repository->findOpenFor($store, $request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session ? (new PosSessionResource($session))->resolve($request) : null,
            ],
        ]);
    }

    public function open(PosOpenSessionRequest $request, Store $store): JsonResponse
    {
        $user = $request->user();

        if (! $store->pos_enabled) {
            return response()->json(['success' => false, 'message' => 'POS is not enabled for this store.'], 400);
        }

        if ($this->repository->hasOpenFor($store, $user)) {
            return response()->json(['success' => false, 'message' => 'You already have an open session for this store.'], 400);
        }

        $validated = $request->validated();

        $session = PosSession::create([
            'store_id' => $store->id,
            'business_id' => $store->business_id,
            'staff_id' => $user->id,
            'opened_at' => now(),
            'opening_balance' => $validated['opening_balance'],
            'status' => PosSession::STATUS_OPEN,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'session' => (new PosSessionResource($session))->withZeroTotals()->resolve($request),
            ],
        ], 201);
    }

    public function close(PosCloseSessionRequest $request, Store $store): JsonResponse
    {
        $session = $this->repository->findOpenFor($store, $request->user());

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'No open session found.'], 400);
        }

        $validated = $request->validated();

        $session->close(
            $validated['closing_balance_actual'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => [
                'session' => (new PosClosedSessionResource($session))->resolve($request),
            ],
        ]);
    }
}
