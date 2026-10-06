<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\CreateStoreRequest;
use App\Http\Requests\Admin\ListStoresRequest;
use App\Http\Requests\Admin\StoreReasonRequest;
use App\Http\Requests\Admin\UpdateStoreRequest;
use App\Http\Resources\Admin\StoreDetailResource;
use App\Http\Resources\Admin\StoreFormOptionsResource;
use App\Http\Resources\Admin\StoreResource;
use App\Models\Store;
use App\Repositories\Admin\StoreModerationRepository;
use App\Services\Admin\StoreLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListStoresRequest`, `CreateStoreRequest`, `UpdateStoreRequest`,
 * `StoreReasonRequest`), queries/persists in `StoreModerationRepository`, the
 * multi-table workflows and their transaction boundaries in
 * `StoreLifecycleService`, and response shaping in `StoreResource` /
 * `StoreDetailResource` / `StoreFormOptionsResource`; the platform-admin guard
 * deliberately stays here so its order is unchanged.
 */
class StoreModerationController extends ApiController
{
    /**
     * The admin console is a platform surface. Audience + `admin.stores`
     * alone are not enough: every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, which contains the admin.* names
     * (WS-1/WS-4 documented the same hole), so a business-scoped account
     * holding a leaked admin-audience token would otherwise read and mutate
     * every tenant's stores.
     */
    use EnsuresPlatformAdmin;

    /**
     * The platform main store id, resolved once per request so a list of fifty
     * stores does not re-query the settings row for every row's "Main" badge.
     */
    private ?int $mainStoreId = null;

    private bool $mainStoreResolved = false;

    public function __construct(
        private readonly StoreModerationRepository $stores,
        private readonly StoreLifecycleService $lifecycle,
    ) {}

    /**
     * The directory. Adds the legacy filter set the audit flagged as missing
     * over the previous endpoint: created-date range, the working `deleted`
     * option, `q` over store id and owner (legacy's placeholder promised those
     * and only name worked), plus the enriched scanning columns (logo, owner,
     * business code, business type, main badge, shop link).
     */
    public function index(ListStoresRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $stores = $this->stores->paginateForDirectory($request->validated());

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
        $this->authorizePlatformAdmin();

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
        $this->authorizePlatformAdmin();

        $mainStoreId = $this->mainStoreId();
        $multiBusinessAllowed = $this->multiBusinessSetupAllowed();

        return $this->ok(
            (new StoreFormOptionsResource($this->stores->formOptions(), $mainStoreId, $multiBusinessAllowed))->resolve(),
        );
    }

    /**
     * Admin-provisioned store, with logo upload, slug normalisation, the
     * main-store bootstrap and both queued notifications legacy sent.
     *
     * The `ALLOW_MS_SETUP` guard is legacy's multi-business control: once a
     * main store exists on a single-business deployment, more stores are
     * refused. The flag is read through config so tests and a cached config
     * can set it without editing the environment; the write workflow and its
     * transaction live in the lifecycle service.
     */
    public function store(CreateStoreRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($this->mainStoreId() !== null && ! $this->multiBusinessSetupAllowed()) {
            return $this->error('Multi-business controls are disabled.', 422);
        }

        $created = $this->lifecycle->provision(
            $request->validated(),
            $request->hasFile('logo') ? $request->file('logo') : null,
            $request->user(),
        );

        if ($created['bootstrapped_main_store']) {
            // The settings row changed inside this request; drop the
            // per-request memo so is_main is right in the response.
            $this->mainStoreResolved = false;
        }

        return $this->ok(['store' => $this->detailPayload($created['store']->fresh())], 'Store created.', 201);
    }

    /**
     * The 16-field edit. Slug uniqueness retries, logo replacement deletes the
     * previous file, status is restricted to the editable set, and the main
     * (homepage) store keeps legacy's guard: it cannot be moved to
     * inactive/suspended — the other edits still apply and the caller is told
     * the status change was skipped.
     */
    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('A deleted store cannot be edited.', 422);
        }

        $blockedMainStoreStatus = $this->lifecycle->update(
            $store,
            $request->validated(),
            $request->hasFile('logo') ? $request->file('logo') : null,
            $request->user(),
        );

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
     * the owner email legacy queued. The main store cannot be suspended; the
     * refusal is audited before the 422 is returned.
     */
    public function suspend(StoreReasonRequest $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('A deleted store cannot be suspended.', 422);
        }

        if ($this->isMainStore($store)) {
            $this->lifecycle->recordSuspendBlockedByMainStore($store, $data['reason'], $request->user());

            return $this->error('This is the main store and cannot be suspended.', 422);
        }

        $this->lifecycle->suspend($store, $data['reason'], $request->user());

        return $this->ok(['store' => $this->detailPayload($store->fresh())], 'Store suspended.');
    }

    /**
     * Activate, with the same mandatory-reason contract. Legacy guarded exact
     * suspend, not activate — so activating the main store stays allowed (only
     * the deleted status is refused).
     */
    public function activate(StoreReasonRequest $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('A deleted store cannot be activated.', 422);
        }

        $this->lifecycle->activate($store, $data['reason'], $request->user());

        return $this->ok(['store' => $this->detailPayload($store->fresh())], 'Store activated.');
    }

    /**
     * Soft delete with legacy's three guards, in order: the homepage store is
     * refused, then any order for the store that is not `completed`, then any
     * transaction whose order belongs to the store that is not `confirmed`.
     * The row survives as `status = 'deleted'` so the audit trail and
     * historical orders keep resolving; each refusal is audited.
     */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($store->status === Store::STATUS_DELETED) {
            return $this->error('This store has already been deleted.', 422);
        }

        if ($this->isMainStore($store)) {
            $this->lifecycle->recordDeleteBlockedByMainStore($store, $request->user());

            return $this->error('This is the main store and cannot be deleted.', 422);
        }

        if ($this->stores->hasIncompleteOrders($store)) {
            $this->lifecycle->recordDeleteBlockedByIncompleteOrders($store, $request->user());

            return $this->error("Deletion rejected: {$store->name} has an incomplete order.", 422);
        }

        if ($this->stores->hasIncompleteTransactions($store)) {
            $this->lifecycle->recordDeleteBlockedByIncompleteTransactions($store, $request->user());

            return $this->error("Deletion rejected: {$store->name} has an incomplete transaction.", 422);
        }

        $this->lifecycle->delete($store, $request->user());

        return $this->ok([], "Store '{$store->name}' has been deleted successfully.");
    }

    /**
     * The directory row, shaped by StoreResource. Kept as a thin private seam
     * so the response sites read as they did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function listPayload(Store $store): array
    {
        return StoreResource::make($store, $this->mainStoreId())->resolve();
    }

    /**
     * The detail console payload, shaped by StoreDetailResource from the
     * relations and blocks StoreModerationRepository reads. Kept as a thin
     * private seam so the response sites read as they did before the
     * extraction.
     *
     * @return array<string, mixed>
     */
    private function detailPayload(Store $store): array
    {
        $this->stores->loadForDetail($store);

        return StoreDetailResource::make($store, $this->mainStoreId(), $this->stores->detailBlocks($store))->resolve();
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
            $this->mainStoreId = $this->stores->mainStoreId();
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
}
