<?php

namespace App\Http\Resources\Management\Warehouse;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The warehouse detail payload — the controller's inline `detail()` verbatim:
 * the summary fields first, then the address block, then the sections and
 * staff lists, in the exact order the controller emitted them.
 *
 * The sections and the assigned staff are loaded by the repository's
 * `loadForDetail()` (`loadMissing`) before this resource resolves, the same
 * call the controller ran inline; no query is issued here.
 *
 * @property Warehouse $resource
 */
final class WarehouseDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->resource;

        return [
            ...(new WarehouseSummaryResource($warehouse))->resolve($request),
            'address' => $warehouse->address,
            'country' => $warehouse->country,
            'contact_person' => $warehouse->contact_person,
            'contact_phone' => $warehouse->contact_phone,
            'description' => $warehouse->description,
            'sections' => $warehouse->sections->map(fn ($section) => [
                'id' => $section->id,
                'section_code' => $section->section_code,
                'name' => $section->name,
                'status' => $section->status->value,
            ])->all(),
            'staff' => $warehouse->assignedStaff->map(fn (User $staff) => [
                'id' => $staff->id,
                'account_code' => $staff->account_code,
                'name' => $staff->name,
                'email' => $staff->email,
            ])->all(),
        ];
    }
}
