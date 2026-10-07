<?php

namespace App\Http\Controllers\Api\V1\Management\Pos;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Pos\OrderSourceIndexRequest;
use App\Http\Resources\Management\Pos\OrderSourceResource;
use App\Repositories\Management\Pos\OrderSourceRepository;
use Illuminate\Http\JsonResponse;

/**
 * WS-17 — the Orders list needs to be able to isolate POS takings, which means
 * the legacy `source` filter (Online Store = "checkout" | "pos") plus the POS
 * session a row was rung on.
 *
 * routes/api/v1/management/ws17-pos-oversight.php re-registers `GET orders`
 * against this controller: Laravel keys routes by method+URI, so the later
 * registration replaces the thin OrderController@index declared earlier in the
 * shared file without either file being edited. The index query and payload
 * (now OrderSourceRepository and OrderSourceResource) are a faithful superset
 * of that version — same filters, same columns, same ordering — so existing
 * consumers see an unchanged shape; only the `source` filter,
 * `pos_session_id` and `is_pos` are new.
 *
 * WS-13's richer board list (`GET orders/board/list`) remains the SPA orders
 * screen; this endpoint keeps working for every other consumer.
 *
 * Layering: only the HTTP shape — status codes, the envelope, pagination meta
 * — lives here. The `source` rule the endpoint validated inline is in
 * OrderSourceIndexRequest; the scoping, filters, eager loads and ordering are
 * in OrderSourceRepository; the row shape (a verbatim move of the old private
 * payload(), field names/types/order included) is in OrderSourceResource. The
 * list is a single-table read with no transaction or side effect, so no
 * service layer is introduced. The other list filters were never validated
 * and are read here with the same filled()/integer()/date() semantics the
 * inline query used, so inputs the endpoint used to answer do not become
 * 422s.
 */
class OrderSourceController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly OrderSourceRepository $orders,
    ) {}

    public function index(OrderSourceIndexRequest $request): JsonResponse
    {
        // Presence and coercion are decided here, with the same
        // filled()/integer()/date() reading the inline query used; the
        // repository only composes the query.
        $filters = [
            'source' => $request->validated('source'),
            'store_id' => $request->filled('store_id') ? $request->integer('store_id') : null,
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'from' => $request->filled('from') ? $request->date('from') : null,
            'to' => $request->filled('to') ? $request->date('to') : null,
            'q' => $request->filled('q') ? (string) $request->string('q') : null,
            'per_page' => $request->integer('per_page', 20),
        ];

        $orders = $this->orders->paginateForUser($this->user($request), $filters);

        return $this->ok(
            OrderSourceResource::collection($orders->getCollection())->resolve($request),
            null,
            200,
            $this->paginationMeta($orders)
        );
    }
}
