<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Store;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WS-15 (admin console) — the category write workflows.
 *
 * Every mutation pairs its table write with the audit row inside one
 * transaction (the recorder contract: a rejected audit row must take the
 * mutation down with it), keeping the exact order the controller used. The
 * controller keeps the HTTP shape: validation, the live-store 422 copy and
 * the response echo.
 *
 * Kept from legacy, moved here with the code it explains:
 *
 * - the slug is `Str::slug(name)` + a 6-character uuid fragment, so category
 *   names may repeat inside a store;
 * - the slug is stable across edits unless the name changes, in which case it
 *   is regenerated (legacy's `categoryNameChanged()` rule);
 * - deleting a category leaves its products in place — the FK nulls their
 *   `category_id` (`nullOnDelete`, products migration 2025_10_24), and the
 *   confirm copy names the count, which is why {@see delete()} reports it.
 */
final class CategoryService
{
    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(array $data, Store $store): Category
    {
        return DB::transaction(function () use ($data, $store) {
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
    }

    /**
     * Re-link the category to the chosen store (fixing legacy, which let a
     * category be attached to a store it did not belong to and left
     * `business_id` null) and audit the change.
     *
     * @param  array<string, mixed>  $data  the validated update payload
     */
    public function update(Category $category, array $data, Store $store): void
    {
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
    }

    /**
     * Delete the category and audit it, reporting how many products the FK
     * just left uncategorised. The count is read before the delete (the FK
     * nulls `category_id` on delete), exactly as the controller read it.
     */
    public function delete(Category $category): int
    {
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

        return $productCount;
    }
}
