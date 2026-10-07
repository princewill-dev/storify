<?php

namespace App\Http\Resources\Management\Category;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A base CategoryController list row — the controller's inline map verbatim,
 * field names, order and types included.
 *
 * Deliberately separate from App\Http\Resources\Management\CategoryResource
 * (the WS-31 slice behind the shared routes, served by CategoryParityController):
 * that payload omits `parent_id` and defaults `products_count` to 0; the base
 * slice returns `parent_id` and keeps the count nullable when no withCount
 * loaded it. The two are not interchangeable.
 *
 * @property Category $resource
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'store_id' => $this->resource->store_id,
            'parent_id' => $this->resource->parent_id,
            'status' => $this->resource->status,
            'products_count' => $this->resource->products_count ?? null,
        ];
    }
}
