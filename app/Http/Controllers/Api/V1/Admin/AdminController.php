<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\AdminInvitationSpaMail;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

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
 */
class AdminController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * The platform account roles this console manages. Everything else in the
     * users table is a tenant account and is WS-8's surface.
     */
    public const PLATFORM_ROLES = [User::ROLE_SUPERADMIN, User::ROLE_ADMIN];

    /**
     * The one platform role that can never be assigned or managed. Super Admin
     * is seeded with every permission and is deliberately absent from the
     * assignable-role list (legacy: `where('name', '!=', 'Super Admin')`).
     */
    private const SUPER_ADMIN_ROLE = 'Super Admin';

    /**
     * Columns the directory may be sorted by. Never pass a request-supplied
     * column straight to orderBy (admin roadmap §3.3).
     */
    private const SORTABLE = ['name', 'email', 'status', 'invited_at', 'last_login_at', 'created_at'];

    /**
     * The admin directory. Legacy showed every platform account newest-first
     * with no filters; this keeps that ordering and adds the search/status
     * filters and pagination the modern lists use.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['invited', 'active', 'suspended'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $viewer = $request->user();

        $query = User::query()
            ->whereIn('role', self::PLATFORM_ROLES)
            // No column restriction: Spatie's team constraint reads the pivot
            // columns, and a `roles:id,name` select drops them.
            ->with('roles');

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $term = '%'.trim($filters['q']).'%';
            $query->where(fn ($inner) => $inner->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('account_code', 'like', $term));
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        $admins = $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            $admins->getCollection()->map(fn (User $admin) => $this->adminPayload($admin, $viewer))->values()->all(),
            null,
            200,
            $this->paginationMeta($admins) + ['stats' => $this->stats()],
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

        $roles = Role::query()
            ->whereNull('business_id')
            ->where('guard_name', 'web')
            ->where('name', '!=', self::SUPER_ADMIN_ROLE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
            ->values()
            ->all();

        return $this->ok(['roles' => $roles]);
    }

    /**
     * Invite a platform admin. Mirrors the legacy `store()`: `role = admin`,
     * `status = invited`, a 64-char token, a random unusable password and
     * `force_password_change`, the Spatie role assigned under team `null`, and
     * the invitation mailed. The audit row is new (legacy only wrote a log
     * line), as is the `emailed` flag — a mail-queue failure must not lose the
     * account, but the admin deserves to know the invite did not go out.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'role' => ['required', 'string', 'max:255', Rule::exists('roles', 'name')],
        ]);

        $role = $this->platformRole($data['role']);

        if (! $role) {
            return $this->error('Please select a valid admin role.', 422, [
                'role' => ['Please select a valid admin role.'],
            ]);
        }

        $actor = $request->user();

        $admin = DB::transaction(function () use ($data, $role, $actor) {
            $admin = User::create([
                'name' => '',
                'email' => $data['email'],
                'role' => User::ROLE_ADMIN,
                'business_id' => null,
                'status' => 'invited',
                'invitation_token' => Str::random(64),
                'invited_at' => now(),
                // Unusable random password; the invitee sets their own on the
                // accept screen. `password` is a hashed cast, so the plain
                // string is hashed once by the model.
                'password' => Str::random(32),
                'force_password_change' => true,
            ]);

            setPermissionsTeamId(null);
            $admin->assignRole($role);

            ActivityRecorder::record(
                action: 'admin.invited',
                description: "Invited {$admin->email} as {$role->name}",
                subject: $admin,
                new: ['email' => $admin->email, 'role' => $role->name, 'status' => 'invited'],
                metadata: ['invited_by' => $actor->id, 'role' => $role->name],
                actor: $actor,
            );

            return $admin;
        });

        $emailed = $this->queueInvitation($admin, 'admin.invitation.mail_failed');

        return $this->ok([
            'admin' => $this->adminPayload($admin->fresh(), $actor),
            'emailed' => $emailed,
        ], "Invitation sent to {$admin->email} as {$role->name}.", 201);
    }

    /**
     * Resend an invitation: rotate the token, refresh `invited_at`, re-queue
     * the mail. An already-accepted admin is answered with `changed: false`
     * and a warning, never a silent mutation (legacy flashed a warning; the
     * new stack returns it in the payload the SPA toasts).
     */
    public function resend(Request $request, User $admin): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($admin);

        if ($admin->status !== 'invited') {
            return $this->ok([
                'admin' => $this->adminPayload($admin, $request->user()),
                'changed' => false,
                'emailed' => false,
                'warning' => 'This admin has already accepted their invitation.',
            ], 'This admin has already accepted their invitation.');
        }

        $actor = $request->user();

        DB::transaction(function () use ($admin, $actor) {
            $admin->update([
                'invitation_token' => Str::random(64),
                'invited_at' => now(),
            ]);

            ActivityRecorder::record(
                action: 'admin.invitation_resent',
                description: "Resent the platform admin invitation to {$admin->email}",
                subject: $admin,
                new: ['invited_at' => $admin->invited_at?->toISOString()],
                metadata: ['resent_by' => $actor->id],
                actor: $actor,
            );
        });

        $emailed = $this->queueInvitation($admin, 'admin.invitation.resend_mail_failed');

        return $this->ok([
            'admin' => $this->adminPayload($admin->fresh(), $actor),
            'changed' => true,
            'emailed' => $emailed,
        ], 'Invitation resent.');
    }

    /**
     * Change an admin's platform role. `syncRoles` (not `assignRole`) because
     * a platform admin holds exactly one assignable role.
     */
    public function update(Request $request, User $admin): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($admin);

        if ($admin->id === $request->user()->id) {
            return $this->error('You cannot change your own role.');
        }

        if ($admin->role === User::ROLE_SUPERADMIN) {
            return $this->error('The superadmin role cannot be changed.');
        }

        $data = $request->validate([
            'role' => ['required', 'string', 'max:255'],
        ]);

        $role = $this->platformRole($data['role']);

        if (! $role) {
            return $this->error('Please select a valid admin role.', 422, [
                'role' => ['Please select a valid admin role.'],
            ]);
        }

        $actor = $request->user();
        $previousRoles = $admin->roles->pluck('name')->values()->all();

        DB::transaction(function () use ($admin, $role, $actor, $previousRoles) {
            setPermissionsTeamId(null);
            $admin->syncRoles([$role]);

            ActivityRecorder::record(
                action: 'admin.role_changed',
                description: "Changed {$admin->email}'s platform role to {$role->name}",
                subject: $admin,
                old: ['roles' => $previousRoles],
                new: ['roles' => [$role->name]],
                metadata: ['changed_by' => $actor->id, 'role' => $role->name],
                actor: $actor,
            );
        });

        $name = $this->displayName($admin->fresh()) ?? $admin->email;

        return $this->ok(
            ['admin' => $this->adminPayload($admin->fresh(), $actor)],
            "{$name}'s role updated to {$role->name}.",
        );
    }

    /**
     * Remove a platform admin — legacy's hard delete, with its two guards.
     * Roles and access tokens are detached first: the pivot rows have no FK
     * cascade onto users in this schema and a live Sanctum token would outlive
     * the account otherwise.
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

        $actor = $request->user();
        $email = $admin->email;

        DB::transaction(function () use ($admin, $actor, $email) {
            ActivityRecorder::record(
                action: 'admin.removed',
                description: "Removed platform admin {$email}",
                subject: $admin,
                old: ['email' => $email, 'roles' => $admin->roles->pluck('name')->values()->all()],
                metadata: ['removed_by' => $actor->id],
                actor: $actor,
            );

            setPermissionsTeamId(null);
            $admin->roles()->detach();
            $admin->tokens()->delete();
            $admin->delete();
        });

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
     * Resolve an assignable platform role by name: global, `web` guard and not
     * Super Admin.
     */
    private function platformRole(string $name): ?Role
    {
        return Role::query()
            ->where('name', $name)
            ->whereNull('business_id')
            ->where('guard_name', 'web')
            ->where('name', '!=', self::SUPER_ADMIN_ROLE)
            ->first();
    }

    /**
     * Queue the invitation mail, swallowing (and logging) delivery failures so
     * a broken mailer cannot roll back an account the admin just created.
     */
    private function queueInvitation(User $admin, string $failureLog): bool
    {
        try {
            Mail::to($admin->email)->queue(new AdminInvitationSpaMail($admin));

            return true;
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Directory row / mutation payload. `is_self`, `is_protected` and
     * `can_manage` are computed server-side so the SPA never has to guess why
     * an action is unavailable.
     *
     * @return array<string, mixed>
     */
    private function adminPayload(User $admin, ?User $viewer): array
    {
        $roleNames = $admin->roles->pluck('name')->values()->all();
        $isSuperadmin = $admin->role === User::ROLE_SUPERADMIN;

        return [
            'id' => $admin->id,
            'account_code' => $admin->account_code,
            'name' => $admin->name,
            // Legacy rendered the literal "Pending setup" for an invitee who
            // had not chosen a name yet.
            'display_name' => $this->displayName($admin) ?? 'Pending setup',
            'email' => $admin->email,
            'phone' => $admin->phone,
            'role' => $admin->role,
            'is_superadmin' => $isSuperadmin,
            'status' => $admin->status,
            'is_verified' => (bool) $admin->is_verified,
            'force_password_change' => (bool) $admin->force_password_change,
            'roles' => $roleNames,
            'role_name' => $isSuperadmin ? self::SUPER_ADMIN_ROLE : ($roleNames[0] ?? 'Admin'),
            'invited_at' => $admin->invited_at?->toISOString(),
            'accepted_at' => $admin->accepted_at?->toISOString(),
            'last_login_at' => $admin->last_login_at?->toISOString(),
            'created_at' => $admin->created_at?->toISOString(),
            'is_self' => $viewer !== null && $viewer->id === $admin->id,
            'is_protected' => $isSuperadmin,
            'can_manage' => $viewer !== null && $viewer->id !== $admin->id && ! $isSuperadmin,
        ];
    }

    /**
     * Display name for an account that may still be "Pending setup" (legacy
     * created admins with an empty name and rendered that label literally).
     */
    private function displayName(User $admin): ?string
    {
        $name = trim((string) $admin->name);

        return $name === '' ? null : $name;
    }

    /**
     * The legacy stat pills the list needed but never had: total, pending and
     * active platform accounts.
     *
     * @return array{total: int, pending: int, active: int}
     */
    private function stats(): array
    {
        $counts = User::query()
            ->whereIn('role', self::PLATFORM_ROLES)
            ->selectRaw("COUNT(*) as total, SUM(status = 'invited') as pending, SUM(status = 'active') as active")
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'pending' => (int) ($counts->pending ?? 0),
            'active' => (int) ($counts->active ?? 0),
        ];
    }
}
