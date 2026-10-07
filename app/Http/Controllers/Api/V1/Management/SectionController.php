<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\SectionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Section\SectionAvailableProductsRequest;
use App\Http\Requests\Management\Section\SectionIndexRequest;
use App\Http\Requests\Management\Section\SectionPayloadRequest;
use App\Http\Requests\Management\Section\SectionPickerRequest;
use App\Http\Requests\Management\Section\SectionProductIdsRequest;
use App\Http\Requests\Management\Section\SectionProductsRequest;
use App\Http\Resources\Management\Section\SectionDetailResource;
use App\Http\Resources\Management\Section\SectionPickerResource;
use App\Http\Resources\Management\Section\SectionProductResource;
use App\Http\Resources\Management\Section\SectionStatsResource;
use App\Http\Resources\Management\Section\SectionSummaryResource;
use App\Http\Resources\Management\Section\SectionWarehouseResource;
use App\Models\Product;
use App\Models\Section;
use App\Models\Warehouse;
use App\Repositories\Management\Section\SectionRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\Section\SectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-36 — Sections and the product↔section link.
 *
 * Sections are the physical zones inside a warehouse that products are filed
 * into (legacy: `Management\SectionController` nested under
 * `warehouses/{warehouse}/sections`). They carry a `section_code`, a name, an
 * optional description and an active/inactive status, and they live and die
 * with their warehouse.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta), the authorization guards and the 422 refusals stay here;
 * the request rules live in App\Http\Requests\Management\Section, the query
 * building and the access scopes in
 * App\Repositories\Management\Section\SectionRepository, the write workflows
 * with their transaction boundaries and audit rows in
 * App\Services\Management\Section\SectionService, and the payload shapes in
 * App\Http\Resources\Management\Section. The guards stay in the controller
 * body so their 403s keep their place in the refusal order (route-binding 404
 * first), rather than moving into FormRequest::authorize(); extraction moves
 * validation ahead of the controller body on purpose, which is the
 * codebase-wide accepted consequence.
 *
 * Money at this boundary: the private `nairaToKobo()`/`koboToNaira()` pair
 * the controller carried is now `Naira::koboFromLenient()` /
 * `Naira::decimalFromKobo()` inside the resources — the same exact-parsing,
 * sign-aware contracts (see SectionStatsResource).
 *
 * Deliberate changes from legacy, each named in the audit:
 *
 * 1. Deleted sections stay deleted. Legacy soft-deleted (`status = 'deleted'`)
 *    but kept counting and listing them in places — the warehouse card's
 *    section count included deleted rows and the pickers offered them back.
 *    Every read here goes through the repository's warehouse scope, which
 *    excludes them.
 * 2. Mutations are gated by `warehouses create|edit|delete`, not the legacy's
 *    blanket `warehouses view` — a read-only user could delete a section.
 * 3. A section in the URL must actually belong to the warehouse in the URL.
 *    Legacy's nested binding resolved `{section}` on its own and never checked
 *    the pair, so a mismatched pair silently operated on another warehouse's
 *    section.
 * 4. Deleting a section with products is refused with the reason (legacy
 *    flashed the same sentence, but only after the click); the SPA also
 *    pre-empts it from the row's product count.
 *
 * Product assignment follows the legacy rule that a section fills in the
 * product's warehouse when none is set (the `Product::saving()` hook owns
 * that), plus one guard legacy lacked: a product already held in *another*
 * warehouse cannot be silently re-pointed at this one by filing it into a
 * section — its stock locations would be orphaned.
 */
class SectionController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly SectionRepository $repository,
        private readonly SectionService $service,
    ) {}

    public function index(SectionIndexRequest $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $sections = $this->repository->paginateForWarehouse($warehouse, $request->validated());

        return $this->ok(
            [
                'warehouse' => (new SectionWarehouseResource($warehouse))->resolve($request),
                'sections' => SectionSummaryResource::collection($sections->getCollection())->resolve($request),
                // Stats are computed unfiltered so the header keeps describing
                // the warehouse, not the current search.
                'stats' => $this->repository->countsForWarehouse($warehouse),
            ],
            null,
            200,
            $this->paginationMeta($sections),
        );
    }

    public function store(SectionPayloadRequest $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $section = $this->service->create($this->user($request), $warehouse, $request->validated());

        return $this->ok(['section' => $this->detail($request, $section)], 'Section created.', 201);
    }

    public function show(SectionProductsRequest $request, Warehouse $warehouse, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $warehouse, $section);

        $products = $this->repository->paginateProducts($section, $request->validated());

        return $this->ok(
            [
                'section' => $this->detail($request, $section),
                'stats' => (new SectionStatsResource($this->repository->statsForSection($section)))->resolve($request),
                'products' => SectionProductResource::collection($products->getCollection())->resolve($request),
            ],
            null,
            200,
            $this->paginationMeta($products),
        );
    }

    public function update(SectionPayloadRequest $request, Warehouse $warehouse, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $warehouse, $section);

        $this->service->update($this->user($request), $section, $request->validated());

        return $this->ok(['section' => $this->detail($request, $section->fresh())], 'Section updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $warehouse, $section);

        if ($section->products()->exists()) {
            return $this->error('Cannot delete a section with products.', 422);
        }

        $this->service->delete($this->user($request), $warehouse, $section);

        return $this->ok([], 'Section deleted.');
    }

    /**
     * Picker source for the product form and the assign-products modal:
     * every non-deleted section the caller can reach, optionally narrowed to
     * one warehouse.
     */
    public function picker(SectionPickerRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $filters = $request->validated();

        if (isset($filters['warehouse_id']) && ! $this->repository->userCanAccessWarehouse($user, (int) $filters['warehouse_id'])) {
            abort(403, 'You do not have access to this warehouse.');
        }

        return $this->ok([
            'sections' => SectionPickerResource::collection($this->repository->sectionsForPicker($user, $filters))->resolve($request),
        ]);
    }

    /**
     * Products that may be filed into this section: what the warehouse holds
     * (plus store-reachable rows with no warehouse yet, which inherit this
     * one on save), minus what is already here. The current section is echoed
     * on each row so the picker can show that an assignment would move a
     * product out of its present zone.
     */
    public function availableProducts(SectionAvailableProductsRequest $request, Warehouse $warehouse, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $warehouse, $section);

        $products = $this->repository->paginateAvailableProducts($this->user($request), $warehouse, $section, $request->validated());

        return $this->ok(
            [
                'products' => $products->getCollection()
                    ->map(fn (Product $product) => (new SectionProductResource($product, withSection: true))->resolve($request))
                    ->values()
                    ->all(),
            ],
            null,
            200,
            $this->paginationMeta($products),
        );
    }

    public function assignProducts(SectionProductIdsRequest $request, Warehouse $warehouse, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $warehouse, $section);

        $user = $this->user($request);
        $ids = array_values(array_unique(array_map('intval', $request->validated()['product_ids'])));

        $products = $this->repository->productsForAssignment($user, $ids);

        if ($products->count() !== count($ids)) {
            return $this->error('One or more products could not be found.', 422, [
                'product_ids' => ['One or more products could not be found.'],
            ]);
        }

        $result = $this->service->assignProducts($user, $warehouse, $section, $products, $ids);

        if ($result['error'] !== null) {
            return $this->error($result['error'], 422, ['product_ids' => [$result['error']]]);
        }

        $assigned = $result['assigned'];

        return $this->ok(
            ['assigned' => $assigned, 'section' => $this->detail($request, $section->fresh())],
            $assigned === 1 ? '1 product assigned to the section.' : "{$assigned} products assigned to the section.",
        );
    }

    public function unassignProducts(SectionProductIdsRequest $request, Warehouse $warehouse, Section $section): JsonResponse
    {
        $this->authorizeSection($request, $warehouse, $section);

        $ids = array_map('intval', $request->validated()['product_ids']);

        $removed = $this->service->unassignProducts($this->user($request), $section, $ids);

        return $this->ok(
            ['removed' => $removed, 'section' => $this->detail($request, $section->fresh())],
            $removed === 1 ? '1 product removed from the section.' : "{$removed} products removed from the section.",
        );
    }

    /**
     * The section detail payload — repository loads, resource shape.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, Section $section): array
    {
        return (new SectionDetailResource($this->repository->loadForDetail($section)))->resolve($request);
    }

    private function authorizeWarehouse(Request $request, Warehouse $warehouse): void
    {
        if (! $this->repository->userCanAccessWarehouse($this->user($request), (int) $warehouse->id)) {
            abort(403, 'You do not have access to this warehouse.');
        }
    }

    /**
     * Guards the nested pair: the section must belong to the business and to
     * the warehouse named in the URL (legacy never checked the latter), and
     * must not be soft-deleted. The business leg is the shape TenantGuard
     * already encodes; the refusal order (warehouse 403, business 403, pair
     * 404) is unchanged.
     */
    private function authorizeSection(Request $request, Warehouse $warehouse, Section $section): void
    {
        $this->authorizeWarehouse($request, $warehouse);

        app(TenantGuard::class)->authorizeBusiness($section, $this->user($request), 'You do not have access to this section.');

        if ((int) $section->warehouse_id !== (int) $warehouse->id || $section->status === SectionStatus::DELETED) {
            abort(404, 'Section not found in this warehouse.');
        }
    }
}
