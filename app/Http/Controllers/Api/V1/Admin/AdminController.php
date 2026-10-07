<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListAdminsRequest;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Resources\Admin\AdminAccountResource;
use App\Http\Resources\Admin\AdminRoleResource;
use App\Models\User;
use App\Repositories\Admin\AdminAccountRepository;
use App\Services\Admin\AdminAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-10 (admin console) — admin accounts & invitations.
 *
 * The new stack had no admins surface at all: `admin.admins` was seeded but
 * granted nothing, the admin SPA router had no `/admins` route, and the only
 * half that existed was the public accept endpoint. This controller carries the
 * legacy `Admin\AdminsController` whole — the directory with its platform-role
 * select, invite, resend, role change and removal — plus the pieces the legacy
 * console only wrote to the log file (audit rows through `ActivityRecorder`).
 *
 * Legacy guards kept verbatim (admin roadmap §24):
 * - an admin cannot change their own role or remove their own account;
 * - the superadmin account is immortal: role change and removal are refused;
 * - only platform roles are assignable — global (`business_id` null), `web`
 *   guard, Super Admin excluded (it is granted, never handed out).
 *
 * Deliberate decisions the audit asked for:
 * - Invite/uninvite states are honest: the role dropdown comes from
 *   `GET admins/roles`, never from a hardcoded list, so a role that does not
 *   exist cannot be submitted (legacy validated `exists:roles,name` and then
 *   re-checked `whereNull('business_id')` — both checks are kept server-side).
 * - Email uniqueness is checked across `users` **and** `customers` (the legacy
 *   cross-check the new stack never had); a customer address cannot be
 *   re-registered as a platform admin.
 * - Resend rotates the token and refreshes `invited_at`; when the invitation
 *   was already accepted it returns `changed: false` with an explanatory
 *   warning instead of silently mutating anything.
 * - Removal detaches Spatie roles and revokes access tokens before the hard
 *   delete, so a removed admin cannot keep a live session or leave orphaned
 *   pivot rows behind.
 *
 * The queueing mailable is `AdminInvitationSpaMail` — a sibling of the shared
 * `AdminInvitationMail` that points the accept link at the admin SPA route
 * this workstream ships (`/accept-invitation/:token`) instead of the legacy
 * Blade page. See that class for why a sibling was created rather than editing
 * the shared mailable.
 *
 * Platform-console guard: business-scoped "Super Admin" roles bundle the
 * `admin.*` permission names, so `permission:admin.admins` alone would let a
 * leaked admin-audience token manage platform accounts. Every action re-checks
 * the platform role via `EnsuresPlatformAdmin` (same hole WS-1/WS-5/WS-8
 * documented).
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests,
 * queries in `AdminAccountRepository`, workflows and their transaction
 * boundaries in `AdminAccountService`, response shaping in
 * `AdminAccountResource` / `AdminRoleResource`. The platform-admin guard and
 * the managed-role 404 deliberately stay here so their order (403 before 404)
 * is unchanged.
 */
class AdminController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * The platform account roles this console manages. Everything else in the
     * users table is a tenant account and is WS-8's surface. The canonical
     * list lives on AdminAccountRepository (the directory, the stats query and
     * the managed-role 404 all ride it); re-exported here because
     * AdminInvitationController resolves an invitation against the same list.
     */
    public const PLATFORM_ROLES = AdminAccountRepository::PLATFORM_ROLES;

    public function __construct(
        private readonly AdminAccountRepository $accounts,
        private readonly AdminAccountService $service,
    ) {}

    /**
     * The admin directory. Legacy showed every platform account newest-first
     * with no filters; the repository keeps that ordering and adds the
     * search/status filters and pagination the modern lists use.
     */
    public function index(ListAdminsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $viewer = $request->user();
        $admins = $this->accounts->paginateForDirectory($request->validated());

        return $this->ok(
            $admins->getCollection()->map(fn (User $admin) => $this->row($admin, $viewer))->values()->all(),
            null,
            200,
            $this->paginationMeta($admins) + ['stats' => $this->accounts->stats()],
        );
    }

    /**
     * The platform roles an admin can be invited with / moved to. Legacy
     * populated the row select from exactly this query; the SPA consumes it so
     * the contract can never drift from the seeder.
     */
    public function roles(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok([
            'roles' => AdminRoleResource::collection($this->accounts->assignableRoles())->resolve(),
        ]);
    }

    /**
     * Invite a platform admin. Legacy parity — the account shape, the Spatie
     * role assignment and the audit row — lives in
     * AdminAccountService::invite(), which also reports whether the invitation
     * mail was queued.
     */
    public function store(StoreAdminRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();
        $role = $this->accounts->platformRole($data['role']);

        if (! $role) {
            return $this->error('Please select a valid admin role.', 422, [
                'role' => ['Please select a valid admin role.'],
            ]);
        }

        $actor = $request->user();

        ['admin' => $admin, 'emailed' => $emailed] = $this->service->invite($data['email'], $role, $actor);

        return $this->ok([
            'admin' => $this->row($admin->fresh(), $actor),
            'emailed' => $emailed,
        ], "Invitation sent to {$admin->email} as {$role->name}.", 201);
    }

    /**
     * Resend an invitation: rotate the token, refresh `invited_at`, re-queue
     * the mail (AdminAccountService::resendInvitation()). An already-accepted
     * admin is answered with `changed: false` and a warning, never a silent
     * mutation (legacy flashed a warning; the new stack returns it in the
     * payload the SPA toasts).
     */
    public function resend(Request $request, User $admin): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($admin);

        if ($admin->status !== 'invited') {
            return $this->ok([
                'admin' => $this->row($admin, $request->user()),
                'changed' => false,
                'emailed' => false,
                'warning' => 'This admin has already accepted their invitation.',
            ], 'This admin has already accepted their invitation.');
        }

        $actor = $request->user();
        $emailed = $this->service->resendInvitation($admin, $actor);

        return $this->ok([
            'admin' => $this->row($admin->fresh(), $actor),
            'changed' => true,
            'emailed' => $emailed,
        ], 'Invitation resent.');
    }

    /**
     * Change an admin's platform role. `syncRoles` (not `assignRole`) because
     * a platform admin holds exactly one assignable role — the sync and its
     * audit row live in AdminAccountService::changeRole().
     */
    public function update(UpdateAdminRequest $request, User $admin): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($admin);

        if ($admin->id === $request->user()->id) {
            return $this->error('You cannot change your own role.');
        }

        if ($admin->role === User::ROLE_SUPERADMIN) {
            return $this->error('The superadmin role cannot be changed.');
        }

        $role = $this->accounts->platformRole($request->validated()['role']);

        if (! $role) {
            return $this->error('Please select a valid admin role.', 422, [
                'role' => ['Please select a valid admin role.'],
            ]);
        }

        $actor = $request->user();

        $this->service->changeRole($admin, $role, $actor);

        $fresh = $admin->fresh();
        $name = $this->displayName($fresh) ?? $fresh->email;

        return $this->ok(
            ['admin' => $this->row($fresh, $actor)],
            "{$name}'s role updated to {$role->name}.",
        );
    }

    /**
     * Remove a platform admin — legacy's hard delete, with its two guards.
     * Roles and access tokens are detached first; see
     * AdminAccountService::remove() for why and in what order.
     */
    public function destroy(Request $request, User $admin): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($admin);

        if ($admin->id === $request->user()->id) {
            return $this->error('You cannot remove your own account.');
        }

        if ($admin->role === User::ROLE_SUPERADMIN) {
            return $this->error('The superadmin account cannot be removed.');
        }

        $email = $admin->email;

        $this->service->remove($admin, $request->user());

        return $this->ok([], "{$email} has been removed.");
    }

    /**
     * Legacy `ensureManaged()`: anything that is not a platform account is a
     * 404 on this console (it belongs to WS-8), never a 403 — the caller must
     * not learn whether the id exists.
     */
    private function ensureManaged(User $admin): void
    {
        abort_unless(in_array($admin->role, self::PLATFORM_ROLES, true), 404);
    }

    /**
     * The directory row / action response shaped by AdminAccountResource. Kept
     * as a thin private seam so the response sites read as they did before the
     * extraction.
     *
     * @return array<string, mixed>
     */
    private function row(User $admin, ?User $viewer): array
    {
        return AdminAccountResource::make($admin, $viewer)->resolve();
    }

    /**
     * Display name for the update message on an account that may still be
     * "Pending setup" (legacy created admins with an empty name and rendered
     * that label literally). The resource applies the same normalisation for
     * `display_name`; this message falls back to the email instead.
     */
    private function displayName(User $admin): ?string
    {
        $name = trim((string) $admin->name);

        return $name === '' ? null : $name;
    }
}
