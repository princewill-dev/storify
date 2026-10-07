<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListActivityLogsRequest;
use App\Http\Resources\Admin\ActivityLogExportResource;
use App\Http\Resources\Admin\ActivityLogResource;
use App\Repositories\Admin\ActivityLogRepository;
use Illuminate\Http\JsonResponse;
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
 *
 * The filtered reads live in ActivityLogRepository, the two representations in
 * ActivityLogResource / ActivityLogExportResource, and the filter validation
 * in ListActivityLogsRequest; this class keeps the HTTP contract (status
 * codes, envelope, pagination meta) and the platform-admin guard.
 */
class ActivityLogController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly ActivityLogRepository $logs,
    ) {}

    /**
     * The platform trail is a platform-console read: audience + permission
     * alone are not enough. Every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, and that bundle contains the
     * admin.* names — so a business-scoped account holding a leaked
     * admin-audience token would otherwise satisfy `admin.activity-logs` and
     * read every tenant's audit rows. Platform admins (AdminAuthController
     * only signs in superadmin/admin) always pass.
     *
     * The guard stays in the controller body — not middleware, not
     * FormRequest::authorize() — so the refusal keeps its place in the
     * sequence: route middleware first, then this 403 for any valid payload.
     * (A payload that is malformed as well as unauthorised is rejected with
     * 422 by the FormRequest before this line — the accepted, inherent
     * consequence of extracting validation.)
     */
    public function index(ListActivityLogsRequest $request): JsonResponse|StreamedResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validated();

        // `export=csv` is a representation of this same endpoint, not a second
        // route — the workstream allows exactly one activity-log route.
        if (($filters['export'] ?? null) === 'csv') {
            return ActivityLogExportResource::stream($this->logs->exportQuery($filters));
        }

        $logs = $this->logs->paginateForIndex($filters);

        return $this->ok(
            ActivityLogResource::collection($logs->getCollection()->values())->resolve($request),
            null,
            200,
            $this->paginationMeta($logs) + ['filters' => $this->logs->filterOptions()],
        );
    }
}
