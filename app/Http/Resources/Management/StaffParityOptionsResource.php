<?php

namespace App\Http\Resources\Management;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — everything the invite/edit form needs to render its pickers in one
 * call: the team roles with their permission chips, the accessible stores and
 * the accessible warehouses.
 *
 * The controller hands over what the user can reach and the repository read;
 * this resource only shapes it, so the query side and the response side stay
 * separate. Role order is the repository's name order; the permission chips
 * are the eager-loaded `permissions:id,name` relation.
 *
 * @property-read array{
 *     roles: Collection<int, Role>,
 *     stores: Collection<int, Store>,
 *     warehouses: Collection<int, Warehouse>,
 * } $resource
 */
final class StaffParityOptionsResource extends JsonResource
{
    /**
     * @param  array{
     *     roles: Collection<int, Role>,
     *     stores: Collection<int, Store>,
     *     warehouses: Collection<int, Warehouse>,
     * }  $options
     */
    public function __construct(array $options)
    {
        parent::__construct($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'roles' => collect($this->resource['roles'])->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ])->values()->all(),
            'stores' => collect($this->resource['stores'])->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
            ])->values()->all(),
            'warehouses' => collect($this->resource['warehouses'])->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'warehouse_code' => $warehouse->warehouse_code,
                'name' => $warehouse->name,
            ])->values()->all(),
        ];
    }
}
