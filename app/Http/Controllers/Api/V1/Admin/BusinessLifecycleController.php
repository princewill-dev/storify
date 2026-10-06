<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\BusinessReasonRequest;
use App\Http\Requests\Admin\ListBusinessesRequest;
use App\Http\Requests\Admin\StoreBusinessRequest;
use App\Http\Requests\Admin\UpdateBusinessRequest;
use App\Http\Resources\Admin\BusinessResource;
use App\Models\Business;
use App\Repositories\Admin\BusinessRepository;
use App\Services\Admin\BusinessLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-4 (admin console) — business lifecycle & directory.
 *
 * The previous admin API exposed a read-only directory plus suspend/activate
 * that flipped `Business.status` and nothing else: no create, no edit, no
 * delete, no main-store guard, no owner cascade, no emails, no audit trail.
 * This controller carries the whole lifecycle, and the module route file
 * re-registers the four existing `businesses` URIs on top of it (the shared
 * `routes/api/v1/admin.php` is owned by the orchestrator and must not be
 * edited by a workstream), so the route table keeps exactly one entry per URI.
 *
 * Deliberate decisions the audit asked for:
 *
 * - **Canonical status.** Legacy's list read `Business.status` while its
 *   actions wrote the owner's `User.status`, so the two diverged. Here
 *   `Business.status` is canonical and every transition cascades to the owner
 *   deliberately (§3.7 of the admin roadmap).
 * - **Guard on delete.** Main-store ownership, store orders that are not
 *   `completed` and transactions that are not `confirmed` all refuse the
 *   delete (legacy's three guards, kept).
 * - **Edit cannot bypass the guards.** The status select on update accepts
 *   `active|pending|suspended` only — `deleted` is reachable exclusively
 *   through `destroy()`, which runs the guards (legacy allowed an edit to set
 *   `deleted` on the owner, silently bypassing all three).
 * - **Owner provisioning.** The legacy create path built a user with no
 *   password at all (`User::create($data)`, and `users.password` is NOT NULL),
 *   so it could only ever 500 in production. The admin now gets a one-time
 *   temporary password back and the owner is forced to change it on first
 *   login; the staff invitation flow's accept endpoint is staff-only, so it is
 *   deliberately not reused here (see store()).
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin
 * FormRequests, queries/persists in `BusinessRepository`, workflows and their
 * transaction boundaries in `BusinessLifecycleService`, response shaping in
 * `BusinessResource`; the platform-admin guard deliberately stays here so its
 * order relative to route binding and validation is unchanged.
 */
class BusinessLifecycleController extends ApiController
{
    /**
     * The admin console is a platform surface. Audience + `admin.businesses`
     * alone are not enough: every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, which contains the admin.* names
     * (WS-1 documented the same hole), so a business-scoped account holding a
     * leaked admin-audience token would otherwise read and mutate every tenant.
     */
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BusinessLifecycleService $lifecycle,
    ) {}

    /**
     * The directory. Adds the legacy filter set the audit flagged as missing
     * over the previous endpoint: created-date range, the `deleted` option
     * (with deleted rows hidden by default, like the legacy list), the
     * warehouses count, and `q` matching the owner phone the legacy
     * placeholder advertised.
     */
    public function index(ListBusinessesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $businesses = $this->businesses->paginateForDirectory($request->validated());

        return $this->ok(
            $businesses->getCollection()->map(fn (Business $business) => $this->payload($business))->values()->all(),
            null,
            200,
            $this->paginationMeta($businesses),
        );
    }

    /**
     * Admin-provisioned business + owner account.
     *
     * The multi-business guard is legacy's `ALLOW_MS_SETUP` flag: on a
     * single-business deployment the platform must not spin up competing
     * businesses once the superadmin account exists. Legacy's check compared
     * every user's email against the superadmin list — which matched the
     * superadmins themselves — so it reduced to "a superadmin exists"; that
     * intent is kept, with the flag readable from config so tests (and a
     * cached config) can set it. The guard and the transaction it gates live
     * in the lifecycle service.
     */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($this->lifecycle->provisioningBlocked()) {
            return $this->error(
                'Multi-business setup is disabled on this deployment. Set ALLOW_MS_SETUP=1 to provision additional businesses.',
                422,
            );
        }

        $created = $this->lifecycle->provision($request->validated(), $request->user());

        // The temporary password is returned once, to the admin who created
        // the account: this stack has no owner-facing invitation accept flow
        // (the management invitation endpoint only accepts role=staff), so the
        // credentials have to travel out-of-band and the owner is forced to
        // change them at first login.
        return $this->ok([
            'business' => $this->payload($created['business']->fresh(), detailed: true),
            'temporary_password' => $created['temporary_password'],
        ], 'Business created. Share the temporary password with the owner; they must change it at first login.', 201);
    }

    /**
     * The business console: everything the legacy detail page answered —
     * owner (with phone/status/verified badge), team with roles, stores with
     * their ownership/business types, warehouses with stock counts, the
     * subscription with its end date, and the KYC panel.
     */
    public function show(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok(['business' => $this->payload($business, detailed: true)]);
    }

    /**
     * Owner/business details from the list or the console.
     */
    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($business->status === 'deleted') {
            return $this->error('A deleted business cannot be edited.', 422);
        }

        $owner = $business->owner;

        if ($owner === null) {
            return $this->error('This business has no owner account to update.', 422);
        }

        $this->lifecycle->update($business, $owner, $request->validated(), $request->user());

        return $this->ok(['business' => $this->payload($business->fresh(), detailed: true)], 'Business updated.');
    }

    /**
     * Soft delete with legacy's three guards. The row survives (`status` moves
     * to `deleted` on the business and cascades to the owner) so the audit
     * trail and historical orders keep resolving.
     */
    public function destroy(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($this->businesses->ownsMainStore($business)) {
            $this->lifecycle->recordDeleteBlockedByMainStore($business, $request->user());

            return $this->error('This business owns the main store and cannot be deleted.', 422);
        }

        $storeIds = $this->businesses->liveStoreIds($business);

        if ($storeIds !== []) {
            if ($this->businesses->hasIncompleteOrders($storeIds)) {
                return $this->error("Deletion rejected: {$business->name} has stores with incomplete orders.", 422);
            }

            if ($this->businesses->hasIncompleteTransactions($storeIds)) {
                return $this->error("Deletion rejected: {$business->name} has stores with incomplete transactions.", 422);
            }
        }

        $this->lifecycle->delete($business, $request->user());

        return $this->ok([], "Business '{$business->name}' has been deleted.");
    }

    /**
     * Suspend the business and its owner, with the main-store guard and the
     * owner notification legacy sent. Legacy never guarded activate — only
     * suspend and delete — so the asymmetry is kept.
     */
    public function suspend(BusinessReasonRequest $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        if ($business->status === 'deleted') {
            return $this->error('A deleted business cannot be suspended.', 422);
        }

        if ($this->businesses->ownsMainStore($business)) {
            return $this->error('This business owns the main store and cannot be suspended.', 422);
        }

        $this->lifecycle->suspend($business, $data['reason'], $request->user());

        return $this->ok(['business' => $this->payload($business->fresh(), detailed: true)], 'Business suspended.');
    }

    /**
     * Activate the business, cascade the owner active and auto-approve an open
     * KYC submission (legacy's single approval path, gone from the previous
     * API). Legacy's auto-approval persisted only status + reviewer because
     * `reviewed_at` was not a column and `reviewer_notes` was not fillable;
     * the shared KycApprovalService writes the note, `approved_at` and the
     * review audit row properly.
     */
    public function activate(BusinessReasonRequest $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        if ($business->status === 'deleted') {
            return $this->error('A deleted business cannot be activated.', 422);
        }

        $kycApproved = $this->lifecycle->activate($business, $data['reason'], $request->user());

        return $this->ok(
            ['business' => $this->payload($business->fresh(), detailed: true), 'kyc_approved' => $kycApproved],
            $kycApproved ? 'Business activated and KYC approved.' : 'Business activated.',
        );
    }

    /**
     * Legacy's verify moved off the owner's user page onto the business
     * console; the same effect as WS-8's `POST /admin/users/{account}/verify`
     * without leaving the page. Gated by `permission:admin.users` in the route
     * file, matching the legacy gate on the user verify action.
     */
    public function verifyOwner(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $owner = $business->owner;

        if ($owner === null) {
            return $this->error('This business has no owner account to verify.', 422);
        }

        $this->lifecycle->verifyOwner($business, $owner, $request->user());

        return $this->ok(['business' => $this->payload($business->fresh(), detailed: true)], 'Owner email marked verified.');
    }

    /**
     * The directory row or the full console payload, shaped by
     * BusinessResource. Kept as a thin private seam so the response sites
     * read as they did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function payload(Business $business, bool $detailed = false): array
    {
        return BusinessResource::make($business)->detailed($detailed)->resolve();
    }
}
