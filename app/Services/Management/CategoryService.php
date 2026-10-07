<?php

namespace App\Services\Management;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WS-31 — the category write workflows.
 *
 * Create, rename and delete each pair the `categories` write with their
 * `activity_logs` audit row inside one transaction, keeping the exact order
 * the controller used. The controller keeps the HTTP shape and the
 * authorization guards, including the products-in-category 409, which stays
 * in front of the transaction there.
 *
 * Kept from the pre-layering controller, moved with the code it explains:
 *
 * - the slug is the legacy format — slugified name plus six random
 *   characters — regenerated from the *new* name whenever the name changes,
 *   so the storefront does not keep resolving a renamed category under its
 *   old slug. The uniqueness probe is not legacy, but without it the
 *   `(store_id, slug)` index can surface a raw 500 the caller cannot act on;
 *   six characters make a retry a formality;
 * - the audit rows use the `category_created` / `category_updated` /
 *   `category_deleted` action names the admin audit screen reads, with the
 *   same old/new snapshots, subject columns and request metadata the
 *   controller wrote directly.
 */
final class CategoryService
{
    /**
     * @param  array<string, mixed>  $data  validated by StoreCategoryRequest
     */
    public function create(Request $request, User $user, array $data): Category
    {
        return DB::transaction(function () use ($request, $user, $data) {
            $category = Category::create([
                'name' => $data['name'],
                'store_id' => $data['store_id'],
                'business_id' => $user->business_id,
                'status' => $data['status'] ?? 'active',
                'slug' => $this->generateSlug((int) $data['store_id'], $data['name']),
            ]);

            $this->log($request, $user, $category, 'category_created', 'Category created', null, [
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status,
                'store_id' => $category->store_id,
            ]);

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated by UpdateCategoryRequest
     */
    public function update(Request $request, User $user, Category $category, array $data): Category
    {
        $renamed = array_key_exists('name', $data) && $data['name'] !== $category->name;
        $before = ['name' => $category->name, 'slug' => $category->slug, 'status' => $category->status];

        return DB::transaction(function () use ($request, $user, $category, $data, $renamed, $before) {
            if ($renamed) {
                // Legacy regenerated the slug whenever the name changed; the
                // base API update never touched it, so a rename left the
                // storefront resolving the category under its stale slug.
                $data['slug'] = $this->generateSlug((int) $category->store_id, $data['name']);
            }

            $category->update($data);

            $this->log($request, $user, $category, 'category_updated', 'Category updated', $before, [
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status,
                'store_id' => $category->store_id,
            ]);

            return $category;
        });
    }

    public function delete(Request $request, User $user, Category $category): void
    {
        DB::transaction(function () use ($request, $user, $category) {
            $snapshot = [
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status,
                'store_id' => $category->store_id,
            ];

            $category->delete();

            $this->log($request, $user, $category, 'category_deleted', 'Category deleted', $snapshot, null);
        });
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
    private function log(Request $request, User $user, Category $category, string $action, string $description, ?array $old, ?array $new): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
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
}
