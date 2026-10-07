<?php

namespace App\Http\Resources\Management\Section;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-36 — a picker row: the controller's inline picker map verbatim, field
 * names, order and null-ability included.
 *
 * @property Section $resource
 */
final class SectionPickerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Section $section */
        $section = $this->resource;

        return [
            'id' => $section->id,
            'section_code' => $section->section_code,
            'name' => $section->name,
            'status' => $section->status->value,
            'warehouse_id' => $section->warehouse_id,
            'warehouse_code' => $section->warehouse?->warehouse_code,
            'warehouse_name' => $section->warehouse?->name,
        ];
    }
}
