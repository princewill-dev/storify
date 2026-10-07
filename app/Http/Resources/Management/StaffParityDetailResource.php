<?php

namespace App\Http\Resources\Management;

use App\Models\StaffDocument;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * WS-20 — the staff block the show endpoint and every write response return.
 *
 * Keys are appended to the summary row in the order the controller's inline
 * `detail()` emitted them: the summary fields first, then created_at, stores,
 * warehouses, permissions and documents. `loadMissing()` runs before the
 * summary, exactly as detail() did, so the list's withCount aliases are used
 * when present and the relation counts otherwise. The document block reads
 * the per-row helpers on StaffDocument, unchanged.
 */
final class StaffParityDetailResource extends StaffParityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $member */
        $member = $this->resource;

        $member->loadMissing(['roles', 'assignedStores:id,name', 'assignedWarehouses:id,name', 'documents']);

        $data = parent::toArray($request);

        $data['created_at'] = $member->created_at?->toISOString();
        $data['stores'] = $member->assignedStores->map(fn ($store) => ['id' => $store->id, 'name' => $store->name])->values()->all();
        $data['warehouses'] = $member->assignedWarehouses->map(fn ($warehouse) => ['id' => $warehouse->id, 'name' => $warehouse->name])->values()->all();
        $data['permissions'] = $member->getAllPermissions()->pluck('name')->values()->all();
        $data['documents'] = $member->documents->map(fn (StaffDocument $document) => [
            'id' => $document->id,
            'original_name' => $document->original_name,
            'tag' => $document->tag,
            'mime_type' => $document->mime_type,
            'size' => (int) $document->size,
            'formatted_size' => $document->formattedSize(),
            'extension' => $document->extension(),
            'is_image' => $document->isImage(),
            'url' => $document->url(),
            'created_at' => $document->created_at?->toISOString(),
        ])->values()->all();

        return $data;
    }
}
