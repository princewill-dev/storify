<?php

namespace App\Http\Resources\Management\Section;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-36 — a section list row: the controller's inline `summary()` verbatim,
 * field names, order and types included. Tests assert exact JSON, so nothing
 * here is "tidied": the counts keep their `?? 0` defaults (the list loads
 * them with `withCount`, the detail with `loadCount`) and the timestamp keeps
 * its ISO-8601 string form.
 *
 * @property Section $resource
 */
final class SectionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $section = $this->resource;

        return [
            'id' => $section->id,
            'section_code' => $section->section_code,
            'name' => $section->name,
            'description' => $section->description,
            'status' => $section->status->value,
            'products_count' => (int) ($section->products_count ?? 0),
            'active_products_count' => (int) ($section->active_products_count ?? 0),
            'created_at' => $section->created_at?->toISOString(),
        ];
    }
}
