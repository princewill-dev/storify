<?php

namespace App\Repositories\Management;

use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * WS-33 — reads behind the management support inbox.
 *
 * The inbox query (store scope, eager store load, q/status/store filters and
 * the pending-first ordering), the sidebar counts and the one-query
 * replier-name lookup live here.
 *
 * The store scope arrives already resolved to the caller's accessible,
 * non-deleted store ids (SupportMessageController::accessibleStoreIds())
 * rather than re-derived here from the user: the same deleted-store exclusion
 * gates the controller's per-message 403, and deriving it once keeps the list
 * and the guard in step. (User::accessibleStoreIds() is deliberately not used
 * — it includes deleted stores, which this slice must not surface.)
 *
 * Query building only — no DB::transaction and no abort() calls in this
 * layer. The list is tenancy-scoped; getting the store scope wrong leaks
 * another business's inbox, which is why the composition lives in one named
 * place.
 */
final class SupportMessageRepository
{
    /**
     * The filtered, paginated inbox: the caller's store scope, the eager store
     * load, the free-text search over name/email/phone/message and the
     * status/store filters, with the legacy trim() kept over `q`.
     *
     * @param  Collection<int, int>  $storeIds
     * @param  array<string, mixed>  $filters  validated by SupportMessageIndexRequest
     */
    public function paginateForStores(Collection $storeIds, array $filters): LengthAwarePaginator
    {
        return SupportMessage::query()
            ->whereIn('store_id', $storeIds)
            ->with('store')
            ->when($filters['q'] ?? null, function ($query, $term) {
                $term = trim((string) $term);
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%"));
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            // Legacy ordered `status asc` alphabetically, which put `closed`
            // above `pending`; the inbox surfaces unanswered work first.
            ->orderByRaw("case status when 'pending' then 0 when 'replied' then 1 else 2 end")
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * Pending/total counts for the sidebar indicator and the page header,
     * scoped to the caller's stores. Shared by the list and the stats
     * endpoint. WS-34's shell-counts endpoint will eventually own shell
     * badges; until then the badge component reads this.
     *
     * @param  Collection<int, int>  $storeIds
     * @return array{pending: int, replied: int, closed: int, total: int}
     */
    public function counts(Collection $storeIds): array
    {
        $query = SupportMessage::query()->whereIn('store_id', $storeIds);

        return [
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'replied' => (clone $query)->where('status', 'replied')->count(),
            'closed' => (clone $query)->where('status', 'closed')->count(),
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * `replied_by_id` carries no relation on the model, so resolve the names
     * in one query per page instead of an N+1 (or touching a shared model).
     * Only business replies are named here — the payload nulls the name for
     * every other type. Reused by every payload site: the list, the detail
     * and the reply echo.
     *
     * @param  Collection<int, SupportMessage>  $messages
     * @return array<int, string>
     */
    public function replierNames(Collection $messages): array
    {
        $ids = $messages
            ->where('replied_by_type', 'business')
            ->pluck('replied_by_id')
            ->filter()
            ->unique();

        return $ids->isEmpty() ? [] : User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
