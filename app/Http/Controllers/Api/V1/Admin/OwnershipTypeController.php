<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListOwnershipTypesRequest;
use App\Http\Requests\Admin\StoreOwnershipTypeRequest;
use App\Http\Requests\Admin\UpdateOwnershipTypeRequest;
use App\Http\Resources\Admin\OwnershipTypeResource;
use App\Models\Business;
use App\Models\OwnershipType;
use App\Models\Store;
use App\Repositories\Admin\OwnershipTypeRepository;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-4 (admin console) — curated ownership-type list.
 *
 * Exactly the shape of BusinessTypeController (legacy had the two as parallel
 * CRUDs, both behind `permission:admin.content`): alphabetical list, unique
 * name ≤255, `q` filter, and a refusal to delete a type that businesses or
 * stores still reference instead of orphaning them the way legacy did.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. The list and reference-count queries live
 * in `OwnershipTypeRepository` (the OwnershipType model carries no relations,
 * so counts come from the referencing tables), the validation in the Admin
 * FormRequest classes and the row shaping in `OwnershipTypeResource`. The
 * writes are single-table persists audited through the existing
 * `ActivityRecorder` service, so no new service layer is introduced.
 */
class OwnershipTypeController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly OwnershipTypeRepository $ownershipTypes,
    ) {}

    public function index(ListOwnershipTypesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $types = $this->ownershipTypes->paginateList($request->validated());

        $ids = $types->getCollection()->pluck('id')->all();
        $businessCounts = $this->ownershipTypes->referenceCounts(Business::class, $ids);
        $storeCounts = $this->ownershipTypes->referenceCounts(Store::class, $ids);

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

    public function store(StoreOwnershipTypeRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $type = OwnershipType::create(['name' => $request->validated()['name']]);

        ActivityRecorder::record(
            action: 'ownership_type_created',
            description: "Ownership type '{$type->name}' created",
            subject: $type,
            new: ['name' => $type->name],
            actor: $request->user(),
        );

        return $this->ok(['ownership_type' => $this->payload($type, 0, 0)], 'Ownership type created.', 201);
    }

    public function update(UpdateOwnershipTypeRequest $request, OwnershipType $ownershipType): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $previous = $ownershipType->name;
        $ownershipType->update(['name' => $request->validated()['name']]);

        ActivityRecorder::record(
            action: 'ownership_type_updated',
            description: "Ownership type '{$previous}' renamed to '{$ownershipType->name}'",
            subject: $ownershipType,
            old: ['name' => $previous],
            new: ['name' => $ownershipType->name],
            actor: $request->user(),
        );

        $type = $ownershipType->fresh();
        $counts = $this->ownershipTypes->countsFor($type->id);

        return $this->ok(['ownership_type' => $this->payload($type, $counts['businesses'], $counts['stores'])], 'Ownership type updated.');
    }

    public function destroy(Request $request, OwnershipType $ownershipType): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($this->ownershipTypes->isInUse($ownershipType->id)) {
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

    /**
     * The row shape, kept as a thin seam the index/create/rename responses
     * share; the fields live in OwnershipTypeResource.
     *
     * @return array<string, mixed>
     */
    private function payload(OwnershipType $type, int $businessesCount, int $storesCount): array
    {
        return OwnershipTypeResource::make($type, $businessesCount, $storesCount)->resolve();
    }
}
