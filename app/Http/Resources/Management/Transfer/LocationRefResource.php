<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-15 — the from/to (and single-location) reference shape.
 *
 * `$class` is the morph type carried on the transfer row; the instance checks
 * cover call sites that pass an already-loaded model whose type is still the
 * authority (the pre-refactor `locationRef()` treated both as sources of
 * truth, and a deleted location resolves to null).
 *
 * @property Model|null $resource
 */
final class LocationRefResource extends JsonResource
{
    public function __construct(private readonly string $class, ?Model $location)
    {
        parent::__construct($location);
    }

    /**
     * Resolve one location reference, or null when the morph relation is
     * missing — the same `?array` the controller helper returned.
     *
     * @return array<string, mixed>|null
     */
    public static function for(string $class, ?Model $location, ?Request $request = null): ?array
    {
        if ($location === null) {
            return null;
        }

        return (new self($class, $location))->resolve($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Model|null $location */
        $location = $this->resource;

        if ($location === null) {
            return [];
        }

        $isWarehouse = $this->class === Warehouse::class || $location instanceof Warehouse;

        return [
            'type' => $isWarehouse ? 'warehouse' : 'store',
            'id' => $location->getKey(),
            'code' => $isWarehouse
                ? ($location instanceof Warehouse ? $location->warehouse_code : null)
                : ($location instanceof Store ? $location->store_id : null),
            'name' => $location instanceof Warehouse || $location instanceof Store ? $location->name : null,
        ];
    }
}
