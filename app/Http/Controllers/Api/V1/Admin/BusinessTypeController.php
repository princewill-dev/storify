<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Store;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS-4 (admin console) — curated business-type list.
 *
 * The values feed `businesses.business_type_id` and `stores.business_type_id`,
 * so the console the audit asked for is a full CRUD (legacy had list/create/
 * edit/delete behind `permission:admin.content`), plus two fixes over legacy:
 * `q` filtering on a list that will outgrow one screen, and a refusal to
 * delete a type that is still referenced — legacy deleted freely and left every
 * business/store pointing at a row that no longer existed.
 *
 * The BusinessType model carries no relations (it is shared and off limits for
 * this workstream), so reference counts come from the referencing tables.
 */
class BusinessTypeController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $types = BusinessType::query()
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.trim($filters['q']).'%'))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $ids = $types->getCollection()->pluck('id')->all();
        $businessCounts = $this->referenceCounts(Business::class, $ids);
        $storeCounts = $this->referenceCounts(Store::class, $ids);

        return $this->ok(
            $types->getCollection()->map(fn (BusinessType $type) => $this->payload(
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
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('business_types', 'name')],
        ]);

        $type = BusinessType::create(['name' => $data['name']]);

        ActivityRecorder::record(
            action: 'business_type_created',
            description: "Business type '{$type->name}' created",
            subject: $type,
            new: ['name' => $type->name],
            actor: $request->user(),
        );

        return $this->ok(['business_type' => $this->payload($type, 0, 0)], 'Business type created.', 201);
    }

    public function update(Request $request, BusinessType $businessType): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('business_types', 'name')->ignore($businessType->id)],
        ]);

        $previous = $businessType->name;
        $businessType->update(['name' => $data['name']]);

        ActivityRecorder::record(
            action: 'business_type_updated',
            description: "Business type '{$previous}' renamed to '{$businessType->name}'",
            subject: $businessType,
            old: ['name' => $previous],
            new: ['name' => $businessType->name],
            actor: $request->user(),
        );

        $type = $businessType->fresh();
        $counts = $this->countsFor($type->id);

        return $this->ok(['business_type' => $this->payload($type, $counts['businesses'], $counts['stores'])], 'Business type updated.');
    }

    public function destroy(Request $request, BusinessType $businessType): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($this->inUse($businessType->id)) {
            return $this->error('This business type is in use by a business or store and cannot be deleted.', 422);
        }

        $name = $businessType->name;
        $businessType->delete();

        ActivityRecorder::record(
            action: 'business_type_deleted',
            description: "Business type '{$name}' deleted",
            old: ['name' => $name],
            actor: $request->user(),
        );

        return $this->ok([], 'Business type deleted.');
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
            'businesses' => Business::query()->where('business_type_id', $typeId)->count(),
            'stores' => Store::query()->where('business_type_id', $typeId)->count(),
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
            ->whereIn('business_type_id', $ids)
            ->selectRaw('business_type_id, COUNT(*) as aggregate')
            ->groupBy('business_type_id')
            ->pluck('aggregate', 'business_type_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BusinessType $type, int $businessesCount, int $storesCount): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'businesses_count' => $businessesCount,
            'stores_count' => $storesCount,
        ];
    }
}
