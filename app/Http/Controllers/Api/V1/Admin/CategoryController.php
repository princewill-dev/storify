<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminProducts;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListCategoriesRequest;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Store;
use App\Repositories\Admin\CategoryRepository;
use App\Services\Admin\CategoryService;
use Illuminate\Http\JsonResponse;

/**
 * WS-15 (admin console) — the platform category directory.
 *
 * Rebuilds `/office/categories` and `/office/stores/{store}/categories`: the
 * same list across every store (store then name, 20/page), the store-scoped
 * variant, plus create / edit / delete. Legacy rendered the create form as a
 * modal on the list and a standalone page for the store-scoped flow; the SPA
 * covers both with the modal (`/categories/create` deep-links open it with
 * the store preselected).
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListCategoriesRequest`, `StoreCategoryRequest`, `UpdateCategoryRequest`),
 * the directory queries in `CategoryRepository`, the write workflows with
 * their transaction boundaries and audit rows in `CategoryService` — where the
 * legacy slug rules and their provenance moved. Response shaping stays in the
 * shared admin catalogue serializer,
 * {@see SerializesAdminProducts::categoryPayload()}, which also shapes the
 * product payloads; pulling one method out of it would fork that serializer.
 *
 * Fixed rather than cloned: legacy let a category be attached to a store it
 * did not belong to (no store validation on edit), and left `business_id`
 * null because the column did not exist yet — both are set correctly here, so
 * every category is tenant-scoped like the products that reference it.
 */
class CategoryController extends ApiController
{
    use EnsuresPlatformAdmin;
    use SerializesAdminProducts;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly CategoryService $catalogue,
    ) {}

    public function index(ListCategoriesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->listResponse($request, null);
    }

    /**
     * The store-scoped list (`/admin/stores/{store}/categories`).
     */
    public function storeIndex(ListCategoriesRequest $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->listResponse($request, $store);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        $store = $this->categories->liveStore((int) $data['store_id']);

        if (! $store) {
            return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
        }

        $category = $this->catalogue->create($data, $store);

        $this->categories->loadForPayload($category);

        return $this->ok(['category' => $this->categoryPayload($category)], 'Category created.', 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        $store = $this->categories->liveStore((int) $data['store_id']);

        if (! $store) {
            return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
        }

        $this->catalogue->update($category, $data, $store);

        $this->categories->loadForPayload($category);

        return $this->ok(['category' => $this->categoryPayload($category)], 'Category updated.');
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $productCount = $this->catalogue->delete($category);

        $message = $productCount > 0
            ? "Category deleted. {$productCount} product(s) are now uncategorised."
            : 'Category deleted.';

        return $this->ok(['uncategorised_products' => $productCount], $message);
    }

    /**
     * The list body shared by both directory endpoints.
     */
    private function listResponse(ListCategoriesRequest $request, ?Store $store): JsonResponse
    {
        $filters = $request->validated();

        $perPage = (int) ($filters['per_page'] ?? 20);

        $storeId = $store?->id;

        // The legacy URL-only `store_id` scope matches the numeric id or the
        // public `st_…` id.
        if ($storeId === null && ($filters['store_id'] ?? null) !== null && $filters['store_id'] !== '') {
            $storeId = $this->categories->resolveStoreId((string) $filters['store_id']);

            if ($storeId === null) {
                // Fail closed: an unknown store lists nothing.
                return $this->ok([], null, 200, [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ]);
            }
        }

        $categories = $this->categories->paginateDirectory($filters, $storeId, $perPage);

        return $this->ok(
            $categories->getCollection()->map(fn (Category $category) => $this->categoryPayload($category))->values()->all(),
            null,
            200,
            $this->paginationMeta($categories),
        );
    }
}
