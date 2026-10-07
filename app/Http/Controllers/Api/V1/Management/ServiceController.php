<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Service\ServiceIndexRequest;
use App\Http\Requests\Management\Service\StoreServiceRequest;
use App\Http\Requests\Management\Service\UpdateServiceRequest;
use App\Http\Resources\Management\Service\ServiceDetailResource;
use App\Http\Resources\Management\Service\ServiceResource;
use App\Http\Resources\Management\Service\StoreOptionResource;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Models\Store;
use App\Repositories\Management\Service\ServiceRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\Service\ServiceCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WS-30 — Services catalogue.
 *
 * The storefront has been publicly serving services
 * (`Api\V1\Storefront\CatalogController@services`) while the business had no
 * way to create or edit one. This is that missing management half: CRUD over
 * the legacy `services` table, scoped to the caller's accessible stores.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta) and the post-commit audit calls stay here; the payload
 * rules live in the Management\Service FormRequests, the list query in
 * App\Repositories\Management\Service\ServiceRepository, the write workflows
 * and their transaction boundaries in
 * App\Services\Management\Service\ServiceCatalogueService, and the response
 * shapes in App\Http\Resources\Management\Service\ (ServiceResource list rows,
 * ServiceDetailResource for a single service, StoreOptionResource for the
 * store filter).
 *
 * Deliberate departures from the legacy `Management\ServiceController`:
 * - routes bind by `service_code` (legacy parity — the model already declares
 *   it as the route key);
 * - `update()` validates the store, the status enum and any `primary_image_id`
 *   against the service itself, all of which legacy skipped;
 * - the "no accessible stores" branch returns an empty page plus an empty
 *   `stores` list instead of legacy's 500 (it dereferenced an undefined
 *   `$breadcrumbs` on that path);
 * - `amount` is the legacy decimal-naira column (like products), so it is
 *   returned as-is and the SPA formats it from the currency relation rather
 *   than the hardcoded ₦ the legacy views printed.
 *
 * Provenance kept with the code it explains:
 * - the create/update store check stays a 422 "Invalid store selection." — a
 *   foreign or deleted store id must not read as "exists but forbidden"
 *   (deliberate anti-id-probing), so it lives in the controller body and the
 *   FormRequests keep `store_id` a plain integer rule;
 * - the service guard stays in the controller body so its 403 keeps its place
 *   in the refusal order (route-binding 404 first), rather than moving into
 *   FormRequest::authorize();
 * - the FormRequest extraction moves validation ahead of the controller body,
 *   so a caller who is both unauthorised and malformed now answers 422 where
 *   it answered 403 — the known, accepted consequence of the extraction
 *   across this codebase. A valid payload from an unauthorised caller still
 *   gets 403, so nothing is escalated, and this is deliberately not worked
 *   around.
 */
class ServiceController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly ServiceRepository $repository,
        private readonly ServiceCatalogueService $catalogue,
    ) {}

    public function index(ServiceIndexRequest $request): JsonResponse
    {
        $services = $this->repository->paginateForStores($this->accessibleStoreIds($request), $request->validated());

        return $this->ok(
            [
                'services' => ServiceResource::collection($services->getCollection())->resolve(),
                // Legacy's "no stores" banner never rendered because its branch
                // 500'd; the SPA uses this list for both the store filter and
                // the create-a-store empty state.
                'stores' => StoreOptionResource::collection($this->accessibleStores($request))->resolve(),
            ],
            null,
            200,
            $this->paginationMeta($services)
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        // 422 by design, not 403 — see the class docblock.
        if (! $this->accessibleStoreIds($request)->contains((int) $data['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        $service = $this->catalogue->create($request, $user, $data);

        $this->logActivity($request, 'service_created', 'Service created', $service);

        return $this->ok(['service' => $this->detail($service->fresh())], 'Service created.', 201);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $this->authorizeService($request, $service);

        return $this->ok(['service' => $this->detail($service)]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $this->authorizeService($request, $service);

        $data = $request->validated();

        // 422 by design, not 403 — see the class docblock.
        if (! $this->accessibleStoreIds($request)->contains((int) $data['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        // The image must already belong to this service. Legacy validated only
        // `exists:service_images,id`, which let a caller point at another
        // service's picture (a silent no-op against a scoped update).
        $primaryId = $data['primary_image_id'] ?? null;

        if ($primaryId !== null && ! $service->images()->whereKey($primaryId)->exists()) {
            return $this->error('The selected primary image does not belong to this service.', 422);
        }

        $this->catalogue->update($request, $service, $data);

        $this->logActivity($request, 'service_updated', 'Service updated', $service);

        return $this->ok(['service' => $this->detail($service->fresh())], 'Service updated.');
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->authorizeService($request, $service);

        $this->catalogue->delete($service);

        // Legacy removed services without a trace; the audit trail now records
        // the deletion too (catalog-org feature 16).
        $this->logActivity($request, 'service_deleted', 'Service deleted', $service);

        return $this->ok([], 'Service deleted.');
    }

    /**
     * Stores the caller may put a service in: accessible and not soft-deleted,
     * mirroring the legacy `status != deleted` filter (and the WS-06 fix that
     * stopped deleted records leaking back into lists).
     *
     * Deliberately shadows ResolvesManagementContext::accessibleStoreIds(),
     * whose plain User::accessibleStoreIds() includes deleted stores: both the
     * list scope and the per-service 403 below depend on the exclusion.
     *
     * @return Collection<int, int>
     */
    private function accessibleStoreIds(Request $request)
    {
        // accessibleStores() below runs ->get(), so this is a Collection of
        // Store models — the key is plain `id`. Qualifying it as `stores.id`
        // (correct against a query builder) matches nothing here.
        return $this->accessibleStores($request)->pluck('id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Store>
     */
    private function accessibleStores(Request $request)
    {
        return $this->user($request)
            ->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    private function authorizeService(Request $request, Service $service): void
    {
        // Rows written before the multi-tenant migration carry a null
        // business_id; the store check below is the decisive scope for both
        // cases.
        app(TenantGuard::class)->authorizeNullableBusiness(
            $service,
            $this->user($request),
            'You do not have access to this service.',
        );

        // The store half stays inline rather than using
        // TenantGuard::authorizeStoreId(): that checks the relation without
        // the deleted-store exclusion, which is load-bearing here (a service
        // in a deleted store is not reachable).
        if (! $this->accessibleStoreIds($request)->contains((int) $service->store_id)) {
            abort(403, 'You do not have access to this service.');
        }
    }

    /**
     * The single-service payload adapter: the eager loads the old private
     * `detail()` shaper applied, then the resource that now carries the shape.
     *
     * @return array<string, mixed>
     */
    private function detail(Service $service): array
    {
        $service->loadMissing(['store', 'images', 'currency']);

        return ServiceDetailResource::make($service)->resolve();
    }

    private function logActivity(Request $request, string $action, string $description, Service $service): void
    {
        $user = $this->user($request);

        ActivityLog::create([
            'user_id' => $user->id,
            'business_id' => $service->business_id ?? $user->business_id,
            'action' => $action,
            'subject_type' => Service::class,
            'subject_id' => $service->id,
            'description' => $description,
            'metadata' => [
                'user_id' => $user->id,
                'service_id' => $service->id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }
}
