<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListBusinessTypesRequest;
use App\Http\Requests\Admin\StoreBusinessTypeRequest;
use App\Http\Requests\Admin\UpdateBusinessTypeRequest;
use App\Http\Resources\Admin\BusinessTypeResource;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Store;
use App\Repositories\Admin\BusinessTypeRepository;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. The list and reference-count queries live
 * in `BusinessTypeRepository` (the BusinessType model carries no relations, so
 * counts come from the referencing tables), the validation in the Admin
 * FormRequest classes and the row shaping in `BusinessTypeResource`. The
 * writes are single-table persists audited through the existing
 * `ActivityRecorder` service, so no new service layer is introduced.
 */
class BusinessTypeController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly BusinessTypeRepository $businessTypes,
    ) {}

    public function index(ListBusinessTypesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $types = $this->businessTypes->paginateList($request->validated());

        $ids = $types->getCollection()->pluck('id')->all();
        $businessCounts = $this->businessTypes->referenceCounts(Business::class, $ids);
        $storeCounts = $this->businessTypes->referenceCounts(Store::class, $ids);

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

    public function store(StoreBusinessTypeRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $type = BusinessType::create(['name' => $request->validated()['name']]);

        ActivityRecorder::record(
            action: 'business_type_created',
            description: "Business type '{$type->name}' created",
            subject: $type,
            new: ['name' => $type->name],
            actor: $request->user(),
        );

        return $this->ok(['business_type' => $this->payload($type, 0, 0)], 'Business type created.', 201);
    }

    public function update(UpdateBusinessTypeRequest $request, BusinessType $businessType): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $previous = $businessType->name;
        $businessType->update(['name' => $request->validated()['name']]);

        ActivityRecorder::record(
            action: 'business_type_updated',
            description: "Business type '{$previous}' renamed to '{$businessType->name}'",
            subject: $businessType,
            old: ['name' => $previous],
            new: ['name' => $businessType->name],
            actor: $request->user(),
        );

        $type = $businessType->fresh();
        $counts = $this->businessTypes->countsFor($type->id);

        return $this->ok(['business_type' => $this->payload($type, $counts['businesses'], $counts['stores'])], 'Business type updated.');
    }

    public function destroy(Request $request, BusinessType $businessType): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($this->businessTypes->isInUse($businessType->id)) {
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

    /**
     * The row shape, kept as a thin seam the index/create/rename responses
     * share; the fields live in BusinessTypeResource.
     *
     * @return array<string, mixed>
     */
    private function payload(BusinessType $type, int $businessesCount, int $storesCount): array
    {
        return BusinessTypeResource::make($type, $businessesCount, $storesCount)->resolve();
    }
}
