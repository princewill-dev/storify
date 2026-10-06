<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Mail\UserPasswordResetMail;
use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityRecorder;
use App\Services\Auth\ApiTokenService;
use App\Services\KycApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
 */
class UserModerationController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * Roles this console manages. Platform admins are WS-10's surface and are
     * never reachable here (legacy `ensureManaged()` 404s them too).
     */
    private const MANAGED_ROLES = [User::ROLE_BUSINESS_OWNER, 'staff'];

    /**
     * Legacy submitted this hidden when the admin clicked Activate — there was
     * never a reason form. Kept verbatim so `BusinessReactivated` and the KYC
     * reviewer note read the same as they did in the legacy console.
     */
    public const DEFAULT_ACTIVATION_REASON = 'Reactivated by admin';

    /**
     * Columns the directory may be sorted by. Never pass a request-supplied
     * column straight to orderBy (admin roadmap §3.3).
     */
    private const SORTABLE = ['name', 'email', 'role', 'status', 'last_login_at', 'created_at'];

    public function __construct(
        private readonly KycApprovalService $kycApproval,
        private readonly ApiTokenService $tokens,
    ) {}

    /**
     * The platform user directory. Legacy defaulted to owners when no role was
     * chosen; `role=all` (or an empty role) is the explicit "every managed
     * role" option the previous SPA silently used.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in([...self::MANAGED_ROLES, 'all'])],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted', 'pending'])],
            'verified' => ['nullable', Rule::in(['0', '1', 'yes', 'no'])],
            'has_business' => ['nullable', Rule::in(['yes', 'no'])],
            'subscription' => ['nullable', Rule::in(['active', 'trial', 'none'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->whereIn('role', self::MANAGED_ROLES)
            ->with(['business:id,name,business_code,status', 'business.activeSubscription.subscriptionPlan:id,name'])
            ->when($this->roleFilter($filters), fn ($q, $role) => $q->where('role', $role))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(($filters['verified'] ?? null) !== null, fn ($q) => $q->where('is_verified', $this->truthy($filters['verified'])))
            ->when(($filters['has_business'] ?? null) === 'yes', fn ($q) => $q->whereNotNull('business_id'))
            ->when(($filters['has_business'] ?? null) === 'no', fn ($q) => $q->whereNull('business_id'))
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', function ($q) use ($filters) {
                $term = '%'.trim($filters['q']).'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('account_code', 'like', $term));
            });

        $this->applySubscriptionFilter($query, $filters['subscription'] ?? null);

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        $users = $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            $users->getCollection()->map(fn (User $user) => $this->rowPayload($user))->values()->all(),
            null,
            200,
            $this->paginationMeta($users) + ['stats' => $this->stats()],
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

        $user->load([
            'business' => fn ($q) => $q->withCount([
                'stores as stores_count' => fn ($inner) => $inner->where('status', '!=', Store::STATUS_DELETED),
                'warehouses as warehouses_count' => fn ($inner) => $inner->where('status', '!=', Warehouse::STATUS_DELETED),
                'users as team_count',
            ]),
            'business.activeSubscription.subscriptionPlan:id,name',
        ]);

        return $this->ok(['user' => $this->detailPayload($user)]);
    }

    /**
     * Edit name/email/phone. Legacy required name and email — the previous API
     * marked them `sometimes`, so a phone-only payload succeeded silently and
     * wiped nothing (or, worse, an empty body returned "User updated.").
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $old = $user->only(['name', 'email', 'phone']);

        DB::transaction(function () use ($user, $data, $old) {
            $user->update($data);

            ActivityRecorder::record(
                action: 'user_updated',
                description: "Updated user {$user->name}",
                subject: $user,
                old: ['name' => $old['name'], 'email' => $old['email'], 'phone' => $old['phone']],
                new: ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null],
                actor: request()->user(),
            );
        });

        return $this->ok(['user' => $this->rowPayload($user->fresh())], 'User updated.');
    }

    /**
     * Suspend with legacy's required reason, main-store protection,
     * already-suspended warning, notification email and audit row.
     */
    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if ($this->ownsMainStore($user)) {
            ActivityRecorder::record(
                action: 'user_suspend_blocked',
                description: "Suspension of {$user->name} blocked: the user owns the platform main store",
                subject: $user,
                metadata: ['guard' => 'main_store'],
                actor: $request->user(),
            );

            return $this->error('This user owns the main store and cannot be suspended.', 422);
        }

        if ($user->status === 'suspended') {
            return $this->ok(
                ['user' => $this->rowPayload($user), 'changed' => false, 'warning' => 'User is already suspended.'],
                'User is already suspended.',
            );
        }

        $previousStatus = $user->status;

        DB::transaction(function () use ($user, $data, $previousStatus, $request) {
            $user->update(['status' => 'suspended']);

            ActivityRecorder::record(
                action: 'user_suspended',
                description: "Suspended {$user->name}. Reason: {$data['reason']}",
                subject: $user,
                old: ['status' => $previousStatus],
                new: ['status' => 'suspended', 'reason' => $data['reason']],
                actor: $request->user(),
            );
        });

        $notified = $this->notifyUser(
            $user,
            fn (User $recipient) => new BusinessSuspended($recipient, $data['reason']),
            'api.admin.user_suspended_mail_failed',
        );

        Log::info('api.admin.user_suspended', ['user_id' => $user->id, 'reason' => $data['reason']]);

        return $this->ok(
            ['user' => $this->rowPayload($user->fresh()), 'changed' => true, 'notified' => $notified],
            'User suspended.',
        );
    }

    /**
     * Activate a suspended user: legacy default reason (no form), KYC
     * auto-approval through the shared KycApprovalService, reactivation email
     * and audit row. `deleted` users go through restore — activating them here
     * would make one door out of a delete that has guards.
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $reason = $data['reason'] ?? self::DEFAULT_ACTIVATION_REASON;

        if ($user->status === 'deleted') {
            return $this->error('This user is deleted. Restore the account instead.', 422);
        }

        if ($user->status === 'active') {
            return $this->ok(
                ['user' => $this->rowPayload($user), 'changed' => false, 'warning' => 'User is already active.'],
                'User is already active.',
            );
        }

        $previousStatus = $user->status;

        $kycApproved = DB::transaction(function () use ($user, $reason, $previousStatus, $request) {
            $user->update(['status' => 'active']);

            ActivityRecorder::record(
                action: 'user_activated',
                description: "Activated {$user->name}. Reason: {$reason}",
                subject: $user,
                old: ['status' => $previousStatus],
                new: ['status' => 'active', 'reason' => $reason],
                actor: $request->user(),
            );

            // Legacy auto-approved a pending application on activation; the
            // service only touches a `submitted` row and writes the reviewer
            // note (legacy's columns for it were dropped by the model).
            return $this->kycApproval->autoApproveOpenApplication(
                $user,
                $request->user(),
                'Auto-approved during user activation: '.$reason,
            ) !== null;
        });

        $notified = $this->notifyUser(
            $user,
            fn (User $recipient) => new BusinessReactivated($recipient, $reason),
            'api.admin.user_activated_mail_failed',
        );

        Log::info('api.admin.user_activated', ['user_id' => $user->id, 'reason' => $reason]);

        return $this->ok(
            [
                'user' => $this->rowPayload($user->fresh()),
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

        $previouslyVerified = (bool) $user->is_verified;

        DB::transaction(function () use ($user, $request, $previouslyVerified) {
            $user->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();

            ActivityRecorder::record(
                action: 'user_verified',
                description: "Verified {$user->name}",
                subject: $user,
                old: ['is_verified' => $previouslyVerified],
                new: ['is_verified' => true],
                actor: $request->user(),
            );
        });

        return $this->ok(['user' => $this->rowPayload($user->fresh())], 'User verified.');
    }

    public function unverify(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $previouslyVerified = (bool) $user->is_verified;

        DB::transaction(function () use ($user, $request, $previouslyVerified) {
            $user->forceFill(['is_verified' => false, 'email_verified_at' => null])->save();

            ActivityRecorder::record(
                action: 'user_unverified',
                description: "Removed verification for {$user->name}",
                subject: $user,
                old: ['is_verified' => $previouslyVerified],
                new: ['is_verified' => false],
                actor: $request->user(),
            );
        });

        return $this->ok(['user' => $this->rowPayload($user->fresh())], 'User verification removed.');
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

        $temporaryPassword = Str::upper(Str::random(4)).'-'.Str::lower(Str::random(4)).'-'.random_int(1000, 9999);

        $user->forceFill([
            'password' => $temporaryPassword,
            'force_password_change' => true,
        ])->save();

        $notified = $this->notifyUser(
            $user,
            fn (User $recipient) => new UserPasswordResetMail($recipient, $temporaryPassword),
            'api.admin.user_password_reset_mail_failed',
        );

        // The temp password never enters the audit row — ActivityRecorder
        // redacts any `password` key, and the description deliberately omits it.
        ActivityRecorder::record(
            action: 'user_password_reset',
            description: "Reset password for {$user->name}",
            subject: $user,
            metadata: ['emailed' => $notified],
            actor: $request->user(),
        );

        if (! $notified) {
            return $this->ok([
                'user' => $this->rowPayload($user->fresh()),
                'temporary_password' => $temporaryPassword,
                'emailed' => false,
            ], 'Password reset, but the email could not be queued. Hand the temporary password over manually.');
        }

        return $this->ok([
            'user' => $this->rowPayload($user->fresh()),
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

        if ($this->ownsMainStore($user)) {
            ActivityRecorder::record(
                action: 'user_delete_blocked',
                description: "Deletion of {$user->name} blocked: the user owns the platform main store",
                subject: $user,
                metadata: ['guard' => 'main_store'],
                actor: $request->user(),
            );

            return $this->error('This user owns the main store and cannot be deleted.', 422);
        }

        $storeIds = $user->stores()->where('status', '!=', Store::STATUS_DELETED)->pluck('id')->all();

        if ($storeIds !== []) {
            if (Order::query()->whereIn('store_id', $storeIds)->where('status', '!=', OrderStatus::COMPLETED->value)->exists()) {
                return $this->error("Deletion rejected: {$user->name} has stores with incomplete orders.", 422);
            }

            if (Transaction::query()
                ->whereHas('order', fn ($query) => $query->whereIn('store_id', $storeIds))
                ->where('status', '!=', TransactionStatus::CONFIRMED->value)
                ->exists()) {
                return $this->error("Deletion rejected: {$user->name} has stores with incomplete transactions.", 422);
            }
        }

        $previousStatus = $user->status;

        DB::transaction(function () use ($user, $previousStatus, $request) {
            $user->update(['status' => 'deleted']);

            ActivityRecorder::record(
                action: 'user_deleted',
                description: "Deleted user {$user->name}",
                subject: $user,
                old: ['status' => $previousStatus],
                new: ['status' => 'deleted'],
                actor: $request->user(),
            );
        });

        return $this->ok(['user' => $this->rowPayload($user->fresh())], "User '{$user->name}' has been deleted.");
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
                ['user' => $this->rowPayload($user), 'changed' => false, 'warning' => 'This user is not deleted.'],
                'This user is not deleted.',
            );
        }

        DB::transaction(function () use ($user, $request) {
            $user->update(['status' => 'active']);

            ActivityRecorder::record(
                action: 'user_restored',
                description: "Restored user {$user->name}",
                subject: $user,
                old: ['status' => 'deleted'],
                new: ['status' => 'active'],
                actor: $request->user(),
            );
        });

        return $this->ok(['user' => $this->rowPayload($user->fresh())], 'User restored.');
    }

    /**
     * "Login as user" — issues a management-audience token pair for the user.
     *
     * The response is the hand-off contract the admin SPA consumes: the token
     * pair plus `handoff.url`, a fully-formed deep link carrying the pair in
     * the URL fragment for the management app to exchange. `handoff.fragment`
     * is the key the fragment is stored under. The management app consumes the
     * fragment on load (see storify-management/src/components/ImpersonationHandoff.vue).
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

        $impersonation = Impersonation::create([
            'impersonator_id' => $admin->id,
            'impersonated_id' => $user->id,
            'started_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        $pair = $this->tokens->issuePair($user, 'management', $request, [
            'impersonated',
            Impersonation::ABILITY_PREFIX.$impersonation->id,
        ]);

        $impersonation->update([
            'access_token_id' => (string) $user->tokens()->latest('id')->value('id'),
        ]);

        ActivityRecorder::record(
            action: 'user_impersonated',
            description: "{$admin->name} started impersonating {$user->name}",
            subject: $user,
            metadata: ['impersonation_id' => $impersonation->id, 'admin_id' => $admin->id],
            actor: $admin,
        );

        return $this->ok([
            ...$pair,
            'user' => [
                'id' => $user->id,
                'account_code' => $user->account_code,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'business' => $user->business?->name,
            ],
            'impersonation' => [
                'id' => $impersonation->id,
                'impersonator' => ['id' => $admin->id, 'name' => $admin->name],
                'started_at' => $impersonation->started_at?->toISOString(),
            ],
            'handoff' => $this->handoffContract($impersonation, $pair),
        ], 'You are now viewing as '.$user->name.'.');
    }

    /**
     * Force-end an impersonation session started from this console. The
     * management app's own "Return to Admin" control calls the
     * management-audience stop endpoint; this one lets the admin close a
     * session they left open (and writes the same `user_impersonation_stopped`
     * audit row the legacy stop wrote).
     */
    public function stopImpersonation(Request $request, User $user): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->ensureManaged($user);

        $data = $request->validate(['impersonation_id' => ['nullable', 'integer', 'exists:impersonations,id']]);

        $query = Impersonation::query()
            ->where('impersonated_id', $user->id)
            ->whereNull('ended_at');

        if ($data['impersonation_id'] ?? null) {
            $query->whereKey($data['impersonation_id']);
        }

        $impersonation = $query->latest('id')->first();

        if ($impersonation === null) {
            return $this->error('There is no active impersonation session for this user.', 404);
        }

        DB::transaction(function () use ($impersonation, $user, $request) {
            $impersonation->update(['ended_at' => now()]);

            // Revoke the impersonated access token so the handed-off session
            // cannot keep acting after the admin ends it.
            if ($impersonation->access_token_id) {
                $user->tokens()->whereKey($impersonation->access_token_id)->delete();
            }

            ActivityRecorder::record(
                action: 'user_impersonation_stopped',
                description: "Stopped impersonating {$user->name}",
                subject: $user,
                metadata: ['impersonation_id' => $impersonation->id],
                actor: $request->user(),
            );
        });

        return $this->ok([
            'impersonation' => [
                'id' => $impersonation->id,
                'ended_at' => $impersonation->ended_at?->toISOString(),
            ],
        ], 'Impersonation session ended.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'account_code' => $user->account_code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'is_verified' => (bool) $user->is_verified,
            'force_password_change' => (bool) $user->force_password_change,
            'business' => $user->business?->name,
            'business_code' => $user->business?->business_code,
            'business_id' => $user->business_id,
            'plan' => $this->planLabel($user),
            'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(User $user): array
    {
        $business = $user->business;
        $subscription = $business?->activeSubscription;

        return $this->rowPayload($user) + [
            'location' => $user->location,
            'ip_address' => $user->ip_address,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'trial_ends_at' => $user->trial_ends_at?->toISOString(),
            'stores' => $user->accessibleStores()->get(['id', 'name', 'status'])->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'status' => $store->status,
            ])->values()->all(),
            'business_detail' => $business === null ? null : [
                'id' => $business->id,
                'name' => $business->name,
                'business_code' => $business->business_code,
                'status' => $business->status,
                'stores_count' => (int) ($business->stores_count ?? 0),
                'warehouses_count' => (int) ($business->warehouses_count ?? 0),
                'team_count' => (int) ($business->team_count ?? 0),
                'orders_count' => Order::query()->where('business_id', $business->id)->count(),
                'stores' => $business->stores()
                    ->where('status', '!=', Store::STATUS_DELETED)
                    ->orderBy('name')
                    ->get(['id', 'name', 'status'])
                    ->map(fn (Store $store) => [
                        'id' => $store->id,
                        'name' => $store->name,
                        'status' => $store->status,
                    ])->values()->all(),
            ],
            'subscription' => $business === null ? null : [
                'status' => $subscription?->status,
                'plan' => $subscription?->subscriptionPlan?->name,
                'subscription_code' => $subscription?->subscription_code,
                'expires_at' => $subscription?->expires_at?->toISOString(),
                'is_trial' => $user->trial_ends_at !== null && $user->trial_ends_at->isFuture(),
                'trial_ends_at' => $user->trial_ends_at?->toISOString(),
            ],
            'payments' => $business === null ? [] : Payment::query()
                ->where('business_id', $business->id)
                ->latest('id')
                ->limit(10)
                ->get(['id', 'reference', 'amount', 'currency', 'status', 'payment_type', 'paid_at', 'created_at'])
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'payment_type' => $payment->payment_type,
                    'paid_at' => $payment->paid_at?->toISOString(),
                    'created_at' => $payment->created_at?->toISOString(),
                ])->values()->all(),
            'activity' => $this->activityFeed($user),
            'active_impersonation' => $this->activeImpersonation($user),
        ];
    }

    /**
     * Legacy's per-user feed: rows the user acted on plus rows about the user.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityFeed(User $user): array
    {
        return ActivityLog::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere(fn ($inner) => $inner->where('subject_type', User::class)->where('subject_id', $user->id));
            })
            ->with('user:id,name')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'actor' => $log->user?->name,
                'created_at' => $log->created_at?->toISOString(),
            ])->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeImpersonation(User $user): ?array
    {
        $impersonation = Impersonation::query()
            ->where('impersonated_id', $user->id)
            ->whereNull('ended_at')
            ->with('impersonator:id,name')
            ->latest('id')
            ->first();

        if ($impersonation === null) {
            return null;
        }

        return [
            'id' => $impersonation->id,
            'impersonator' => $impersonation->impersonator?->name,
            'started_at' => $impersonation->started_at?->toISOString(),
        ];
    }

    /**
     * The "Trial / plan name / None" column legacy showed.
     */
    private function planLabel(User $user): string
    {
        if ($user->trial_ends_at !== null && $user->trial_ends_at->isFuture()) {
            return 'Trial';
        }

        return $user->business?->activeSubscription?->subscriptionPlan?->name ?? 'None';
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applySubscriptionFilter($query, ?string $subscription): void
    {
        if ($subscription === 'active') {
            $query->whereHas('business', fn ($business) => $business->whereHas('activeSubscription'));
        } elseif ($subscription === 'trial') {
            $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now());
        } elseif ($subscription === 'none') {
            $query->where(fn ($inner) => $inner->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', now()))
                ->whereDoesntHave('business', fn ($business) => $business->whereHas('activeSubscription'));
        }
    }

    /**
     * Legacy defaulted the directory to owners; `all` (or an empty value) is
     * the explicit opt-out the SPA's role select offers.
     *
     * One deliberate widening: when the caller is running a free-text search
     * with no role chosen, every managed role is searched. The command palette
     * and the dashboard tiles deep-link `/users?q=…` with no role, and silently
     * hiding a staff member a support admin just searched for is the kind of
     * trap the audit called out.
     *
     * @param  array<string, mixed>  $filters
     */
    private function roleFilter(array $filters): ?string
    {
        $role = $filters['role'] ?? null;
        $searching = ($filters['q'] ?? null) !== null && $filters['q'] !== '';

        if ($role === null || $role === '') {
            return $searching ? null : User::ROLE_BUSINESS_OWNER;
        }

        return $role === 'all' ? null : $role;
    }

    private function truthy(?string $value): bool
    {
        return in_array($value, ['1', 'yes'], true);
    }

    /**
     * @return array{owners: int, staff: int, suspended: int, unverified: int}
     */
    private function stats(): array
    {
        return [
            'owners' => User::query()->where('role', User::ROLE_BUSINESS_OWNER)->where('status', '!=', 'deleted')->count(),
            'staff' => User::query()->where('role', 'staff')->where('status', '!=', 'deleted')->count(),
            'suspended' => User::query()->whereIn('role', self::MANAGED_ROLES)->where('status', 'suspended')->count(),
            'unverified' => User::query()->whereIn('role', self::MANAGED_ROLES)->where('is_verified', false)->where('status', '!=', 'deleted')->count(),
        ];
    }

    /**
     * The platform main store's owner may not be suspended or deleted
     * (roadmap §3.7). Kept inline so concurrent workstreams each carry their
     * own copy rather than racing on a shared helper.
     */
    private function ownsMainStore(User $user): bool
    {
        $mainStoreId = Setting::query()->value('main_store_id');

        if (! $mainStoreId) {
            return false;
        }

        return $user->stores()->whereKey($mainStoreId)->exists();
    }

    /**
     * A failed notification must never undo the mutation — the action is in
     * the audit trail and the admin is told the truth about the mail.
     */
    private function notifyUser(User $user, callable $mailable, string $failureLog): bool
    {
        if (empty($user->email)) {
            return false;
        }

        try {
            Mail::to($user->email)->queue($mailable($user));

            return true;
        } catch (\Throwable $e) {
            Log::error($failureLog, ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The deep link contract the admin SPA opens: the pair travels in the URL
     * fragment (never sent to a server, never written to access logs) and the
     * management app exchanges it for its own session. The SPA composes the
     * full URL — the API has no configured management-app origin — and passes
     * `fragment` verbatim as the fragment key.
     *
     * @param  array<string, mixed>  $pair
     * @return array<string, mixed>
     */
    private function handoffContract(Impersonation $impersonation, array $pair): array
    {
        $payload = [
            'access_token' => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'impersonation_id' => $impersonation->id,
            'expires_at' => now()->addSeconds((int) $pair['expires_in'])->toISOString(),
        ];

        return [
            'fragment' => 'impersonation',
            'payload' => $payload,
            'encoded' => rtrim(strtr(base64_encode(json_encode($payload) ?: '{}'), '+/', '-_'), '='),
        ];
    }

    private function ensureManaged(User $user): void
    {
        abort_unless(in_array($user->role, self::MANAGED_ROLES, true), 404);
    }
}
