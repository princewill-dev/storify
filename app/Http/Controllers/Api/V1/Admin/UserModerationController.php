<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ActivateUserRequest;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\StopImpersonationRequest;
use App\Http\Requests\Admin\SuspendUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\ImpersonationResource;
use App\Http\Resources\Admin\UserDetailResource;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Repositories\Admin\UserModerationRepository;
use App\Services\Admin\UserModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-8 (admin console) — user moderation completion.
 *
 * The previous admin API exposed a thin directory, a drawer payload and bare
 * suspend/activate/verify actions that flipped `status` and nothing else. This
 * controller carries the legacy console whole: the directory filters/stats and
 * Plan column, the detail payload (business metrics, subscription + payments,
 * last-IP, force-password-change flag, activity feed), edit with a real audit
 * row, suspend with a required reason + main-store guard + email, activate with
 * the legacy default reason and KYC auto-approval, reset-password, guarded
 * soft delete, restore, and impersonation start/stop with an explicit token
 * hand-off contract.
 *
 * The route module re-registers the seven `users` URIs the shared
 * `routes/api/v1/admin.php` already owns (that file belongs to the
 * orchestrator), so `route:list` keeps exactly one entry per URI. The old
 * `Api\V1\Admin\UserController` is left on disk untouched for the orchestrator
 * to retire.
 *
 * Legacy defects deliberately not cloned (admin roadmap §24 / audit 1.4–1.9):
 *
 * - suspend's reason is required again (the previous API accepted none) and
 *   the missing main-store guard + suspension email + already-suspended guard
 *   are restored;
 * - delete carries legacy's three guards (main store, open orders, open
 *   transactions) that the old API dropped entirely;
 * - every write emits one of the ten legacy `user_*` audit actions with
 *   old/new values (`ActivityRecorder`, redacting secrets structurally);
 * - activation does not get a reason *form* — legacy submitted a hidden
 *   hardcoded reason, so a default reason restores parity and keeps the
 *   KYC reviewer note and the reactivation mail honest.
 *
 * Platform-console guard: a business-scoped account's in-business "Super
 * Admin" role bundles the `admin.*` permission names, so `permission:admin.users`
 * alone would let a leaked admin-audience token moderate every tenant. Every
 * action re-checks the platform role (same hole WS-1/WS-5 documented).
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin
 * FormRequests, queries in `UserModerationRepository`, workflows and their
 * transaction boundaries in `UserModerationService`, response shaping in
 * `UserResource` / `UserDetailResource` / `ImpersonationResource`. The
 * platform-admin guard and the managed-role 404 deliberately stay here so
 * their order (403 before 404) is unchanged.
 */
class UserModerationController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly UserModerationRepository $users,
        private readonly UserModerationService $moderation,
    ) {}

    /**
     * The platform user directory. Legacy defaulted to owners when no role was
     * chosen; `role=all` (or an empty role) is the explicit "every managed
     * role" option the previous SPA silently used.
     */
    public function index(ListUsersRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $users = $this->users->paginateForDirectory($request->validated());

        return $this->ok(
            $users->getCollection()->map(fn (User $user) => $this->row($user))->values()->all(),
            null,
            200,
            $this->paginationMeta($users) + ['stats' => $this->users->stats()],
        );
    }

    /**
     * The detail console: account, business metrics, subscription + last
     * payments, and the per-user activity feed. Legacy rendered all of it in
     * one page; the drawer-only API lost five of the six blocks.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $this->users->loadDetailRelations($user);

        return $this->ok([
            'user' => UserDetailResource::make($user, $this->users->detailBlocks($user))->resolve($request),
        ]);
    }

    /**
     * Edit name/email/phone (validation — and the legacy parity decision
     * behind it — lives in UpdateUserRequest) with a real audit row.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $this->moderation->update($user, $request->validated(), $request->user());

        return $this->ok(['user' => $this->row($user->fresh())], 'User updated.');
    }

    /**
     * Suspend with legacy's required reason, main-store protection,
     * already-suspended warning, notification email and audit row.
     */
    public function suspend(SuspendUserRequest $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        if ($this->users->ownsMainStore($user)) {
            $this->moderation->recordSuspendBlockedByMainStore($user, $request->user());

            return $this->error('This user owns the main store and cannot be suspended.', 422);
        }

        if ($user->status === 'suspended') {
            return $this->ok(
                ['user' => $this->row($user), 'changed' => false, 'warning' => 'User is already suspended.'],
                'User is already suspended.',
            );
        }

        $notified = $this->moderation->suspend($user, $request->validated()['reason'], $request->user());

        return $this->ok(
            ['user' => $this->row($user->fresh()), 'changed' => true, 'notified' => $notified],
            'User suspended.',
        );
    }

    /**
     * Activate a suspended user: legacy default reason (no form), KYC
     * auto-approval through the shared KycApprovalService, reactivation email
     * and audit row. `deleted` users go through restore — activating them here
     * would make one door out of a delete that has guards.
     */
    public function activate(ActivateUserRequest $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        if ($user->status === 'deleted') {
            return $this->error('This user is deleted. Restore the account instead.', 422);
        }

        if ($user->status === 'active') {
            return $this->ok(
                ['user' => $this->row($user), 'changed' => false, 'warning' => 'User is already active.'],
                'User is already active.',
            );
        }

        ['kyc_approved' => $kycApproved, 'notified' => $notified] = $this->moderation->activate(
            $user,
            $request->validated()['reason'] ?? null,
            $request->user(),
        );

        return $this->ok(
            [
                'user' => $this->row($user->fresh()),
                'changed' => true,
                'notified' => $notified,
                'kyc_approved' => $kycApproved,
            ],
            $kycApproved ? 'User activated and KYC approved.' : 'User activated.',
        );
    }

    public function verify(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $this->moderation->verify($user, $request->user());

        return $this->ok(['user' => $this->row($user->fresh())], 'User verified.');
    }

    public function unverify(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $this->moderation->unverify($user, $request->user());

        return $this->ok(['user' => $this->row($user->fresh())], 'User verification removed.');
    }

    /**
     * Support-driven password reset: a `XXXX-xxxx-NNNN` temporary password,
     * `force_password_change`, and the reset mail. When mail queueing fails the
     * temporary password is returned in the response so the admin can still
     * hand it over — legacy flashed it for exactly this reason.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        ['temporary_password' => $temporaryPassword, 'emailed' => $notified] = $this->moderation->resetPassword($user, $request->user());

        if (! $notified) {
            return $this->ok([
                'user' => $this->row($user->fresh()),
                'temporary_password' => $temporaryPassword,
                'emailed' => false,
            ], 'Password reset, but the email could not be queued. Hand the temporary password over manually.');
        }

        return $this->ok([
            'user' => $this->row($user->fresh()),
            'emailed' => true,
        ], 'Temporary password generated and emailed to the user.');
    }

    /**
     * Guarded soft delete (legacy sets `status = deleted`; the row and its
     * history survive). Refusals: main-store owner, stores with orders not
     * `completed`, stores with transactions not `confirmed`.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        if ($this->users->ownsMainStore($user)) {
            $this->moderation->recordDeleteBlockedByMainStore($user, $request->user());

            return $this->error('This user owns the main store and cannot be deleted.', 422);
        }

        $storeIds = $this->users->liveStoreIds($user);

        if ($storeIds !== []) {
            if ($this->users->hasIncompleteOrders($storeIds)) {
                return $this->error("Deletion rejected: {$user->name} has stores with incomplete orders.", 422);
            }

            if ($this->users->hasIncompleteTransactions($storeIds)) {
                return $this->error("Deletion rejected: {$user->name} has stores with incomplete transactions.", 422);
            }
        }

        $this->moderation->delete($user, $request->user());

        return $this->ok(['user' => $this->row($user->fresh())], "User '{$user->name}' has been deleted.");
    }

    /**
     * Restore a `deleted` user to `active` — the other half of the soft delete,
     * without which delete is a one-way door.
     */
    public function restore(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        if ($user->status !== 'deleted') {
            return $this->ok(
                ['user' => $this->row($user), 'changed' => false, 'warning' => 'This user is not deleted.'],
                'This user is not deleted.',
            );
        }

        $this->moderation->restore($user, $request->user());

        return $this->ok(['user' => $this->row($user->fresh())], 'User restored.');
    }

    /**
     * "Login as user" — issues a management-audience token pair for the user
     * and returns the hand-off contract the admin SPA consumes (response shape
     * in ImpersonationResource). The create → token → linkage → audit sequence
     * lives in UserModerationService and must not be reordered.
     */
    public function impersonate(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        /** @var User $admin */
        $admin = $request->user();

        if ($user->is($admin)) {
            return $this->error('You cannot impersonate yourself.');
        }

        if ($user->status === 'deleted') {
            return $this->error('Deleted users cannot be impersonated.');
        }

        ['impersonation' => $impersonation, 'pair' => $pair] = $this->moderation->startImpersonation($user, $admin, $request);

        return $this->ok(
            ImpersonationResource::make($impersonation)->handoff($user, $admin, $pair)->resolve($request),
            'You are now viewing as '.$user->name.'.',
        );
    }

    /**
     * Force-end an impersonation session started from this console. The
     * management app's own "Return to Admin" control calls the
     * management-audience stop endpoint; this one lets the admin close a
     * session they left open (and writes the same `user_impersonation_stopped`
     * audit row the legacy stop wrote).
     */
    public function stopImpersonation(StopImpersonationRequest $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $impersonation = $this->users->findOpenImpersonation($user, $request->validated()['impersonation_id'] ?? null);

        if ($impersonation === null) {
            return $this->error('There is no active impersonation session for this user.', 404);
        }

        $this->moderation->stopImpersonation($impersonation, $user, $request->user());

        return $this->ok(ImpersonationResource::make($impersonation)->resolve($request), 'Impersonation session ended.');
    }

    /**
     * The directory row / action response shaped by UserResource. Kept as a
     * thin private seam so the response sites read as they did before the
     * extraction.
     *
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        return UserResource::make($user)->resolve();
    }

    private function ensureManaged(User $user): void
    {
        abort_unless(in_array($user->role, UserModerationRepository::MANAGED_ROLES, true), 404);
    }
}
