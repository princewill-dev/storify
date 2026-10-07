<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ApproveKycApplicationRequest;
use App\Http\Requests\Admin\ListKycApplicationsRequest;
use App\Http\Requests\Admin\RejectKycApplicationRequest;
use App\Http\Resources\Admin\KycApplicationDetailResource;
use App\Http\Resources\Admin\KycApplicationResource;
use App\Models\KycApplication;
use App\Models\User;
use App\Repositories\Admin\KycReviewRepository;
use App\Services\KycApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-3 — KYC review queue and review screen (admin console).
 *
 * Registered by routes/api/v1/admin/ad03-kyc-review.php under
 * `permission:admin.businesses`. Legacy's controller is the behavioural
 * reference (Admin\BusinessKycApplicationController): status-count pills, a
 * status filter, latest-submitted-first pagination of 20, and approve/reject
 * with optional/required review notes. Everything here is platform-wide —
 * unlike the management-side submission screen, an admin does not scope the
 * queue to one business.
 *
 * Deliberate improvements over legacy, per the audit:
 *  - the review payload surfaces `selfie_image_path`, `kyc_document_type_id`,
 *    `kyc_document_id` and `payload`, all stored by legacy but never shown
 *    (§24.3);
 *  - the transition itself guards on `submitted` so a direct POST cannot
 *    re-approve or re-reject (§24.4) — the guard lives in KycApprovalService;
 *  - a rejection actually emails the owner, and the flash reflects whether
 *    that queueing worked instead of always claiming it did (§24.1).
 *
 * Platform-admin guard: the platform KYC queue is a cross-tenant read
 * (including identity documents). Audience + permission alone are not enough:
 * the in-business "Super Admin" role is seeded with the full permission
 * bundle, admin.* names included, so a business-scoped account holding an
 * admin-audience token would otherwise read every business's application.
 * Platform admins (AdminAuthController only signs in superadmin/admin) pass.
 * The guard deliberately stays in this controller — not in middleware or
 * FormRequest::authorize() — so it runs (403) before anything else in the
 * body, ahead of the service transition.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin
 * FormRequests, reads in KycReviewRepository, the transition and its
 * transaction boundary in KycApprovalService, response shaping in
 * KycApplicationResource / KycApplicationDetailResource.
 */
class KycApplicationController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly KycReviewRepository $applications,
        private readonly KycApprovalService $kyc,
    ) {}

    public function index(ListKycApplicationsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $applications = $this->applications->paginateForQueue($request->validated());

        return $this->ok(
            $applications->getCollection()->map(fn (KycApplication $application) => $this->row($application))->values()->all(),
            null,
            200,
            $this->paginationMeta($applications) + ['status_counts' => $this->applications->statusCounts()],
        );
    }

    public function show(Request $request, KycApplication $application): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok(['application' => $this->detail($application)]);
    }

    public function approve(ApproveKycApplicationRequest $request, KycApplication $application): JsonResponse
    {
        $this->authorizePlatformAdmin();

        /** @var User $admin */
        $admin = $request->user();

        $result = $this->kyc->approve($application, $admin, $request->validated()['review_notes'] ?? null);

        return $this->ok(
            [
                'application' => $this->detail($result->application),
                'notified' => $result->notified,
            ],
            $result->notified
                ? 'KYC application approved and the business owner notified.'
                : 'KYC application approved, but the notification email could not be queued — notify the owner manually.',
        );
    }

    public function reject(RejectKycApplicationRequest $request, KycApplication $application): JsonResponse
    {
        $this->authorizePlatformAdmin();

        /** @var User $admin */
        $admin = $request->user();

        $result = $this->kyc->reject($application, $admin, $request->validated()['review_notes']);

        // Legacy always flashed "the business owner notified" while sending
        // nothing; the message now follows the mail queue's actual outcome.
        return $this->ok(
            [
                'application' => $this->detail($result->application),
                'notified' => $result->notified,
            ],
            $result->notified
                ? 'KYC application rejected and the business owner notified.'
                : 'KYC application rejected, but the notification email could not be queued — contact the owner directly.',
        );
    }

    /**
     * The queue row shaped by KycApplicationResource. Kept as a thin private
     * seam so the response sites read as they did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function row(KycApplication $application): array
    {
        return KycApplicationResource::make($application)->resolve();
    }

    /**
     * The review payload shaped by KycApplicationDetailResource: the shared
     * relations are eager-loaded and the owner's earlier applications fetched
     * here, so response shaping issues no queries of its own.
     *
     * @return array<string, mixed>
     */
    private function detail(KycApplication $application): array
    {
        return KycApplicationDetailResource::make(
            $this->applications->loadDetailRelations($application),
            $this->applications->historyFor($application),
        )->resolve();
    }
}
