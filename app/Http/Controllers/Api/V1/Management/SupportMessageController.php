<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\SupportMessageIndexRequest;
use App\Http\Requests\Management\SupportMessageReplyRequest;
use App\Http\Resources\Management\SupportMessageResource;
use App\Http\Resources\Management\SupportMessageStoreOptionResource;
use App\Models\Store;
use App\Models\SupportMessage;
use App\Repositories\Management\SupportMessageRepository;
use App\Services\Management\SupportMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WS-33 — support messaging.
 *
 * The legacy screen (`Management\SupportMessageController@index`) listed every
 * message belonging to the business's stores and, behind `support reply`,
 * could write a reply, flip the status and email the customer — but its Blade
 * view never rendered a reply form, so businesses could not actually use it.
 * This controller is that capability plus the reply UI it never had.
 *
 * Layering: this class keeps the HTTP shape — status codes, message strings,
 * the envelope, the pagination meta — and the store guards, whose order is
 * asserted. The list filters validate in SupportMessageIndexRequest and the
 * reply body in SupportMessageReplyRequest, the inbox query / counts /
 * one-query replier-name lookup live in
 * App\Repositories\Management\SupportMessageRepository, the reply's
 * transaction, audit row and customer email in
 * App\Services\Management\SupportMessageService, and the row shapes in
 * Management\SupportMessageResource and SupportMessageStoreOptionResource.
 *
 * Deliberate departures from the legacy:
 * - the legacy list was deliberately thin (no store/phone/status/reply); the
 *   resource carries them so the SPA can be worked in, and adds q / status /
 *   store_id filters the legacy never had;
 * - ordering surfaces `pending` first (in the repository); legacy ordered
 *   `status asc` alphabetically, which put `closed` above `pending`;
 * - replies are refused on a closed conversation (admin-side) instead of
 *   silently overwriting it;
 * - soft-deleted stores are excluded, matching the WS-06 policy that deleted
 *   records must not leak back into read endpoints;
 * - the audit entry goes to `activity_logs` (ActivityLogger) so it is
 *   queryable, mirroring the legacy's Log::info intent without cloning the
 *   legacy log key that AGENTS.md forbids — the call now sits in the service,
 *   after its transaction, exactly where this controller ran it.
 *
 * The FormRequest extraction moves validation ahead of the controller body, so
 * a caller who is both unauthorised and malformed now answers 422 where it
 * answered 403 on `reply` — the known, accepted consequence of the extraction
 * across this codebase. A valid payload from an unauthorised caller still gets
 * 403, and route-binding 404 still precedes both, so nothing is escalated;
 * this is deliberately not worked around.
 */
class SupportMessageController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly SupportMessageRepository $messages,
        private readonly SupportMessageService $support,
    ) {}

    public function index(SupportMessageIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $storeIds = $this->accessibleStoreIds($request);

        // Never trust an id from the request; an inaccessible store filter is
        // rejected rather than silently returning everything. 422 by design,
        // not 403 — deliberate anti-id-probing.
        if (($filters['store_id'] ?? null) !== null && ! $storeIds->contains((int) $filters['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        $messages = $this->messages->paginateForStores($storeIds, $filters);

        $replierNames = $this->messages->replierNames($messages->getCollection());

        return $this->ok(
            [
                'messages' => $messages->getCollection()
                    ->map(fn (SupportMessage $message) => $this->payload($message, $replierNames))
                    ->values()
                    ->all(),
                // The store filter source. Legacy's view had no store column at
                // all; the SPA uses this for the filter and the no-stores state.
                'stores' => SupportMessageStoreOptionResource::collection($this->accessibleStores($request))->resolve(),
                'counts' => $this->messages->counts($storeIds),
            ],
            null,
            200,
            $this->paginationMeta($messages),
        );
    }

    /**
     * Pending/total counts for the sidebar indicator and page header. WS-34's
     * shell-counts endpoint will eventually own shell badges; until then the
     * badge component reads this.
     */
    public function stats(Request $request): JsonResponse
    {
        return $this->ok(['counts' => $this->messages->counts($this->accessibleStoreIds($request))]);
    }

    public function show(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizeMessage($request, $supportMessage);

        $supportMessage->loadMissing('store');

        return $this->ok([
            'message' => $this->payload($supportMessage, $this->messages->replierNames(collect([$supportMessage]))),
        ]);
    }

    public function reply(SupportMessageReplyRequest $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizeMessage($request, $supportMessage);

        // A closed conversation is admin-final; legacy would overwrite it.
        if ($supportMessage->status === 'closed') {
            return $this->error('This conversation has been closed and can no longer be replied to.', 422);
        }

        $fresh = $this->support->reply($supportMessage, $request->validated()['reply'], $this->user($request));

        return $this->ok(
            ['message' => $this->payload($fresh, $this->messages->replierNames(collect([$fresh])))],
            'Reply sent successfully to the customer.',
        );
    }

    /**
     * Stores the caller may read messages for. Legacy scoped by
     * `Store::where('user_id', $user->id)`, which silently gave staff nothing;
     * accessibleStores() covers owners and staff alike, minus deleted stores.
     *
     * Deliberately shadows ResolvesManagementContext::accessibleStoreIds(),
     * whose plain User::accessibleStoreIds() includes deleted stores: both the
     * list scope and the per-message 403 below depend on the exclusion.
     *
     * @return Collection<int, Store>
     */
    private function accessibleStores(Request $request): Collection
    {
        $query = $this->user($request)->accessibleStores();

        return $query
            ->where($query->getModel()->qualifyColumn('status'), '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, int>
     */
    private function accessibleStoreIds(Request $request): Collection
    {
        // accessibleStores() runs ->get(), so this is a Collection of Store
        // models — the key is plain `id`. Qualifying it as `stores.id`
        // (correct against a query builder) matches nothing here.
        return $this->accessibleStores($request)->pluck('id');
    }

    private function authorizeMessage(Request $request, SupportMessage $supportMessage): void
    {
        // The check stays inline rather than using TenantGuard::authorizeStoreId():
        // that reads accessibleStores() without the deleted-store exclusion,
        // which is load-bearing here (a message in a deleted store is not
        // reachable). It also stays in the controller body, never
        // FormRequest::authorize(), so its 403 keeps its place in the refusal
        // order.
        if (! $this->accessibleStoreIds($request)->contains((int) $supportMessage->store_id)) {
            abort(403, 'You do not have access to this support message.');
        }
    }

    /**
     * The message-row shape, kept as a thin private seam so the response sites
     * read as they did before the extraction; the fields live in
     * SupportMessageResource.
     *
     * @param  array<int, string>  $replierNames
     * @return array<string, mixed>
     */
    private function payload(SupportMessage $message, array $replierNames = []): array
    {
        return SupportMessageResource::make($message, $replierNames)->resolve();
    }
}
