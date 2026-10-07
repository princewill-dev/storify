<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Category\StoreCategoryRequest;
use App\Http\Requests\Management\Category\UpdateCategoryRequest;
use App\Http\Resources\Management\Category\CategoryResource;
use App\Models\Category;
use App\Repositories\Management\Category\CategoryRepository;
use App\Services\Access\TenantGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The base management categories API — list, create, update, delete.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta) stays here; the create/update payload rules live in the
 * Management\Category FormRequests, the list query in
 * App\Repositories\Management\Category\CategoryRepository, and the list row
 * shape in App\Http\Resources\Management\Category\CategoryResource. No
 * service: every write is a single-row create, update or delete — no
 * transaction, ledger or notification — so one would be indirection without
 * benefit.
 *
 * WS-31 lays a richer superset over these URIs through CategoryParityController
 * (the two contracts differ on `parent_id`, the per_page default and
 * deleted-store exclusion), so the base slice's own layers above are kept
 * separate from that work rather than converged into it.
 *
 * Provenance kept with the code it explains:
 *  - the create store check stays a 422 "Invalid store selection." — a foreign
 *    or deleted store id must not read as "exists but forbidden" (deliberate
 *    anti-id-probing);
 *  - the update/destroy guard stays in the controller body so its 403 keeps
 *    its place in the refusal order (route-binding 404 first), rather than
 *    moving into FormRequest::authorize();
 *  - the index deliberately has no FormRequest: this slice never validated its
 *    list filters, and rules would turn inputs it used to answer (per_page=500
 *    reaching the paginator) into 422s.
 */
class CategoryController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request, CategoryRepository $repository): JsonResponse
    {
        // The same filled()/integer() reading the inline query used, so a
        // non-numeric store_id or a blank q keeps the meaning it had.
        $filters = [
            'store_id' => $request->filled('store_id') ? $request->integer('store_id') : null,
            'q' => $request->filled('q') ? (string) $request->string('q') : null,
            'per_page' => $request->integer('per_page', 50),
        ];

        $categories = $repository->paginateForUser($this->user($request), $filters);

        return $this->ok(
            CategoryResource::collection($categories->getCollection())->resolve(),
            null,
            200,
            $this->paginationMeta($categories)
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        // 422 by design, not 403 — see the class docblock.
        if (! in_array((int) $data['store_id'], $this->accessibleStoreIds($request)->all(), true)) {
            return $this->error('Invalid store selection.', 422);
        }

        $category = Category::create([
            'name' => $data['name'],
            'store_id' => $data['store_id'],
            'business_id' => $this->user($request)->business_id,
            'parent_id' => $data['parent_id'] ?? null,
            'status' => $data['status'] ?? 'active',
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
        ]);

        // The created model is echoed as-is: its serialization, attribute
        // order included, is the payload exact-JSON consumers assert.
        return $this->ok(['category' => $category], 'Category created.', 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorizeCategory($request, $category);

        $category->update($request->validated());

        return $this->ok(['category' => $category->fresh()], 'Category updated.');
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeCategory($request, $category);

        if ($category->products()->exists()) {
            return $this->error('Move or delete the products in this category first.', 409);
        }

        $category->delete();

        return $this->ok([], 'Category deleted.');
    }

    /**
     * The business + reachable-store shape TenantGuard already encodes, with
     * the same check order and the same 403 message the private method it
     * replaces carried.
     */
    private function authorizeCategory(Request $request, Category $category): void
    {
        app(TenantGuard::class)->authorizeBusinessAndStore(
            $category,
            $this->user($request),
            (int) $category->store_id,
            'You do not have access to this category.',
        );
    }
}
