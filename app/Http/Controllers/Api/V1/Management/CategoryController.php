<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereIn('store_id', $this->accessibleStoreIds($request))
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->integer('store_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->withCount('products')
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 50));

        return $this->ok(
            $categories->getCollection()->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'store_id' => $category->store_id,
                'parent_id' => $category->parent_id,
                'status' => $category->status,
                'products_count' => $category->products_count ?? null,
            ])->values()->all(),
            null,
            200,
            $this->paginationMeta($categories)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'store_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

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

        return $this->ok(['category' => $category], 'Category created.', 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeCategory($request, $category);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $category->update($data);

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

    private function authorizeCategory(Request $request, Category $category): void
    {
        $user = $this->user($request);

        if ((int) $category->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($category->store_id)->exists()) {
            abort(403, 'You do not have access to this category.');
        }
    }
}
