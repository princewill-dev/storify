<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\AdminStoreCreated;
use App\Mail\StoreActivated;
use App\Mail\StoreReactivated;
use App\Mail\StoreSuspended;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Order;
use App\Models\OwnershipType;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WS-6 (admin console) — store moderation & lifecycle.
 *
 * The previous admin API exposed a read-only store directory and detail that
 * were thinner than legacy (no owner/type/logo/main-store columns, no product/
 * category/pack panels) and had **no way to change a store's status at all** —
 * the platform office could not take down an abusive store from the new UI.
 * This controller carries the whole lifecycle.
 *
 * The shared `routes/api/v1/admin.php` is owned by the orchestrator and must
 * not be edited by a workstream, so the module route file re-registers the two
 * existing `stores` URIs on top of this controller — the route collection keys
 * on method+URI, so the later registration replaces the earlier one and
 * `route:list` keeps exactly one entry per URI. The old, read-only
 * `Api\V1\Admin\StoreController` is left untouched on disk for the
 * orchestrator to retire; every field it returned is preserved here.
 *
 * Legacy defects deliberately not cloned:
 *
 * - **Create/edit dropped the business link.** Legacy `unset()` the chosen
 *   `business_id` before saving while still resolving `user_id` from it, so an
 *   admin-created store ended up with `business_id = null` and never appeared
 *   under its business. Here the business link is written, and the owner is
 *   the chosen business's owner (legacy could also force-override the owner to
 *   the superadmin's own business owner when ALLOW_MS_SETUP was off).
 * - **Edit could set `deleted`.** Legacy's edit form offered a `deleted`
 *   status (validated as a free string), bypassing the delete guards. The edit
 *   endpoint accepts `active|inactive|suspended` only; `deleted` is reachable
 *   exclusively through `destroy()`, which runs the guards.
 * - **The "Deleted" list filter could never return rows** because the base
 *   query excluded deleted stores before applying it. Passing `status=deleted`
 *   now actually lists soft-deleted stores (deleted rows stay hidden otherwise,
 *   so the directory still reads as legacy's default).
 * - **The legacy detail tiles were hard-coded zeros** ("Total amount earned",
 *   "Customers", "Sales"). They are computed here from confirmed transactions,
 *   distinct customers on orders, and completed orders.
 * - **Edit-to-inactive/suspended emailed the owner without a reason.** The
 *   notification now carries the status-change explanation.
 */
class StoreModerationController extends ApiController
{
    /**
     * Statuses the create form is allowed to reach. Legacy's create modal
     * offered active/inactive only; `pending`/`suspended`/`deleted` are
     * lifecycle transitions, not creation states.
     */
    private const CREATE_STATUSES = ['active', 'inactive'];

    /**
     * Statuses an edit is allowed to reach. `deleted` is excluded on purpose —
     * deletion has guards, an edit does not (see the class docblock). `pending`
     * stays selectable so a pending store round-trips through the form
     * unchanged.
     */
    private const EDITABLE_STATUSES = ['active', 'inactive', 'suspended', 'pending'];

    /**
     * The platform main store id, resolved once per request so a list of fifty
     * stores does not re-query the settings row for every row's "Main" badge.
     */
    private ?int $mainStoreId = null;

    private bool $mainStoreResolved = false;

    /**
     * The directory. Adds the legacy filter set the audit flagged as missing
     * over the previous endpoint: created-date range, the working `deleted`
     * option, `q` over store id and owner (legacy's placeholder promised those
     * and only name worked), plus the enriched scanning columns (logo, owner,
     * business code, business type, main badge, shop link).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended', 'pending', 'deleted'])],
            'include_deleted' => ['nullable', 'boolean'],
            'is_main' => ['nullable', 'boolean'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['name', 'store_id', 'status', 'balance', 'created_at', 'products_count', 'orders_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Store::query()
            ->with([
                'user:id,name,email,phone',
                'business:id,name,business_code,user_id',
                'ownershipType:id,name',
                'businessType:id,name',
            ])
            ->withCount(['products', 'orders']);

        // Legacy default: deleted rows stay out of the directory. The legacy
        // filter option for them was dead because the exclusion ran first;
        // asking for the status explicitly now returns them.
        $status = $filters['status'] ?? null;

        if ($status !== null) {
            $query->where('status', $status);
        } elseif (! ($filters['include_deleted'] ?? false)) {
            $query->where('status', '!=', Store::STATUS_DELETED);
        }

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $term = '%'.trim($filters['q']).'%';

            $query->where(fn ($inner) => $inner
                ->where('name', 'like', $term)
                ->orWhere('store_id', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhereHas('user', fn ($owner) => $owner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term))
                ->orWhereHas('business', fn ($business) => $business
                    ->where('name', 'like', $term)
                    ->orWhere('business_code', 'like', $term)));
        }

        if (($filters['ownership_type_id'] ?? null) !== null) {
            $query->where('ownership_type_id', $filters['ownership_type_id']);
        }

        if (($filters['business_type_id'] ?? null) !== null) {
            $query->where('business_type_id', $filters['business_type_id']);
        }

        if (($filters['is_main'] ?? false) === true) {
            $mainStoreId = $this->mainStoreId();

            $query->when($mainStoreId !== null, fn ($inner) => $inner->whereKey($mainStoreId))
                ->when($mainStoreId === null, fn ($inner) => $inner->whereRaw('1 = 0'));
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        // Whitelisted above — never pass a request-supplied column to orderBy.
        $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc');

        $stores = $query->paginate($filters['per_page'] ?? 20)->withQueryString();

        return $this->ok(
            $stores->getCollection()->map(fn (Store $store) => $this->listPayload($store))->values()->all(),
            null,
            200,
            $this->paginationMeta($stores),
        );
    }

    /**
     * The store console: everything the legacy detail page answered — the
     * Store Info card (description, support contacts, address, socials,
     * ownership/business type, logo), the Business & Owner card, computed
     * metric tiles, and the Products / Categories / Packs panels.
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        return $this->ok(['store' => $this->detailPayload($store)]);
    }

    /**
     * Dropdown data for the create/edit modal: businesses (with their owner),
     * ownership/business types, the status lists the form may offer, and the
     * main-store/`ALLOW_MS_SETUP` state so the SPA can disable "Add Store"
     * with an honest reason instead of failing on submit (legacy flashed the
     * refusal only after the form was filled in).
     */
    public function formOptions(Request $request): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        $mainStoreId = $this->mainStoreId();
        $multiBusinessAllowed = $this->multiBusinessSetupAllowed();

        return $this->ok([
            'businesses' => Business::query()
                ->with('owner:id,name,email')
                ->orderBy('name')
                ->get()
                ->map(fn (Business $business) => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'business_code' => $business->business_code,
                    'owner' => $business->owner?->name,
                    'owner_email' => $business->owner?->email,
                ])->values()->all(),
            'ownership_types' => OwnershipType::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (OwnershipType $type) => ['id' => $type->id, 'name' => $type->name])->values()->all(),
            'business_types' => BusinessType::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (BusinessType $type) => ['id' => $type->id, 'name' => $type->name])->values()->all(),
            'statuses' => [
                'create' => self::CREATE_STATUSES,
                'edit' => self::EDITABLE_STATUSES,
            ],
            'main_store_id' => $mainStoreId,
            'multi_business_setup_allowed' => $multiBusinessAllowed,
            'can_create' => ! ($mainStoreId !== null && ! $multiBusinessAllowed),
        ]);
    }

    /**
     * Admin-provisioned store, with logo upload, slug normalisation, the
     * main-store bootstrap and both queued notifications legacy sent.
     *
     * The `ALLOW_MS_SETUP` guard is legacy's multi-business control: once a
     * main store exists on a single-business deployment, more stores are
     * refused. The flag is read through config so tests and a cached config
     * can set it without editing the environment.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        if ($this->mainStoreId() !== null && ! $this->multiBusinessSetupAllowed()) {
            return $this->error('Multi-business controls are disabled.', 422);
        }

        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'status' => ['required', Rule::in(self::CREATE_STATUSES)],
        ]);

        $logoPath = null;

        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('stores/logos', 'public');
        }

        $business = Business::query()->findOrFail($data['business_id']);

        $bootstrappedMainStore = false;

        $store = DB::transaction(function () use ($request, $data, $business, $logoPath, &$bootstrappedMainStore) {
            $store = Store::create([
                'business_id' => $business->id,
                'user_id' => $business->user_id,
                'name' => $data['name'],
                'slug' => $this->uniqueStoreSlug($data['slug'] ?? null, $data['name']),
                'description' => $data['description'] ?? null,
                'logo_path' => $logoPath,
                'support_email' => $data['support_email'] ?? null,
                'support_phone' => $data['support_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'instagram_url' => $data['instagram_url'] ?? null,
                'facebook_url' => $data['facebook_url'] ?? null,
                'twitter_url' => $data['twitter_url'] ?? null,
                'tiktok_url' => $data['tiktok_url'] ?? null,
                'ownership_type_id' => $data['ownership_type_id'] ?? null,
                'business_type_id' => $data['business_type_id'] ?? null,
                'status' => $data['status'],
            ]);

            ActivityRecorder::record(
                action: 'store_created',
                description: "Store '{$store->name}' created for '{$business->name}'",
                subject: $store,
                new: [
                    'name' => $store->name,
                    'store_id' => $store->store_id,
                    'slug' => $store->slug,
                    'status' => $store->status,
                    'business_id' => $store->business_id,
                ],
                metadata: ['method' => 'admin_provisioned'],
                actor: $request->user(),
                businessId: $store->business_id,
            );

            // Legacy's bootstrap: the first store a superadmin creates becomes
            // the homepage store (only when none is configured yet).
            if ($request->user()?->role === User::ROLE_SUPERADMIN && $this->mainStoreId() === null) {
                $settings = Setting::query()->first() ?? new Setting;
                $settings->main_store_id = $store->id;
                $settings->save();

                // The settings row changed inside this request; drop the
                // per-request memo so is_main is right in the response.
                $this->mainStoreResolved = false;
                $bootstrappedMainStore = true;

                ActivityRecorder::record(
                    action: 'main_store_configured',
                    description: "Store '{$store->name}' configured as the homepage store",
                    subject: $store,
                    new: ['main_store_id' => $store->id],
                    actor: $request->user(),
                    businessId: $store->business_id,
                );
            }

            return $store;
        });

        if ($bootstrappedMainStore) {
            $this->forgetMainStoreCaches();
        }

        $this->notifyAdminsOfNewStore($store);
        $this->notifyOwner(
            $store,
            fn (User $owner) => new StoreActivated($store),
            'api.admin.store_created_mail_failed',
        );

        Log::info('api.admin.store_created', [
            'actor_user_id' => $request->user()?->id,
            'store_id' => $store->id,
            'public_id' => $store->store_id,
        ]);

        return $this->ok(['store' => $this->detailPayload($store->fresh())], 'Store created.', 201);
    }

    /**
     * The 16-field edit. Slug uniqueness retries, logo replacement deletes the
     * previous file, status is restricted to the editable set, and the main
     * (homepage) store keeps legacy's guard: it cannot be moved to
     * inactive/suspended — the other edits still apply and the caller is told
     * the status change was skipped.
     */
    public function update(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('A deleted store cannot be edited.', 422);
        }

        $data = $request->validate([
            'business_id' => ['sometimes', 'required', 'integer', 'exists:businesses,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'status' => ['sometimes', 'required', Rule::in(self::EDITABLE_STATUSES)],
        ]);

        $previousStatus = $store->status;
        $blockedMainStoreStatus = false;

        $old = [
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
        ];

        DB::transaction(function () use ($request, $store, $data, $old, &$blockedMainStoreStatus) {
            $attributes = [];

            foreach ([
                'description', 'support_email', 'support_phone', 'address',
                'instagram_url', 'facebook_url', 'twitter_url', 'tiktok_url',
                'ownership_type_id', 'business_type_id',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }

            if (array_key_exists('slug', $data) || array_key_exists('name', $data)) {
                $attributes['slug'] = $this->uniqueStoreSlug(
                    $data['slug'] ?? null,
                    $data['name'] ?? $store->name,
                    $store->id,
                );
            }

            // Re-resolve the owner from the chosen business. Legacy unset the
            // business_id and therefore never relinked it; the link is written
            // here so the store keeps showing under its business.
            if (array_key_exists('business_id', $data)) {
                $business = Business::query()->find($data['business_id']);

                if ($business !== null) {
                    $attributes['business_id'] = $business->id;
                    $attributes['user_id'] = $business->user_id;
                }
            }

            if (array_key_exists('status', $data)) {
                if ($this->isMainStore($store) && in_array($data['status'], ['inactive', 'suspended'], true)) {
                    // Legacy blocked the change silently and flashed a warning;
                    // the warning is now structured in the response.
                    $blockedMainStoreStatus = true;

                    Log::warning('api.admin.store_status_change_blocked_main_store', [
                        'actor_user_id' => $request->user()?->id,
                        'store_id' => $store->id,
                        'attempted_status' => $data['status'],
                        'main_store_id' => $this->mainStoreId(),
                    ]);
                } else {
                    $attributes['status'] = $data['status'];
                }
            }

            if ($request->hasFile('logo')) {
                if ($store->logo_path) {
                    try {
                        Storage::disk('public')->delete($store->logo_path);
                    } catch (\Throwable $e) {
                        Log::warning('api.admin.store_logo_delete_failed', [
                            'store_id' => $store->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $attributes['logo_path'] = $request->file('logo')->store('stores/logos', 'public');
            }

            if ($attributes !== []) {
                $store->update($attributes);
            }

            ActivityRecorder::record(
                action: 'store_updated',
                description: "Store '{$store->name}' updated",
                subject: $store,
                old: $old,
                new: [
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'status' => $store->status,
                    'support_email' => $store->support_email,
                    'support_phone' => $store->support_phone,
                ],
                metadata: $blockedMainStoreStatus
                    ? ['blocked_status_change' => 'main_store']
                    : [],
                actor: $request->user(),
                businessId: $store->business_id,
            );
        });

        // A status change into a suspended-like state notifies the owner;
        // legacy sent this mail without a reason, so the owner could not tell
        // why. The generated reason fixes that.
        if ($previousStatus !== $store->status && in_array($store->status, ['inactive', 'suspended'], true)) {
            $reason = "Your store was set to {$store->status} by the platform team.";

            $this->notifyOwner(
                $store,
                fn (User $owner) => new StoreSuspended($store, $reason),
                'api.admin.store_status_mail_failed',
            );
        }

        Log::info('api.admin.store_updated', ['actor_user_id' => $request->user()?->id, 'store_id' => $store->id]);

        return $this->ok(
            [
                'store' => $this->detailPayload($store->fresh()),
                'warnings' => $blockedMainStoreStatus
                    ? ['This store is the homepage store and cannot be set to inactive or suspended. Other details were updated.']
                    : [],
            ],
            $blockedMainStoreStatus
                ? 'Store updated. The homepage store cannot be set to inactive or suspended, so its status was left unchanged.'
                : 'Store updated.',
        );
    }

    /**
     * Suspend with the mandatory reason legacy required (max 2000 chars) and
     * the owner email legacy queued. The main store cannot be suspended.
     */
    public function suspend(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('A deleted store cannot be suspended.', 422);
        }

        if ($this->isMainStore($store)) {
            ActivityRecorder::record(
                action: 'store_suspend_blocked',
                description: "Suspension of '{$store->name}' blocked: it is the homepage store",
                subject: $store,
                metadata: ['guard' => 'main_store', 'reason' => $data['reason']],
                actor: $request->user(),
                businessId: $store->business_id,
            );

            return $this->error('This is the main store and cannot be suspended.', 422);
        }

        $previousStatus = $store->status;

        DB::transaction(function () use ($request, $store, $data, $previousStatus) {
            $store->update(['status' => Store::STATUS_SUSPENDED]);

            ActivityRecorder::record(
                action: 'store_suspended',
                description: "Store '{$store->name}' suspended",
                subject: $store,
                old: ['status' => $previousStatus],
                new: ['status' => Store::STATUS_SUSPENDED],
                metadata: ['reason' => $data['reason']],
                actor: $request->user(),
                businessId: $store->business_id,
            );
        });

        $this->notifyOwner(
            $store,
            fn (User $owner) => new StoreSuspended($store, $data['reason']),
            'api.admin.store_suspended_mail_failed',
        );

        Log::info('api.admin.store_suspended', [
            'actor_user_id' => $request->user()?->id,
            'store_id' => $store->id,
            'reason' => $data['reason'],
        ]);

        return $this->ok(['store' => $this->detailPayload($store->fresh())], 'Store suspended.');
    }

    /**
     * Activate, with the same mandatory-reason contract. Legacy guarded exact
     * suspend, not activate — so activating the main store stays allowed.
     */
    public function activate(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('A deleted store cannot be activated.', 422);
        }

        $previousStatus = $store->status;

        DB::transaction(function () use ($request, $store, $data, $previousStatus) {
            $store->update(['status' => Store::STATUS_ACTIVE]);

            ActivityRecorder::record(
                action: 'store_activated',
                description: "Store '{$store->name}' activated",
                subject: $store,
                old: ['status' => $previousStatus],
                new: ['status' => Store::STATUS_ACTIVE],
                metadata: ['reason' => $data['reason']],
                actor: $request->user(),
                businessId: $store->business_id,
            );
        });

        $this->notifyOwner(
            $store,
            fn (User $owner) => new StoreReactivated($store, $data['reason']),
            'api.admin.store_activated_mail_failed',
        );

        Log::info('api.admin.store_activated', [
            'actor_user_id' => $request->user()?->id,
            'store_id' => $store->id,
            'reason' => $data['reason'],
        ]);

        return $this->ok(['store' => $this->detailPayload($store->fresh())], 'Store activated.');
    }

    /**
     * Soft delete with legacy's three guards, in order: the homepage store is
     * refused, then any order for the store that is not `completed`, then any
     * transaction whose order belongs to the store that is not `confirmed`.
     * The row survives as `status = 'deleted'` so the audit trail and
     * historical orders keep resolving.
     */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('This store has already been deleted.', 422);
        }

        if ($this->isMainStore($store)) {
            ActivityRecorder::record(
                action: 'store_delete_blocked',
                description: "Deletion of '{$store->name}' blocked: it is the homepage store",
                subject: $store,
                metadata: ['guard' => 'main_store'],
                actor: $request->user(),
                businessId: $store->business_id,
            );

            return $this->error('This is the main store and cannot be deleted.', 422);
        }

        if (Order::query()
            ->where('store_id', $store->id)
            ->where('status', '!=', OrderStatus::COMPLETED->value)
            ->exists()) {
            ActivityRecorder::record(
                action: 'store_delete_blocked',
                description: "Deletion of '{$store->name}' blocked: incomplete orders",
                subject: $store,
                metadata: ['guard' => 'incomplete_orders'],
                actor: $request->user(),
                businessId: $store->business_id,
            );

            return $this->error("Deletion rejected: {$store->name} has an incomplete order.", 422);
        }

        if (Transaction::query()
            ->whereHas('order', fn ($query) => $query->where('store_id', $store->id))
            ->where('status', '!=', TransactionStatus::CONFIRMED->value)
            ->exists()) {
            ActivityRecorder::record(
                action: 'store_delete_blocked',
                description: "Deletion of '{$store->name}' blocked: incomplete transactions",
                subject: $store,
                metadata: ['guard' => 'incomplete_transactions'],
                actor: $request->user(),
                businessId: $store->business_id,
            );

            return $this->error("Deletion rejected: {$store->name} has an incomplete transaction.", 422);
        }

        $previousStatus = $store->status;

        DB::transaction(function () use ($request, $store, $previousStatus) {
            $store->update(['status' => Store::STATUS_DELETED]);

            ActivityRecorder::record(
                action: 'store_deleted',
                description: "Store '{$store->name}' deleted",
                subject: $store,
                old: ['status' => $previousStatus],
                new: ['status' => Store::STATUS_DELETED],
                actor: $request->user(),
                businessId: $store->business_id,
            );
        });

        Log::info('api.admin.store_deleted', ['actor_user_id' => $request->user()?->id, 'store_id' => $store->id]);

        return $this->ok([], "Store '{$store->name}' has been deleted successfully.");
    }

    /**
     * The directory row: legacy's scanning columns plus the enrichment the
     * audit asked for. Every key the previous read-only endpoint returned is
     * preserved so existing consumers keep working.
     *
     * @return array<string, mixed>
     */
    private function listPayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'pos_enabled' => (bool) $store->pos_enabled,
            'balance' => (int) $store->balance,
            'business' => $store->business?->name,
            'business_id' => $store->business_id,
            'business_code' => $store->business?->business_code,
            'owner' => $store->user ? [
                'id' => $store->user->id,
                'name' => $store->user->name,
                'email' => $store->user->email,
                'phone' => $store->user->phone,
            ] : null,
            'logo_path' => $store->logo_path,
            'logo_url' => $store->logoUrl(),
            'ownership_type' => $store->ownershipType?->name,
            'business_type' => $store->businessType?->name,
            'ownership_type_id' => $store->ownership_type_id,
            'business_type_id' => $store->business_type_id,
            'is_main' => $this->isMainStore($store),
            'shop_url' => $store->has_website && $store->slug ? store_url($store->slug) : null,
            'products_count' => $store->products_count ?? null,
            'orders_count' => $store->orders_count ?? null,
            'created_at' => $store->created_at?->toISOString(),
        ];
    }

    /**
     * The detail console payload: store info, business/owner block, computed
     * tiles, and the Products / Categories / Packs panels the legacy show page
     * rendered.
     *
     * @return array<string, mixed>
     */
    private function detailPayload(Store $store): array
    {
        $store->loadMissing([
            'user:id,name,email,phone',
            'business.owner:id,name,email,phone',
            'ownershipType:id,name',
            'businessType:id,name',
        ]);

        if ($store->products_count === null || $store->orders_count === null) {
            $store->loadCount(['products', 'orders']);
        }

        $owner = $store->business?->owner ?? $store->user;

        $categories = $store->categories()->orderBy('name')->get(['id', 'name', 'status']);
        $recentProducts = Product::query()
            ->where('store_id', $store->id)
            ->latest()
            ->take(10)
            ->get(['id', 'product_code', 'name', 'amount', 'status']);

        // Legacy hard-coded these tiles to zero; they are computed here from
        // confirmed money, distinct ordering customers, and completed orders.
        $totalEarned = Transaction::query()
            ->whereHas('order', fn ($query) => $query->where('store_id', $store->id))
            ->where('status', TransactionStatus::CONFIRMED->value)
            ->sum('amount');

        $customersCount = Order::query()
            ->where('store_id', $store->id)
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id');

        $salesCount = Order::query()
            ->where('store_id', $store->id)
            ->where('status', OrderStatus::COMPLETED->value)
            ->count();

        return [
            ...$this->listPayload($store),
            'description' => $store->description,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
            'address' => $store->address,
            'socials' => [
                'instagram' => $store->instagram_url,
                'facebook' => $store->facebook_url,
                'twitter' => $store->twitter_url,
                'tiktok' => $store->tiktok_url,
            ],
            'payment_mode' => $store->payment_mode,
            'views' => (int) $store->views,
            'business_block' => $store->business ? [
                'id' => $store->business->id,
                'name' => $store->business->name,
                'business_code' => $store->business->business_code,
            ] : null,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
            ] : null,
            'stats' => [
                'total_earned' => round((float) $totalEarned, 2),
                'customers_count' => $customersCount,
                'products_count' => (int) ($store->products_count ?? 0),
                'sales_count' => $salesCount,
            ],
            'categories' => $categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'status' => $category->status,
            ])->values()->all(),
            'categories_count' => $categories->count(),
            'recent_products' => $recentProducts->map(fn (Product $product) => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'amount' => $product->amount !== null ? (float) $product->amount : null,
                'status' => $product->status,
            ])->values()->all(),
            'packs' => $this->packPayload($store),
            // Store has no packs() relation; the panel queries the table the
            // way the legacy controller did.
            'packs_count' => Pack::query()->where('store_id', $store->id)->count(),
            'updated_at' => $store->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packPayload(Store $store): array
    {
        return Pack::query()
            ->where('store_id', $store->id)
            ->latest()
            ->take(10)
            ->get(['id', 'pack_code', 'name', 'amount', 'status'])
            ->map(fn ($pack) => [
                'id' => $pack->id,
                'pack_code' => $pack->pack_code,
                'name' => $pack->name,
                'amount' => $pack->amount !== null ? (float) $pack->amount : null,
                'status' => $pack->status,
            ])->values()->all();
    }

    /**
     * Normalise and uniquify a slug. Legacy lower-cased and turned spaces into
     * underscores; `Str::slug(..., '_')` keeps that shape while stripping
     * punctuation legacy left in (an apostrophe in a name produced a slug the
     * storefront could never route). Uniqueness retries legacy only ran on
     * update; both paths need it, or a name collision hit the unique index.
     */
    private function uniqueStoreSlug(?string $slug, string $name, ?int $ignoreStoreId = null): string
    {
        $base = Str::slug($slug !== null && trim($slug) !== '' ? $slug : $name, '_');

        if ($base === '') {
            $base = 'store';
        }

        $candidate = $base;
        $suffix = 1;

        while (
            Store::query()
                ->where('slug', $candidate)
                ->when($ignoreStoreId !== null, fn ($query) => $query->whereKeyNot($ignoreStoreId))
                ->exists()
        ) {
            $candidate = $base.'_'.(++$suffix);
        }

        return $candidate;
    }

    /**
     * The homepage store, resolved from the platform setting WS2 exposes.
     */
    private function isMainStore(Store $store): bool
    {
        $mainStoreId = $this->mainStoreId();

        return $mainStoreId !== null && (int) $store->id === $mainStoreId;
    }

    private function mainStoreId(): ?int
    {
        if (! $this->mainStoreResolved) {
            $value = Setting::query()->value('main_store_id');

            $this->mainStoreId = $value !== null ? (int) $value : null;
            $this->mainStoreResolved = true;
        }

        return $this->mainStoreId;
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
     * The homepage store is cached at four keys by the home API and the admin
     * view composers; the bootstrap write must bust them or the storefront
     * keeps serving the old homepage.
     */
    private function forgetMainStoreCaches(): void
    {
        Cache::forget('admin_main_store');
        Cache::forget('home_main_store');
        Cache::forget('home_api_company');
        Cache::forget('company_settings');
    }

    /**
     * Legacy queued AdminStoreCreated to every superadmin, falling back to the
     * configured from-address when none exist.
     */
    private function notifyAdminsOfNewStore(Store $store): void
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
            Mail::to($recipients->all())->queue(new AdminStoreCreated($store));
        } catch (\Throwable $e) {
            Log::error('api.admin.store_created_admin_mail_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A failed notification must never undo the mutation — the action can be
     * seen in the audit trail and the admin told the truth.
     */
    private function notifyOwner(Store $store, callable $mailable, string $failureLog): void
    {
        $owner = $store->user;

        if ($owner === null || empty($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->queue($mailable($owner));
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'store_id' => $store->id,
                'user_id' => $store->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The admin console is a platform surface. Audience + `admin.stores`
     * alone are not enough: every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, which contains the admin.* names
     * (WS-1/WS-4 documented the same hole), so a business-scoped account
     * holding a leaked admin-audience token would otherwise read and mutate
     * every tenant's stores.
     */
    private function authorizePlatformAccess(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->isAdmin(),
            403,
            'This endpoint is restricted to platform administrators.',
        );
    }
}
