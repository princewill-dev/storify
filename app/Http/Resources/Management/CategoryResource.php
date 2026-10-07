<?php

namespace App\Http\Resources\Management;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-31 — a category row as the list and the write responses render it.
 *
 * `products_count` arrives from `withCount('products')` on the list and
 * `loadCount('products')` on the create/update echo; it defaults to 0 when no
 * count was loaded, exactly as the controller's row() did.
 *
 * `parent_id` is deliberately absent from the payload as well as the write
 * rules — the hierarchy is schema-only in every stack until the storefront
 * consumes it (roadmap D9).
 */
final class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'name' => $category->name,
            // Parity gap the verify pass named: the legacy table had a slug
            // column the modern list dropped.
            'slug' => $category->slug,
            'store_id' => $category->store_id,
            'status' => $category->status,
            'products_count' => (int) ($category->products_count ?? 0),
        ];
    }
}
