<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\OwnershipType;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS-4 (admin console) — curated ownership-type list.
 *
 * Exactly the shape of BusinessTypeController (legacy had the two as parallel
 * CRUDs, both behind `permission:admin.content`): alphabetical list, unique
 * name ≤255, `q` filter, and a refusal to delete a type that businesses or
 * stores still reference instead of orphaning them the way legacy did.
 */
class OwnershipTypeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $types = OwnershipType::query()
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.trim($filters['q']).'%'))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $ids = $types->getCollection()->pluck('id')->all();
        $businessCounts = $this->referenceCounts(Business::class, $ids);
        $storeCounts = $this->referenceCounts(Store::class, $ids);

        return $this->ok(
            $types->getCollection()->map(fn (OwnershipType $type) => $this->payload(
                $type,
                $businessCounts[$type->id] ?? 0,
                $storeCounts[$type->id] ?? 0,
            ))->values()->all(),
            null,
            200,
            $this->paginationMeta($types),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('ownership_types', 'name')],
        ]);

        $type = OwnershipType::create(['name' => $data['name']]);

        ActivityRecorder::record(
            action: 'ownership_type_created',
            description: "Ownership type '{$type->name}' created",
            subject: $type,
            new: ['name' => $type->name],
            actor: $request->user(),
        );

        return $this->ok(['ownership_type' => $this->payload($type, 0, 0)], 'Ownership type created.', 201);
    }

    public function update(Request $request, OwnershipType $ownershipType): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('ownership_types', 'name')->ignore($ownershipType->id)],
        ]);

        $previous = $ownershipType->name;
        $ownershipType->update(['name' => $data['name']]);

        ActivityRecorder::record(
            action: 'ownership_type_updated',
            description: "Ownership type '{$previous}' renamed to '{$ownershipType->name}'",
            subject: $ownershipType,
            old: ['name' => $previous],
            new: ['name' => $ownershipType->name],
            actor: $request->user(),
        );

        $type = $ownershipType->fresh();
        $counts = $this->countsFor($type->id);

        return $this->ok(['ownership_type' => $this->payload($type, $counts['businesses'], $counts['stores'])], 'Ownership type updated.');
    }

    public function destroy(Request $request, OwnershipType $ownershipType): JsonResponse
    {
        $this->authorizePlatformAccess($request);

        if ($this->inUse($ownershipType->id)) {
            return $this->error('This ownership type is in use by a business or store and cannot be deleted.', 422);
        }

        $name = $ownershipType->name;
        $ownershipType->delete();

        ActivityRecorder::record(
            action: 'ownership_type_deleted',
            description: "Ownership type '{$name}' deleted",
            old: ['name' => $name],
            actor: $request->user(),
        );

        return $this->ok([], 'Ownership type deleted.');
    }

    private function inUse(int $typeId): bool
    {
        $counts = $this->countsFor($typeId);

        return $counts['businesses'] > 0 || $counts['stores'] > 0;
    }

    /**
     * @return array{businesses: int, stores: int}
     */
    private function countsFor(int $typeId): array
    {
        return [
            'businesses' => Business::query()->where('ownership_type_id', $typeId)->count(),
            'stores' => Store::query()->where('ownership_type_id', $typeId)->count(),
        ];
    }

    /**
     * @param  class-string  $model
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function referenceCounts(string $model, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->whereIn('ownership_type_id', $ids)
            ->selectRaw('ownership_type_id, COUNT(*) as aggregate')
            ->groupBy('ownership_type_id')
            ->pluck('aggregate', 'ownership_type_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OwnershipType $type, int $businessesCount, int $storesCount): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'businesses_count' => $businessesCount,
            'stores_count' => $storesCount,
        ];
    }

    private function authorizePlatformAccess(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->isAdmin(),
            403,
            'This endpoint is restricted to platform administrators.',
        );
    }
}
