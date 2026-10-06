<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\AdminBusinessCreated;
use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\OwnershipType;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityRecorder;
use App\Services\KycApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
 */
class BusinessLifecycleController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * Owner account statuses an edit is allowed to reach. `deleted` is
     * excluded on purpose — deletion has guards, an edit does not.
     */
    private const EDITABLE_STATUSES = ['active', 'pending', 'suspended'];

    public function __construct(private readonly KycApprovalService $kycApproval) {}

    /**
     * The directory. Adds the legacy filter set the audit flagged as missing
     * over the previous endpoint: created-date range, the `deleted` option
     * (with deleted rows hidden by default, like the legacy list), the
     * warehouses count, and `q` matching the owner phone the legacy
     * placeholder advertised.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'pending', 'suspended', 'deleted'])],
            'include_deleted' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['name', 'business_code', 'status', 'created_at', 'stores_count', 'warehouses_count', 'users_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Business::query()
            ->with([
                'owner:id,name,email,phone,account_code,status,is_verified',
                'activeSubscription.subscriptionPlan:id,name',
            ])
            // Deleted stores/warehouses are not part of what the business runs
            // today; counting them made the legacy numbers disagree with the
            // store and warehouse screens.
            ->withCount([
                'stores as stores_count' => fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED),
                'warehouses as warehouses_count' => fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
                'users as users_count',
            ]);

        // Legacy default: deleted rows stay out of the directory unless the
        // admin explicitly asks for them.
        $status = $filters['status'] ?? null;

        if ($status !== null) {
            $query->where('status', $status);
        } elseif (! ($filters['include_deleted'] ?? false)) {
            $query->where('status', '!=', 'deleted');
        }

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $term = '%'.trim($filters['q']).'%';

            $query->where(fn ($inner) => $inner->where('name', 'like', $term)
                ->orWhere('business_code', 'like', $term)
                ->orWhereHas('owner', fn ($owner) => $owner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)));
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        // Whitelisted above — never pass a request-supplied column to orderBy.
        $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc');

        $businesses = $query->paginate($filters['per_page'] ?? 20)->withQueryString();

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
     * cached config) can set it.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if (User::query()->where('role', User::ROLE_SUPERADMIN)->exists() && ! $this->multiBusinessSetupAllowed()) {
            return $this->error(
                'Multi-business setup is disabled on this deployment. Set ALLOW_MS_SETUP=1 to provision additional businesses.',
                422,
            );
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['active', 'pending'])],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
        ]);

        $temporaryPassword = Str::password(16, symbols: false);
        $status = $data['status'] ?? 'active';

        /** @var array{0: User, 1: Business} $created */
        $created = DB::transaction(function () use ($request, $data, $temporaryPassword, $status) {
            $owner = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => User::ROLE_BUSINESS_OWNER,
                // Business.status is canonical; the owner is created with the
                // same status deliberately (activating the business is the
                // path back to active for both).
                'status' => $status,
                'password' => $temporaryPassword,
                'force_password_change' => true,
                'is_verified' => false,
            ]);

            $business = Business::create([
                'user_id' => $owner->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'status' => $status,
                'business_type_id' => $data['business_type_id'] ?? null,
                'ownership_type_id' => $data['ownership_type_id'] ?? null,
            ]);

            $owner->forceFill(['business_id' => $business->id])->save();

            ActivityRecorder::record(
                action: 'business_created',
                description: "Business '{$business->name}' provisioned by platform admin",
                subject: $business,
                new: [
                    'name' => $business->name,
                    'business_code' => $business->business_code,
                    'status' => $business->status,
                    'owner_id' => $owner->id,
                    'owner_email' => $owner->email,
                ],
                metadata: ['method' => 'admin_provisioned'],
                actor: $request->user(),
                businessId: $business->id,
            );

            return [$owner, $business];
        });

        [$owner, $business] = $created;

        $this->notifyAdminsOfNewBusiness($owner);

        Log::info('api.admin.business_created', [
            'actor_user_id' => $request->user()?->id,
            'business_id' => $business->id,
            'owner_id' => $owner->id,
        ]);

        // The temporary password is returned once, to the admin who created
        // the account: this stack has no owner-facing invitation accept flow
        // (the management invitation endpoint only accepts role=staff), so the
        // credentials have to travel out-of-band and the owner is forced to
        // change them at first login.
        return $this->ok([
            'business' => $this->payload($business->fresh(), detailed: true),
            'temporary_password' => $temporaryPassword,
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
    public function update(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($business->status === 'deleted') {
            return $this->error('A deleted business cannot be edited.', 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($business->user_id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(self::EDITABLE_STATUSES)],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
        ]);

        $owner = $business->owner;

        if ($owner === null) {
            return $this->error('This business has no owner account to update.', 422);
        }

        $old = [
            'name' => $business->name,
            'status' => $business->status,
            'owner_name' => $owner->name,
            'owner_email' => $owner->email,
            'owner_phone' => $owner->phone,
        ];

        DB::transaction(function () use ($business, $owner, $data, $old, $request) {
            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
                // Slug re-normalised from the name, as legacy did (it normalised
                // on every save, with or without a slug in the payload).
                $attributes['slug'] = $this->uniqueSlug($data['name'], $business->id);
            }

            foreach (['business_type_id', 'ownership_type_id'] as $typeColumn) {
                if (array_key_exists($typeColumn, $data)) {
                    $attributes[$typeColumn] = $data[$typeColumn];
                }
            }

            if (array_key_exists('status', $data)) {
                $attributes['status'] = $data['status'];
            }

            if ($attributes !== []) {
                $business->update($attributes);
            }

            $ownerAttributes = [];

            if (array_key_exists('owner_name', $data)) {
                $ownerAttributes['name'] = $data['owner_name'];
            }

            if (array_key_exists('email', $data)) {
                $ownerAttributes['email'] = $data['email'];
            }

            if (array_key_exists('phone', $data)) {
                $ownerAttributes['phone'] = $data['phone'];
            }

            // Business.status is canonical; the owner follows deliberately.
            if (array_key_exists('status', $data)) {
                $ownerAttributes['status'] = $data['status'];
            }

            if ($ownerAttributes !== []) {
                $owner->update($ownerAttributes);
            }

            // A rejected audit row must take the edit down with it.
            ActivityRecorder::record(
                action: 'business_updated',
                description: "Business '{$business->name}' updated",
                subject: $business,
                old: $old,
                new: [
                    'name' => $business->name,
                    'status' => $business->status,
                    'owner_name' => $owner->name,
                    'owner_email' => $owner->email,
                    'owner_phone' => $owner->phone,
                ],
                actor: $request->user(),
                businessId: $business->id,
            );
        });

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

        if ($this->ownsMainStore($business)) {
            ActivityRecorder::record(
                action: 'business_delete_blocked',
                description: "Deletion of '{$business->name}' blocked: it owns the platform main store",
                subject: $business,
                metadata: ['guard' => 'main_store'],
                actor: $request->user(),
                businessId: $business->id,
            );

            return $this->error('This business owns the main store and cannot be deleted.', 422);
        }

        $storeIds = $business->stores()->where('status', '!=', Store::STATUS_DELETED)->pluck('id')->all();

        if ($storeIds !== []) {
            if (Order::query()->whereIn('store_id', $storeIds)->where('status', '!=', OrderStatus::COMPLETED->value)->exists()) {
                return $this->error("Deletion rejected: {$business->name} has stores with incomplete orders.", 422);
            }

            if (Transaction::query()
                ->whereHas('order', fn ($query) => $query->whereIn('store_id', $storeIds))
                ->where('status', '!=', TransactionStatus::CONFIRMED->value)
                ->exists()) {
                return $this->error("Deletion rejected: {$business->name} has stores with incomplete transactions.", 422);
            }
        }

        $previousStatus = $business->status;

        DB::transaction(function () use ($business, $previousStatus, $request) {
            $business->update(['status' => 'deleted']);
            // Canonical status cascades to the owner account so a deleted
            // business cannot still sign in (legacy wrote only the user half).
            $business->owner?->update(['status' => 'deleted']);

            ActivityRecorder::record(
                action: 'business_deleted',
                description: "Business '{$business->name}' deleted",
                subject: $business,
                old: ['status' => $previousStatus],
                new: ['status' => 'deleted'],
                actor: $request->user(),
                businessId: $business->id,
            );
        });

        return $this->ok([], "Business '{$business->name}' has been deleted.");
    }

    /**
     * Suspend the business and its owner, with the main-store guard and the
     * owner notification legacy sent. Legacy never guarded activate — only
     * suspend and delete — so the asymmetry is kept.
     */
    public function suspend(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if ($business->status === 'deleted') {
            return $this->error('A deleted business cannot be suspended.', 422);
        }

        if ($this->ownsMainStore($business)) {
            return $this->error('This business owns the main store and cannot be suspended.', 422);
        }

        $previousStatus = $business->status;

        DB::transaction(function () use ($business, $data, $previousStatus, $request) {
            $business->update(['status' => 'suspended']);
            $business->owner?->update(['status' => 'suspended']);

            ActivityRecorder::record(
                action: 'business_suspended',
                description: "Business '{$business->name}' suspended",
                subject: $business,
                old: ['status' => $previousStatus],
                new: ['status' => 'suspended'],
                metadata: ['reason' => $data['reason']],
                actor: $request->user(),
                businessId: $business->id,
            );
        });

        $this->notifyOwner(
            $business,
            fn (User $owner) => new BusinessSuspended($owner, $data['reason']),
            'api.admin.business_suspended_mail_failed',
        );

        Log::info('api.admin.business_suspended', ['business_id' => $business->id, 'reason' => $data['reason']]);

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
    public function activate(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if ($business->status === 'deleted') {
            return $this->error('A deleted business cannot be activated.', 422);
        }

        $owner = $business->owner;
        $previousStatus = $business->status;

        $kycApproved = DB::transaction(function () use ($business, $owner, $data, $previousStatus, $request) {
            $business->update(['status' => 'active']);
            $owner?->update(['status' => 'active']);

            ActivityRecorder::record(
                action: 'business_activated',
                description: "Business '{$business->name}' activated",
                subject: $business,
                old: ['status' => $previousStatus],
                new: ['status' => 'active'],
                metadata: ['reason' => $data['reason']],
                actor: $request->user(),
                businessId: $business->id,
            );

            if ($owner === null) {
                return false;
            }

            $application = $this->kycApproval->autoApproveOpenApplication(
                $owner,
                $request->user(),
                'Auto-approved during business activation: '.$data['reason'],
            );

            return $application !== null;
        });

        $this->notifyOwner(
            $business,
            fn (User $owner) => new BusinessReactivated($owner, $data['reason']),
            'api.admin.business_activated_mail_failed',
        );

        Log::info('api.admin.business_activated', ['business_id' => $business->id, 'reason' => $data['reason']]);

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

        $wasVerified = (bool) $owner->is_verified;

        // Tenant-scoped mutation, like the lifecycle transitions above: the
        // owner write and its audit row stand or fall together.
        DB::transaction(function () use ($business, $owner, $wasVerified, $request) {
            $owner->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();

            ActivityRecorder::record(
                action: 'business_owner_verified',
                description: "Owner of '{$business->name}' marked email-verified",
                subject: $business,
                old: ['owner_verified' => $wasVerified],
                new: ['owner_verified' => true],
                metadata: ['owner_user_id' => $owner->id],
                actor: $request->user(),
                businessId: $business->id,
            );
        });

        return $this->ok(['business' => $this->payload($business->fresh(), detailed: true)], 'Owner email marked verified.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Business $business, bool $detailed = false): array
    {
        $business->loadMissing([
            'owner:id,name,email,phone,account_code,status,is_verified,email_verified_at,last_login_at',
            'activeSubscription.subscriptionPlan:id,name',
        ]);

        if ($business->stores_count === null) {
            $business->loadCount([
                'stores as stores_count' => fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED),
                'warehouses as warehouses_count' => fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
                'users as users_count',
            ]);
        }

        $subscription = $business->activeSubscription;
        $owner = $business->owner;

        $data = [
            'id' => $business->id,
            'name' => $business->name,
            'business_code' => $business->business_code,
            'prefix' => $business->prefix,
            'slug' => $business->slug,
            'status' => $business->status,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'account_code' => $owner->account_code,
                'status' => $owner->status,
                'is_verified' => (bool) $owner->is_verified,
            ] : null,
            'plan' => $subscription?->subscriptionPlan?->name,
            'subscription' => [
                'name' => $subscription?->subscriptionPlan?->name,
                'status' => $subscription?->status,
                'ends_at' => $subscription?->expires_at?->toISOString(),
            ],
            'stores_count' => (int) ($business->stores_count ?? 0),
            'warehouses_count' => (int) ($business->warehouses_count ?? 0),
            'users_count' => (int) ($business->users_count ?? 0),
            'created_at' => $business->created_at?->toISOString(),
        ];

        if (! $detailed) {
            return $data;
        }

        $data['description'] = $business->description;
        $data['currency'] = $business->currency;
        // Business has no ownershipType()/businessType() relations (the model
        // is shared and off limits for this workstream), so the curated type
        // names the console shows are resolved from their own tables.
        $data['business_type'] = $business->business_type_id
            ? BusinessType::query()->whereKey($business->business_type_id)->value('name')
            : null;
        $data['ownership_type'] = $business->ownership_type_id
            ? OwnershipType::query()->whereKey($business->ownership_type_id)->value('name')
            : null;
        $data['updated_at'] = $business->updated_at?->toISOString();

        if ($owner !== null) {
            $data['owner']['email_verified_at'] = $owner->email_verified_at?->toISOString();
            $data['owner']['last_login_at'] = $owner->last_login_at?->toISOString();
        }

        $business->loadMissing([
            'stores.ownershipType:id,name',
            'stores.businessType:id,name',
            'warehouses' => fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
        ]);

        $data['stores'] = $business->stores
            ->where('status', '!=', Store::STATUS_DELETED)
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'store_type' => $store->store_type,
                'ownership_type' => $store->ownershipType?->name,
                'business_type' => $store->businessType?->name,
            ])->values()->all();

        $data['warehouses'] = $business->warehouses->map(fn (Warehouse $warehouse) => [
            'id' => $warehouse->id,
            'warehouse_code' => $warehouse->warehouse_code,
            'name' => $warehouse->name,
            'status' => $warehouse->status->value,
            // Legacy's "stock items" column counted stock-location rows; a row
            // holding nothing is not stock, so only positive quantities count.
            'stock_items_count' => (int) $warehouse->stockLocations()->where('quantity', '>', 0)->count(),
        ])->values()->all();

        $data['team'] = $this->teamPayload($business);

        $application = $business->kycApplications()->orderByDesc('id')->first();
        $data['kyc'] = $application ? $this->kycPayload($application) : null;

        return $data;
    }

    /**
     * Every user belonging to the business with the roles the console needs to
     * show. Spatie roles are team-scoped, so the team context has to be the
     * member's business while their roles are read.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamPayload(Business $business): array
    {
        $previousTeam = getPermissionsTeamId();

        try {
            return $business->users()
                ->orderBy('name')
                ->get()
                ->map(function (User $member) {
                    setPermissionsTeamId($member->business_id);

                    return [
                        'id' => $member->id,
                        'account_code' => $member->account_code,
                        'name' => $member->name,
                        'email' => $member->email,
                        'role' => $member->role,
                        'status' => $member->status,
                        'is_verified' => (bool) $member->is_verified,
                        'roles' => $member->getRoleNames()->values()->all(),
                    ];
                })->values()->all();
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function kycPayload(KycApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'legal_name' => $application->legal_name,
            'submitted_at' => $application->submitted_at?->toISOString(),
            'approved_at' => $application->approved_at?->toISOString(),
            'rejected_at' => $application->rejected_at?->toISOString(),
            'review_notes' => $application->review_notes,
            'reviewed_by' => $application->reviewed_by,
            'reviewer' => $application->reviewer?->name,
            'document_type' => $application->documentType?->name,
        ];
    }

    /**
     * The platform main store's owner may not be suspended or deleted
     * (roadmap §3.7). WS-6/WS-8 carry the same rule for the store and the
     * user; it is inlined here rather than shared so concurrent workstreams
     * cannot overwrite one another's copy mid-flight.
     */
    private function ownsMainStore(Business $business): bool
    {
        $mainStoreId = Setting::query()->value('main_store_id');

        if (! $mainStoreId) {
            return false;
        }

        return $business->stores()->whereKey($mainStoreId)->exists();
    }

    /**
     * Legacy read the raw env flag with no config indirection; the config key
     * lets tests and a cached config override it without touching the flag.
     */
    private function multiBusinessSetupAllowed(): bool
    {
        $configured = config('app.allow_ms_setup');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return (int) env('ALLOW_MS_SETUP', 0) === 1;
    }

    /**
     * Legacy normalised the slug from the name on every save
     * (lower-case, spaces to underscores) but let the database's unique index
     * blow up on a collision; a numeric suffix keeps the save succeeding.
     */
    private function uniqueSlug(string $name, ?int $ignoreBusinessId = null): string
    {
        $base = Str::of($name)->trim()->lower()->replace(' ', '_')->replaceMatches('/[^a-z0-9_]+/', '')->value();

        if ($base === '') {
            $base = 'business';
        }

        $slug = $base;
        $suffix = 1;

        while (Business::query()
            ->where('slug', $slug)
            ->when($ignoreBusinessId !== null, fn ($query) => $query->whereKeyNot($ignoreBusinessId))
            ->exists()) {
            $slug = $base.'_'.(++$suffix);
        }

        return $slug;
    }

    /**
     * Legacy queued AdminBusinessCreated to every superadmin, falling back to
     * the configured from-address when none exist.
     */
    private function notifyAdminsOfNewBusiness(User $owner): void
    {
        $recipients = User::query()
            ->where('role', User::ROLE_SUPERADMIN)
            ->pluck('email')
            ->filter()
            ->values();

        if ($recipients->isEmpty() && config('mail.from.address')) {
            $recipients = collect([config('mail.from.address')]);
        }

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Mail::to($recipients->all())->queue(new AdminBusinessCreated($owner));
        } catch (\Throwable $e) {
            Log::error('api.admin.business_created_mail_failed', [
                'owner_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A failed notification must never undo the mutation — the action can be
     * seen in the audit trail and the admin told the truth.
     */
    private function notifyOwner(Business $business, callable $mailable, string $failureLog): void
    {
        $owner = $business->owner;

        if ($owner === null || empty($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->queue($mailable($owner));
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The admin console is a platform surface. Audience + `admin.businesses`
     * alone are not enough: every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, which contains the admin.* names
     * (WS-1 documented the same hole), so a business-scoped account holding a
     * leaked admin-audience token would otherwise read and mutate every tenant.
     */
}
