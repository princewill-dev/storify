<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
 */
class CategoryParityController extends ApiController
{
    use ResolvesManagementContext;

    /** The per-page sizes the list UI offers (same whitelist as the products list). */
    private const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // The internal `stores.id` — the SPA sends `auth.stores[].id`.
            // Legacy accepted the public `store_id` code behind this same
            // parameter name; the verify pass calls the divergence out, so the
            // internal id is now the documented convention (as products/orders
            // already use).
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ]);

        if (isset($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $categories = Category::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereIn('store_id', $this->storeIds($request))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.trim($term).'%'))
            ->withCount('products')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20);

        return $this->ok(
            $categories->getCollection()->map(fn (Category $category) => $this->row($category))->values()->all(),
            null,
            200,
            $this->paginationMeta($categories),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'store_id' => ['required', 'integer'],
            // Optional because the legacy create form omitted the field its
            // own controller required — every submission from that page
            // bounced with "The status field is required." Active stays the
            // default so the modern modal never dead-ends on a hidden field.
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'parent_id' => ['nullable', 'prohibited'],
        ], [
            'parent_id.prohibited' => 'Category hierarchy is not supported yet.',
        ]);

        if (! $this->storeIds($request)->contains((int) $data['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        $category = DB::transaction(function () use ($request, $user, $data) {
            $category = Category::create([
                'name' => $data['name'],
                'store_id' => $data['store_id'],
                'business_id' => $user->business_id,
                'status' => $data['status'] ?? 'active',
                'slug' => $this->generateSlug((int) $data['store_id'], $data['name']),
            ]);

            $this->log($request, $category, 'category_created', 'Category created', null, [
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status,
                'store_id' => $category->store_id,
            ]);

            return $category;
        });

        return $this->ok(['category' => $this->row($category->loadCount('products'))], 'Category created.', 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeCategory($request, $category);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'parent_id' => ['nullable', 'prohibited'],
        ], [
            'parent_id.prohibited' => 'Category hierarchy is not supported yet.',
        ]);

        $renamed = array_key_exists('name', $data) && $data['name'] !== $category->name;
        $before = ['name' => $category->name, 'slug' => $category->slug, 'status' => $category->status];

        $category = DB::transaction(function () use ($request, $category, $data, $renamed, $before) {
            if ($renamed) {
                // Legacy regenerated the slug whenever the name changed; the
                // base API update never touched it, so a rename left the
                // storefront resolving the category under its stale slug.
                $data['slug'] = $this->generateSlug((int) $category->store_id, $data['name']);
            }

            $category->update($data);

            $this->log($request, $category, 'category_updated', 'Category updated', $before, [
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status,
                'store_id' => $category->store_id,
            ]);

            return $category;
        });

        return $this->ok(['category' => $this->row($category->fresh()->loadCount('products'))], 'Category updated.');
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

        DB::transaction(function () use ($request, $category) {
            $snapshot = [
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status,
                'store_id' => $category->store_id,
            ];

            $category->delete();

            $this->log($request, $category, 'category_deleted', 'Category deleted', $snapshot, null);
        });

        return $this->ok([], 'Category deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            // Parity gap the verify pass named: the legacy table had a slug
            // column the modern list dropped.
            'slug' => $category->slug,
            'store_id' => $category->store_id,
            'status' => $category->status,
            'products_count' => (int) ($category->products_count ?? 0),
            // `parent_id` is deliberately absent from the payload as well as
            // the write rules — the hierarchy is schema-only in every stack
            // until the storefront consumes it (roadmap D9).
        ];
    }

    /**
     * Legacy format: slugified name plus six random characters, regenerated
     * from the *new* name. The uniqueness probe is not legacy, but without it
     * the `(store_id, slug)` index can surface a raw 500 the caller cannot act
     * on; six characters make a retry a formality.
     */
    private function generateSlug(int $storeId, string $name): string
    {
        do {
            $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
        } while (Category::query()->where('store_id', $storeId)->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function log(Request $request, Category $category, string $action, string $description, ?array $old, ?array $new): void
    {
        ActivityLog::create([
            'user_id' => $this->user($request)->id,
            'business_id' => $category->business_id,
            'action' => $action,
            'subject_type' => Category::class,
            'subject_id' => $category->id,
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }

    /**
     * Accessible stores minus soft-deleted ones — legacy filtered
     * `status != 'deleted'` on the category index; accessibleStores() only
     * applies that for the staff/admin paths, so an owner would otherwise see
     * the categories of a store they deleted.
     *
     * The column is qualified because a restricted staff member's branch is the
     * `staff_assignments` pivot join, which also carries an `id` — an
     * unqualified `pluck('id')` is ambiguous there (SQLSTATE 1052).
     *
     * @return Collection<int, int>
     */
    private function storeIds(Request $request): Collection
    {
        return $this->user($request)
            ->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck('stores.id');
    }

    private function authorizeStoreId(Request $request, int $storeId): void
    {
        if (! $this->storeIds($request)->contains($storeId)) {
            abort(403, 'You do not have access to this store.');
        }
    }

    private function authorizeCategory(Request $request, Category $category): void
    {
        $user = $this->user($request);

        if ((int) $category->business_id !== (int) $user->business_id
            || ! $this->storeIds($request)->contains((int) $category->store_id)) {
            abort(403, 'You do not have access to this category.');
        }
    }
}
