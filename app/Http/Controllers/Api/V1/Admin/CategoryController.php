<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminProducts;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Category;
use App\Models\Store;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
 * Kept from legacy:
 * - the slug is `Str::slug(name)` + a 6-character uuid fragment, so category
 *   names may repeat inside a store;
 * - the slug is stable across edits unless the name changes, in which case it
 *   is regenerated (`categoryNameChanged()`);
 * - deleting a category leaves its products in place — the FK nulls their
 *   `category_id` (`nullOnDelete`), and the confirm copy names the count.
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

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->listResponse($request, null);
    }

    /**
     * The store-scoped list (`/admin/stores/{store}/categories`).
     */
    public function storeIndex(Request $request, Store $store): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->listResponse($request, $store);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $store = $this->liveStore((int) $data['store_id']);

        if (! $store) {
            return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
        }

        $category = DB::transaction(function () use ($data, $store) {
            $category = Category::create([
                'store_id' => $store->id,
                'business_id' => $store->business_id,
                'name' => $data['name'],
                'status' => $data['status'],
                'slug' => Str::slug($data['name']).'-'.substr((string) Str::uuid(), 0, 6),
            ]);

            ActivityRecorder::record(
                action: 'category_created',
                description: "Category {$category->name} created for {$store->name}.",
                subject: $category,
                new: ['name' => $category->name, 'store_id' => $category->store_id, 'status' => $category->status],
            );

            return $category;
        });

        $category->load(['store:id,name,store_id', 'parent:id,name']);
        $category->loadCount('products');

        return $this->ok(['category' => $this->categoryPayload($category)], 'Category created.', 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $store = $this->liveStore((int) $data['store_id']);

        if (! $store) {
            return $this->error('Choose a store that still exists.', 422, ['store_id' => ['Choose a store that still exists.']]);
        }

        DB::transaction(function () use ($category, $data, $store) {
            $before = ['name' => $category->name, 'store_id' => $category->store_id, 'status' => $category->status, 'slug' => $category->slug];

            $attributes = [
                'store_id' => $store->id,
                'business_id' => $store->business_id,
                'name' => $data['name'],
                'status' => $data['status'],
            ];

            // Keep the slug stable unless the name changed — legacy's rule.
            if ($category->name !== $data['name']) {
                $attributes['slug'] = Str::slug($data['name']).'-'.substr((string) Str::uuid(), 0, 6);
            }

            $category->update($attributes);

            ActivityRecorder::record(
                action: 'category_updated',
                description: "Category {$category->name} updated.",
                subject: $category,
                old: $before,
                new: ['name' => $category->name, 'store_id' => $category->store_id, 'status' => $category->status, 'slug' => $category->slug],
            );
        });

        $category->load(['store:id,name,store_id', 'parent:id,name']);
        $category->loadCount('products');

        return $this->ok(['category' => $this->categoryPayload($category)], 'Category updated.');
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $productCount = $category->products()->count();

        DB::transaction(function () use ($category) {
            ActivityRecorder::record(
                action: 'category_deleted',
                description: "Category {$category->name} deleted.",
                subject: $category,
                old: ['name' => $category->name, 'store_id' => $category->store_id],
            );

            $category->delete();
        });

        $message = $productCount > 0
            ? "Category deleted. {$productCount} product(s) are now uncategorised."
            : 'Category deleted.';

        return $this->ok(['uncategorised_products' => $productCount], $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function listResponse(Request $request, ?Store $store): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'store_id' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = Category::query()
            ->with(['store:id,name,store_id', 'parent:id,name'])
            ->withCount('products');

        if ($store) {
            $query->where('store_id', $store->id);
        } elseif (($filters['store_id'] ?? null) !== null && $filters['store_id'] !== '') {
            $storeId = $this->resolveStoreId((string) $filters['store_id']);

            if ($storeId === null) {
                // Fail closed: an unknown store lists nothing.
                return $this->ok([], null, 200, [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ]);
            }

            $query->where('store_id', $storeId);
        }

        $query
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            // Legacy ordering: store, then name.
            ->orderBy('store_id')->orderBy('name');

        $categories = $query->paginate($perPage)->withQueryString();

        return $this->ok(
            $categories->getCollection()->map(fn (Category $category) => $this->categoryPayload($category))->values()->all(),
            null,
            200,
            $this->paginationMeta($categories),
        );
    }

    private function liveStore(int $id): ?Store
    {
        return Store::query()->whereKey($id)->where('status', '!=', Store::STATUS_DELETED)->first();
    }

    private function resolveStoreId(string $value): ?int
    {
        $store = Store::query()
            ->where('store_id', $value)
            ->when(ctype_digit($value), fn ($q) => $q->orWhere('id', (int) $value))
            ->first(['id']);

        return $store?->id;
    }
}
