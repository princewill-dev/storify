<?php

namespace App\Repositories\Admin;

use App\Models\Store;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-16 (admin console) — the platform support inbox reads.
 *
 * This layer builds the queries and applies the eager loads; it never opens a
 * transaction and never calls abort() — the transaction boundary belongs to
 * SupportInboxService and the HTTP status each refusal maps to belongs to the
 * controller. Filters arrive already validated by
 * ListSupportMessagesRequest, so a bad sort or filter value never reaches
 * these queries.
 */
final class SupportMessageRepository
{
    /**
     * The platform inbox page: free-text search over the customer's name,
     * email, phone, message and reply, the status/store/date filters, the
     * pending-first ordering and the whitelisted sort.
     *
     * @param  array<string, mixed>  $filters  validated by ListSupportMessagesRequest
     */
    public function paginateInbox(array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        return SupportMessage::query()
            ->with(['store.business:id,name,business_code'])
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%")
                ->orWhere('reply', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            // Unanswered work first — what an inbox is for.
            ->orderByRaw("case status when 'pending' then 0 when 'replied' then 1 else 2 end")
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * Pending/replied/closed counters for the page chips and the nav badge.
     * Platform-wide, independent of the active filters (legacy chips were
     * global too). Shared by the list and the stats endpoint.
     *
     * @return array{pending: int, replied: int, closed: int, total: int}
     */
    public function counts(): array
    {
        $counts = SupportMessage::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $pending = $counts['pending'] ?? 0;
        $replied = $counts['replied'] ?? 0;
        $closed = $counts['closed'] ?? 0;

        return [
            'pending' => $pending,
            'replied' => $replied,
            'closed' => $closed,
            'total' => $pending + $replied + $closed,
        ];
    }

    /**
     * Distinct stores that actually hold messages — the filter dropdown
     * source. Deleted stores stay in the list: their messages are still owed
     * an answer, and SupportMessageStoreOptionResource marks the store's
     * status so the SPA can say so.
     *
     * @return Collection<int, Store>
     */
    public function storeOptions(): Collection
    {
        $storeIds = SupportMessage::query()->distinct()->pluck('store_id');

        return Store::query()
            ->with(['business:id,name,business_code'])
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * `replied_by_id` carries no relation on the model, so resolve the names
     * in one query per page rather than N+1 (or touching a shared model).
     * Both admins and business users live in the `users` table. Reused by
     * every payload site: the list, the detail and each mutation echo.
     *
     * @param  Collection<int, SupportMessage>  $messages
     * @return array<int, string>
     */
    public function replierNames(Collection $messages): array
    {
        $ids = $messages
            ->pluck('replied_by_id')
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
