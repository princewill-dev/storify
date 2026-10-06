<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\KycApplication;
use App\Models\User;
use App\Services\KycApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
 */
class KycApplicationController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(private readonly KycApprovalService $kyc) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $this->validatedFilters($request);

        // Roadmap default: the queue opens on what needs a decision. `all`
        // is an explicit opt-out, not an empty string.
        $status = $filters['status'] ?? KycApplication::STATUS_SUBMITTED;

        $applications = $this->filteredQuery($filters, $status)
            ->with($this->relations())
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return $this->ok(
            $applications->getCollection()->map(fn (KycApplication $application) => $this->listPayload($application))->values()->all(),
            null,
            200,
            $this->paginationMeta($applications) + ['status_counts' => $this->statusCounts()],
        );
    }

    public function show(Request $request, KycApplication $application): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $application->load($this->relations());

        return $this->ok(['application' => $this->detailPayload($application)]);
    }

    public function approve(Request $request, KycApplication $application): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        $result = $this->kyc->approve($application, $admin, $data['review_notes'] ?? null);

        return $this->ok(
            [
                'application' => $this->detailPayload($result->application->load($this->relations())),
                'notified' => $result->notified,
            ],
            $result->notified
                ? 'KYC application approved and the business owner notified.'
                : 'KYC application approved, but the notification email could not be queued — notify the owner manually.',
        );
    }

    public function reject(Request $request, KycApplication $application): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        $result = $this->kyc->reject($application, $admin, $data['review_notes']);

        // Legacy always flashed "the business owner notified" while sending
        // nothing; the message now follows the mail queue's actual outcome.
        return $this->ok(
            [
                'application' => $this->detailPayload($result->application->load($this->relations())),
                'notified' => $result->notified,
            ],
            $result->notified
                ? 'KYC application rejected and the business owner notified.'
                : 'KYC application rejected, but the notification email could not be queued — contact the owner directly.',
        );
    }

    /**
     * The platform KYC queue is a cross-tenant read (including identity
     * documents). Audience + permission alone are not enough: the in-business
     * "Super Admin" role is seeded with the full permission bundle, admin.*
     * names included, so a business-scoped account holding an admin-audience
     * token would otherwise read every business's application. Platform
     * admins (AdminAuthController only signs in superadmin/admin) pass.
     */

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in([
                KycApplication::STATUS_DRAFT,
                KycApplication::STATUS_SUBMITTED,
                KycApplication::STATUS_APPROVED,
                KycApplication::STATUS_REJECTED,
                'all',
            ])],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $filters, string $status): Builder
    {
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
            });
    }

    /**
     * Platform-wide counts for the queue pills, like legacy's statusCounts.
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
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
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'user:id,name,email,phone,account_code,status,is_verified,business_id,created_at',
            'business:id,name,business_code,status,created_at',
            'reviewer:id,name,account_code',
            'documentType:id,name,code',
        ];
    }

    /**
     * Row shape for the queue table: the legacy columns plus the document
     * indicators a reviewer scans for before opening the application.
     *
     * @return array<string, mixed>
     */
    private function listPayload(KycApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'status_label' => $application->status_metadata['label'] ?? ucfirst($application->status),
            'legal_name' => $application->legal_name,
            'submitted_at' => $application->submitted_at?->toISOString(),
            'reviewed_at' => ($application->approved_at ?? $application->rejected_at)?->toISOString(),
            'business' => $application->business ? [
                'id' => $application->business->id,
                'name' => $application->business->name,
                'business_code' => $application->business->business_code,
                'status' => $application->business->status,
            ] : null,
            'owner' => $application->user ? [
                'id' => $application->user->id,
                'name' => $application->user->name,
                'email' => $application->user->email,
                'phone' => $application->user->phone,
                'account_code' => $application->user->account_code,
                'status' => $application->user->status,
            ] : null,
            'reviewer' => $application->reviewer ? [
                'id' => $application->reviewer->id,
                'name' => $application->reviewer->name,
            ] : null,
            'has_identification_document' => (bool) $application->identification_document_path,
            'has_selfie' => (bool) $application->selfie_image_path,
        ];
    }

    /**
     * Full review payload. Everything legacy stored is surfaced here —
     * including the selfie, the document type/id and the raw payload, plus
     * the owner's earlier applications so a reviewer can see a rejection the
     * resubmission was meant to fix.
     *
     * @return array<string, mixed>
     */
    private function detailPayload(KycApplication $application): array
    {
        return $this->listPayload($application) + [
            'phone_number' => $application->phone_number,
            'date_of_birth' => $application->date_of_birth?->toDateString(),
            'address_line' => $application->address_line,
            'city' => $application->city,
            'state' => $application->state,
            'country' => $application->country,
            'device_type' => $application->device_type,
            'browser' => $application->browser,
            'ip_address' => $application->ip_address,
            'kyc_document_type_id' => $application->kyc_document_type_id,
            'document_type' => $application->documentType ? [
                'id' => $application->documentType->id,
                'name' => $application->documentType->name,
                'code' => $application->documentType->code,
            ] : null,
            'kyc_document_id' => $application->kyc_document_id,
            'identification_document_url' => $application->identification_document_path
                ? asset('storage/'.$application->identification_document_path)
                : null,
            'selfie_url' => $application->selfie_image_path
                ? asset('storage/'.$application->selfie_image_path)
                : null,
            // Deliberate: legacy persisted this array and never displayed it.
            'payload' => $application->payload,
            'review_notes' => $application->review_notes,
            'approved_at' => $application->approved_at?->toISOString(),
            'rejected_at' => $application->rejected_at?->toISOString(),
            'can_review' => $application->status === KycApplication::STATUS_SUBMITTED,
            'history' => $this->history($application),
        ];
    }

    /**
     * The same owner's earlier applications, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(KycApplication $application): array
    {
        return KycApplication::query()
            ->where('user_id', $application->user_id)
            ->whereKeyNot($application->getKey())
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (KycApplication $previous) => [
                'id' => $previous->id,
                'status' => $previous->status,
                'status_label' => $previous->status_metadata['label'] ?? ucfirst($previous->status),
                'submitted_at' => $previous->submitted_at?->toISOString(),
                'reviewed_at' => ($previous->approved_at ?? $previous->rejected_at)?->toISOString(),
                'review_notes' => $previous->review_notes,
            ])
            ->all();
    }
}
