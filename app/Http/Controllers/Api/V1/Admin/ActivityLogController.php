<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WS-1 — Activity log & audit trail viewer.
 *
 * Registered by routes/api/v1/admin/ad01-activity-log.php under
 * `permission:admin.activity-logs`. Legacy exposed a list only, hard-gated to
 * the superadmin *role*; the permission is the modern equivalent (the audit
 * explicitly keeps this permission-only so a Platform/Support/Finance admin
 * can read). What this adds over legacy, per the audit: the detail columns it
 * captured but never showed (subject, old/new values, metadata, business),
 * and a filtered CSV export — both inside the same single route.
 */
class ActivityLogController extends ApiController
{
    /**
     * Columns the list may be sorted by; anything else falls back to newest
     * first. Never pass a request-supplied column straight to orderBy.
     */
    private const SORTABLE = ['created_at', 'action', 'ip_address'];

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorizePlatformAccess($request);

        $filters = $this->validatedFilters($request);
        $query = $this->filteredQuery($filters);

        // `export=csv` is a representation of this same endpoint, not a second
        // route — the workstream allows exactly one activity-log route.
        if (($filters['export'] ?? null) === 'csv') {
            return $this->exportCsv($query);
        }

        $logs = $query
            ->with($this->relations())
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return $this->ok(
            $logs->getCollection()->map(fn (ActivityLog $log) => $this->payload($log))->values()->all(),
            null,
            200,
            $this->paginationMeta($logs) + ['filters' => $this->filterOptions()],
        );
    }

    /**
     * The platform trail is a platform-console read: audience + permission
     * alone are not enough. Every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, and that bundle contains the
     * admin.* names — so a business-scoped account holding a leaked
     * admin-audience token would otherwise satisfy `admin.activity-logs` and
     * read every tenant's audit rows. Platform admins (AdminAuthController
     * only signs in superadmin/admin) always pass.
     */
    private function authorizePlatformAccess(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && in_array($user->role, [User::ROLE_SUPERADMIN, User::ROLE_ADMIN], true),
            403,
            'This endpoint is restricted to platform administrators.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'export' => ['nullable', Rule::in(['csv'])],
        ]);
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

    private function exportCsv(Builder $query): StreamedResponse
    {
        $filename = 'activity-logs-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // The explicit escape keeps PHP 8.4's fputcsv deprecation away and
            // follows RFC 4180 (enclosure-only quoting).
            fputcsv($handle, [
                'When', 'User', 'Action', 'Description', 'Subject',
                'Old values', 'New values', 'Metadata', 'Business', 'IP', 'User agent',
            ], ',', '"', '');

            $query->with($this->relations())
                ->reorder()
                ->chunkById(500, function (Collection $logs) use ($handle) {
                    foreach ($logs as $log) {
                        fputcsv($handle, array_map($this->csvCell(...), [
                            $log->created_at?->toDateTimeString() ?? '',
                            $log->user?->name ?? '—',
                            $log->action,
                            $log->description ?? '',
                            $log->subject_type !== null ? class_basename($log->subject_type).' #'.$log->subject_id : '',
                            // Redacted exactly like the JSON payload: rows written
                            // before ActivityRecorder existed may carry raw secrets.
                            json_encode($log->old_values === null ? [] : ActivityRecorder::redact($log->old_values), JSON_UNESCAPED_SLASHES),
                            json_encode($log->new_values === null ? [] : ActivityRecorder::redact($log->new_values), JSON_UNESCAPED_SLASHES),
                            json_encode(ActivityRecorder::redact($log->metadata ?? []), JSON_UNESCAPED_SLASHES),
                            $log->business?->name ?? '',
                            $log->ip_address ?? '',
                            $log->user_agent ?? '',
                        ]), ',', '"', '');
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Guard against spreadsheet formula injection when an export is opened in
     * Excel/Sheets: a description beginning "=" or "@" must stay text.
     */
    private function csvCell(mixed $value): string
    {
        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'user:id,name,account_code,role',
            'business:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            // Redacted again on read: rows written before ActivityRecorder
            // existed (and by other writers) may carry raw secrets.
            'old_values' => $log->old_values === null ? null : ActivityRecorder::redact($log->old_values),
            'new_values' => $log->new_values === null ? null : ActivityRecorder::redact($log->new_values),
            'metadata' => ActivityRecorder::redact($log->metadata ?? []),
            'business_id' => $log->business_id,
            'business' => $log->business?->name,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'account_code' => $log->user->account_code,
                'role' => $log->user->role,
            ] : null,
            'created_at' => $log->created_at?->toISOString(),
        ];
    }

    /**
     * Filter lookups. The action list is distinct values *present in the
     * table* (legacy behaviour, kept so the dropdown can never offer an action
     * that returns zero rows); the user list is the same idea — only users a
     * filter can actually match, where legacy dumped every account.
     *
     * @return array{users: array<int, array{id: int, name: string}>, actions: array<int, string>}
     */
    private function filterOptions(): array
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
