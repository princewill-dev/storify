<?php

namespace App\Services\Management\Warehouse;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The warehouse write workflows.
 *
 * Create, update and soft-delete keep the controller's transaction boundaries
 * and write order: the DB::transaction first, the `Log::info` audit row after
 * it, with the same event names and metadata arrays. Update carries no audit
 * row because the controller carried none. Delete carries no transaction
 * because the controller had none — the status update is a single statement.
 *
 * The HTTP shape stays in the controller: the messages, the 201/200/422
 * statuses, the 422 that refuses deleting a warehouse still holding stock and
 * the 403 access guard all sit in front of these calls, unchanged.
 */
final class WarehouseService
{
    /**
     * @param  array<string, mixed>  $data  validated by WarehousePayloadRequest
     */
    public function create(User $user, array $data): Warehouse
    {
        $warehouse = DB::transaction(function () use ($user, $data) {
            $warehouse = Warehouse::create([
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ($data['is_active'] ?? true) ? Warehouse::STATUS_ACTIVE : Warehouse::STATUS_INACTIVE,
            ]);

            $this->syncStaff($warehouse, $data['staff_ids'] ?? []);

            return $warehouse;
        });

        Log::info('api.management.warehouse_created', [
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
        ]);

        return $warehouse;
    }

    /**
     * @param  array<string, mixed>  $data  validated by WarehousePayloadRequest
     */
    public function update(Warehouse $warehouse, array $data): void
    {
        DB::transaction(function () use ($warehouse, $data) {
            $warehouse->update([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ($data['is_active'] ?? true) ? Warehouse::STATUS_ACTIVE : Warehouse::STATUS_INACTIVE,
            ]);

            $this->syncStaff($warehouse, $data['staff_ids'] ?? []);
        });
    }

    /**
     * Soft-delete (status flag), the controller's single `update()` call and
     * audit row, moved as-is.
     */
    public function delete(User $user, Warehouse $warehouse): void
    {
        $warehouse->update(['status' => Warehouse::STATUS_DELETED]);

        Log::info('api.management.warehouse_deleted', [
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    /**
     * The legacy multi-select kept only the last id it was handed, so a
     * warehouse could never end up with more than one assignee.
     *
     * @param  array<int, int>  $staffIds
     */
    private function syncStaff(Warehouse $warehouse, array $staffIds): void
    {
        $warehouse->assignedStaff()->sync(array_values(array_filter($staffIds)));
    }
}
