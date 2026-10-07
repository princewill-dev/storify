<?php

namespace App\Http\Resources\Management\Section;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-36 — the section detail payload: the summary fields, then the warehouse,
 * then `updated_at`, exactly the order the controller's inline `detail()`
 * built.
 *
 * The warehouse and the two product counts are loaded by the repository's
 * `loadForDetail()` before this resource is resolved, the same
 * `loadMissing()`/`loadCount()` pair the controller ran inline.
 *
 * @property Section $resource
 */
final class SectionDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Section $section */
        $section = $this->resource;

        return [
            ...(new SectionSummaryResource($section))->resolve($request),
            'warehouse' => $section->warehouse
                ? (new SectionWarehouseResource($section->warehouse))->resolve($request)
                : null,
            'updated_at' => $section->updated_at?->toISOString(),
        ];
    }
}
