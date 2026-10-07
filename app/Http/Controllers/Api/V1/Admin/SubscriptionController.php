<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListSubscriptionsRequest;
use App\Http\Resources\Admin\SubscriptionResource;
use App\Models\Subscription;
use App\Repositories\Admin\SubscriptionRepository;
use Illuminate\Http\JsonResponse;

/**
 * WS-11 (admin console) — subscription oversight list.
 *
 * Legacy `/office/subscriptions` was a read-only, 15-a-page list with a status
 * filter and free-text search over business or plan name — no detail screen
 * and, per the audit verification, no cancel/suspend/extend action anywhere in
 * the platform for anyone. Parity means the same: this controller reads, it
 * never mutates a subscription. (`status = expired/suspended` rows only exist
 * if another process writes them; the list still renders them.)
 *
 * Improve on legacy: the `trial` value the legacy filter offered never existed
 * as a row status — trials live on `users.trial_ends_at`, and a plan flagged
 * `is_trial` is a template every checkout path excludes. Here `trial` filters
 * on the two places trial-ness can actually be recorded: a trial-plan template
 * or the metadata flag. Trial rows are a facet of their own: they are excluded
 * from the five status buckets — in the filter and in `status_counts` alike —
 * so every pill returns exactly the number of rows printed on it and the
 * counts partition `all`.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in
 * `ListSubscriptionsRequest`, the list and its trial facet in
 * `SubscriptionRepository`, and row shaping in `SubscriptionResource`. The
 * platform-admin guard deliberately stays in the body — it must not move into
 * FormRequest::authorize() or middleware. No service layer is introduced: the
 * endpoint is read-only, with no transaction, workflow or side effect to own.
 */
class SubscriptionController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
    ) {}

    public function index(ListSubscriptionsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validated();

        $subscriptions = $this->subscriptions->paginateDirectory($filters, (int) ($filters['per_page'] ?? 15));

        return $this->ok(
            $subscriptions->getCollection()->map(fn (Subscription $subscription) => $this->payload($subscription))->values()->all(),
            null,
            200,
            $this->paginationMeta($subscriptions) + ['status_counts' => $this->subscriptions->statusCounts()],
        );
    }

    /**
     * The row shape, kept as a thin seam so the response site reads as it did
     * before the extraction; the fields live in SubscriptionResource.
     *
     * @return array<string, mixed>
     */
    private function payload(Subscription $subscription): array
    {
        return SubscriptionResource::make($subscription)->resolve();
    }
}
