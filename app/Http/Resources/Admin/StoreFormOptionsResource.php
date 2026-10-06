<?php

namespace App\Http\Resources\Admin;

use App\Http\Requests\Admin\CreateStoreRequest;
use App\Http\Requests\Admin\UpdateStoreRequest;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\OwnershipType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-6 (admin console) — the create/edit modal dropdowns.
 *
 * Legacy loaded these per form action; one endpoint keeps the SPA form's data
 * in a single round trip. `StoreModerationRepository::formOptions()` reads the
 * rows and this resource only shapes them, so the query side and the response
 * side stay separate.
 *
 * The status lists live on the two write requests that validate them, so the
 * form's selectable values and the validator can never drift apart. The
 * main-store/`ALLOW_MS_SETUP` state lets the SPA disable "Add Store" with an
 * honest reason instead of failing on submit (legacy flashed the refusal only
 * after the form was filled in).
 *
 * @property-read array{
 *     businesses: Collection<int, Business>,
 *     ownership_types: Collection<int, OwnershipType>,
 *     business_types: Collection<int, BusinessType>,
 * } $resource
 */
final class StoreFormOptionsResource extends JsonResource
{
    /**
     * @param  array{
     *     businesses: Collection<int, Business>,
     *     ownership_types: Collection<int, OwnershipType>,
     *     business_types: Collection<int, BusinessType>,
     * }  $options
     */
    public function __construct(
        array $options,
        private readonly ?int $mainStoreId,
        private readonly bool $multiBusinessSetupAllowed,
    ) {
        parent::__construct($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'businesses' => collect($this->resource['businesses'])->map(fn (Business $business) => [
                'id' => $business->id,
                'name' => $business->name,
                'business_code' => $business->business_code,
                'owner' => $business->owner?->name,
                'owner_email' => $business->owner?->email,
            ])->values()->all(),
            'ownership_types' => collect($this->resource['ownership_types'])->map(fn (OwnershipType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])->values()->all(),
            'business_types' => collect($this->resource['business_types'])->map(fn (BusinessType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])->values()->all(),
            'statuses' => [
                'create' => CreateStoreRequest::CREATE_STATUSES,
                'edit' => UpdateStoreRequest::EDITABLE_STATUSES,
            ],
            'main_store_id' => $this->mainStoreId,
            'multi_business_setup_allowed' => $this->multiBusinessSetupAllowed,
            'can_create' => ! ($this->mainStoreId !== null && ! $this->multiBusinessSetupAllowed),
        ];
    }
}
