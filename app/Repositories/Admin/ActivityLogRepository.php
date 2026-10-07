<?php

namespace App\Repositories\Admin;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-1 — the platform audit-trail reads behind the activity-log viewer.
 *
 * The filtered query is shared by the JSON list and the `export=csv`
 * representation, so it is composed once here, together with the eager loads,
 * the sort-whitelisted ordering and the distinct-value dropdown lookups. This
 * layer only builds queries — the controller owns the platform-admin guard and
 * the HTTP shape; nothing here opens a transaction or calls abort().
 */
class ActivityLogRepository
{
    /**
     * Columns the list may be sorted by; anything else falls back to newest
     * first. Never pass a request-supplied column straight to orderBy.
     */
    public const SORTABLE = ['created_at', 'action', 'ip_address'];

    /**
     * The relations both representations render.
     *
     * @var array<int, string>
     */
    private const RELATIONS = [
        'user:id,name,account_code,role',
        'business:id,name',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForIndex(array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->with(self::RELATIONS)
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();
    }

    /**
     * The same filtered rows for the CSV representation. `reorder()` keeps the
     * export's chunkById walk from inheriting any list ordering.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportQuery(array $filters): Builder
    {
        return $this->filteredQuery($filters)
            ->with(self::RELATIONS)
            ->reorder();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return ActivityLog::query()
            ->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Legacy searched action, description, IP and user agent.
                $like = '%'.$term.'%';

                $q->where(function ($inner) use ($like) {
                    $inner->where('action', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('ip_address', 'like', $like)
                        ->orWhere('user_agent', 'like', $like);
                });
            })
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
    }

    /**
     * Filter lookups. The action list is distinct values *present in the
     * table* (legacy behaviour, kept so the dropdown can never offer an action
     * that returns zero rows); the user list is the same idea — only users a
     * filter can actually match, where legacy dumped every account.
     *
     * @return array{users: array<int, array{id: int, name: string}>, actions: array<int, string>}
     */
    public function filterOptions(): array
    {
        $users = ActivityLog::query()
            ->join('users', 'users.id', '=', 'activity_logs.user_id')
            ->select('users.id', 'users.name')
            ->distinct()
            ->orderBy('users.name')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
            ->values()
            ->all();

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->values()
            ->all();

        return ['users' => $users, 'actions' => $actions];
    }
}
