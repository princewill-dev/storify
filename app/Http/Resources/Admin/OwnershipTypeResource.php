<?php

namespace App\Http\Resources\Admin;

use App\Models\OwnershipType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-4 (admin console) — the curated ownership-type row.
 *
 * The reference counts are computed per page by the repository and passed in:
 * the OwnershipType model carries no relations (it is shared and off limits
 * for this workstream), so they cannot be loaded on the row itself.
 * Create/rename responses reuse the same shape with zero counts.
 *
 * @property-read OwnershipType $resource
 */
class OwnershipTypeResource extends JsonResource
{
    public function __construct(
        OwnershipType $type,
        private readonly int $businessesCount,
        private readonly int $storesCount,
    ) {
        parent::__construct($type);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OwnershipType $type */
        $type = $this->resource;

        return [
            'id' => $type->id,
            'name' => $type->name,
            'businesses_count' => $this->businessesCount,
            'stores_count' => $this->storesCount,
        ];
    }
}
