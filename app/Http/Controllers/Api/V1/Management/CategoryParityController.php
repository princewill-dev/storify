<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\CategoryIndexRequest;
use App\Http\Requests\Management\StoreCategoryRequest;
use App\Http\Requests\Management\UpdateCategoryRequest;
use App\Http\Resources\Management\CategoryResource;
use App\Models\Category;
use App\Repositories\Management\CategoryRepository;
use App\Services\Management\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-31 — categories polish & audit logging.
 *
 * Lives beside CategoryController (which it replaces on the shared routes)
 * because the base controller's payload shape is consumed by other
 * workstreams (the products list filter and the product form picker) and must
 * not change underneath them. This one adds what the legacy screens carried
 * and the base slice dropped:
 *
 * - the rename behaviour legacy had and the base update silently lost: the
 *   storefront-facing slug is regenerated whenever the name changes, so the
 *   storefront does not keep resolving a renamed category under its old slug;
 * - an ActivityLog trail on create/update/delete (legacy wrote `Log::info`
 *   only, so the admin audit screen never saw category writes — services did;
 *   this brings categories up to the service convention with
 *   `category_created` / `category_updated` / `category_deleted`);
 * - the deleted-store filter legacy applied (`status != 'deleted'`) which the
 *   base slice dropped, so a deleted store's categories cannot leak back into
 *   the list or its filter;
 * - a real hierarchy guard: `parent_id` was accepted and returned with no
 *   ownership or cycle validation while no UI ever set it (roadmap D9), so a
 *   non-null `parent_id` is now refused with a clear 422 until the storefront
 *   renders child categories.
 *
 * Deliberately not carried over from the legacy edit screen: store
 * reassignment. `products.category_id` is not store-scoped, so moving a
 * category to another store would strand its products in the old store under
 * a category the new store owns — legacy allowed that blindly. The base API
 * already dropped the field; the SPA shows the owning store read-only.
 *
 * The HTTP shape (status codes, refusal messages, envelope, pagination meta)
 * stays here; the list query and the shared store-id scope live in
 * App\Repositories\Management\CategoryRepository, the write workflows with
 * their transaction boundaries and audit rows in
 * App\Services\Management\CategoryService, the payloads in
 * App\Http\Resources\Management\CategoryResource and the request rules beside
 * them in App\Http\Requests\Management.
 */
class CategoryParityController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly CategoryRepository $repository,
        private readonly CategoryService $service,
    ) {}

    public function index(CategoryIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        if (isset($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $categories = $this->repository->paginateForUser($this->user($request), $filters);

        return $this->ok(
            CategoryResource::collection($categories->getCollection())->resolve($request),
            null,
            200,
            $this->paginationMeta($categories),
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        if (! $this->repository->storeIds($user)->contains((int) $data['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        $category = $this->service->create($request, $user, $data);

        return $this->ok(
            ['category' => (new CategoryResource($category->loadCount('products')))->resolve($request)],
            'Category created.',
            201,
        );
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorizeCategory($request, $category);

        $category = $this->service->update($request, $this->user($request), $category, $request->validated());

        return $this->ok(
            ['category' => (new CategoryResource($category->fresh()->loadCount('products')))->resolve($request)],
            'Category updated.',
        );
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeCategory($request, $category);

        if ($category->products()->exists()) {
            // Stricter than legacy by design: `products.category_id` is
            // nullOnDelete, so the old hard delete silently stripped products
            // of their category. The SPA reads the row's products_count and
            // swaps the confirm dialog for this message pre-emptively.
            return $this->error('Move or delete the products in this category first.', 409);
        }

        $this->service->delete($request, $this->user($request), $category);

        return $this->ok([], 'Category deleted.');
    }

    private function authorizeStoreId(Request $request, int $storeId): void
    {
        if (! $this->repository->storeIds($this->user($request))->contains($storeId)) {
            abort(403, 'You do not have access to this store.');
        }
    }

    private function authorizeCategory(Request $request, Category $category): void
    {
        $user = $this->user($request);

        // The store set is the repository's deleted-store-filtered one, not
        // TenantGuard::authorizeStoreId(): that reads accessibleStores()
        // directly, which admits a deleted store for an owner — the WS-31
        // deleted-store refusals (asserted) depend on the filtered scope.
        if ((int) $category->business_id !== (int) $user->business_id
            || ! $this->repository->storeIds($user)->contains((int) $category->store_id)) {
            abort(403, 'You do not have access to this category.');
        }
    }
}
