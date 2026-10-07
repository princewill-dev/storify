<?php

namespace App\Repositories\Admin;

use App\Models\KycApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-3 (admin console) — KYC review queue and review-screen reads.
 *
 * Query composition only: the queue filters/search/eager loads, the platform
 * status-count pills and the owner's earlier applications. This layer never
 * opens a transaction and never calls abort() — the transition itself (and its
 * transaction boundary) lives in KycApprovalService, and the HTTP statuses
 * stay in the controller. The queue is platform-wide: unlike the
 * management-side submission screen, an admin does not scope it to one
 * business. The platform-admin guard runs in the controller ahead of every
 * read.
 */
final class KycReviewRepository
{
    /**
     * Eager loads shared by the queue and the review screen, with the exact
     * column lists the payloads read.
     *
     * @var array<int, string>
     */
    public const RELATIONS = [
        'user:id,name,email,phone,account_code,status,is_verified,business_id,created_at',
        'business:id,name,business_code,status,created_at',
        'reviewer:id,name,account_code',
        'documentType:id,name,code',
    ];

    /**
     * The platform KYC queue, latest-submitted-first, paginated.
     *
     * Roadmap default: the queue opens on what needs a decision. `all` is an
     * explicit opt-out, not an empty string.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForQueue(array $filters): LengthAwarePaginator
    {
        $status = $filters['status'] ?? KycApplication::STATUS_SUBMITTED;

        return KycApplication::query()
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                // Legacy had no search at all; an admin reviewing a queue
                // this size needs one. Searches the columns the table shows.
                $like = '%'.$term.'%';

                $query->where(function (Builder $inner) use ($like) {
                    $inner->where('legal_name', 'like', $like)
                        ->orWhereHas('user', fn (Builder $user) => $user
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like))
                        ->orWhereHas('business', fn (Builder $business) => $business
                            ->where('name', 'like', $like)
                            ->orWhere('business_code', 'like', $like));
                });
            })
            ->with(self::RELATIONS)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Platform-wide counts for the queue pills, like legacy's statusCounts.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = KycApplication::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusCounts = [
            KycApplication::STATUS_DRAFT => (int) ($counts[KycApplication::STATUS_DRAFT] ?? 0),
            KycApplication::STATUS_SUBMITTED => (int) ($counts[KycApplication::STATUS_SUBMITTED] ?? 0),
            KycApplication::STATUS_APPROVED => (int) ($counts[KycApplication::STATUS_APPROVED] ?? 0),
            KycApplication::STATUS_REJECTED => (int) ($counts[KycApplication::STATUS_REJECTED] ?? 0),
        ];

        return $statusCounts + ['all' => array_sum($statusCounts)];
    }

    /**
     * Eager-load the shared relations on a review-screen row. The approve and
     * reject responses re-read their application via `fresh()`, so the same
     * load runs there before shaping.
     */
    public function loadDetailRelations(KycApplication $application): KycApplication
    {
        return $application->load(self::RELATIONS);
    }

    /**
     * The same owner's earlier applications, newest first — the resubmission
     * context the review screen carries so a reviewer can judge a resubmission
     * against what went wrong before. Shaping happens in
     * KycApplicationDetailResource.
     *
     * @return Collection<int, KycApplication>
     */
    public function historyFor(KycApplication $application): Collection
    {
        return KycApplication::query()
            ->where('user_id', $application->user_id)
            ->whereKeyNot($application->getKey())
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }
}
