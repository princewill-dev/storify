<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\DispatchIndexRequest;
use App\Http\Resources\Management\DispatchBoardResource;
use App\Repositories\Management\DispatchRepository;
use App\Services\Access\TenantGuard;
use Illuminate\Http\JsonResponse;

/**
 * WS-26 — dispatches board.
 *
 * The legacy screen (`Management\DispatchesController@index`) was a read-only
 * board over `OrderDelivery`: four metric cards, a free-text search across
 * driver / tracking / order number, a filter modal (status, store, date range)
 * with an active-filter count, and a table whose rows link back to the order.
 * This controller is the same read model — legacy never advanced delivery
 * statuses from the screen (only order fulfilment actions produced them), so
 * nothing here mutates. Rows are created by WS-12's dispatch action.
 *
 * Scoping note: legacy computed its metric cards from a raw business-wide
 * `OrderDelivery` query, so restricted staff saw counts for stores they cannot
 * open. Every read runs through `DispatchRepository::accessibleQuery()`, which
 * applies both the business and the accessible-store scope.
 *
 * The controller keeps the HTTP shape only — status codes, the envelope and
 * pagination meta. Validation lives in DispatchIndexRequest, the board's
 * scoping/filters/aggregates in DispatchRepository, and response shaping in
 * DispatchBoardResource / DispatchResource. The board is a read-only,
 * single-table read model, so no service layer is introduced. The foreign
 * store-id guard deliberately stays here — after validation, before the query
 * — so its order is unchanged (it must not move into FormRequest::authorize()).
 */
class DispatchController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly DispatchRepository $dispatches,
        private readonly TenantGuard $tenantGuard,
    ) {}

    /**
     * The board: filterable delivery rows plus the metric cards and the
     * filter-modal option lists the legacy view received from its controller.
     */
    public function index(DispatchIndexRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $filters = $request->validated();

        // A store id is only ever trusted after checking it against the stores
        // this user can reach — a foreign id is refused, not silently emptied.
        if (! empty($filters['store_id'])) {
            $this->tenantGuard->authorizeStoreId($user, (int) $filters['store_id'], 'You do not have access to this store.');
        }

        $page = $this->dispatches->paginateForUser($user, $filters);

        $payload = new DispatchBoardResource(
            $page->getCollection(),
            $this->dispatches->stats($user),
            $this->dispatches->storeOptions($user),
        );

        return $this->ok(
            $payload->resolve($request),
            null,
            200,
            $this->paginationMeta($page),
        );
    }
}
