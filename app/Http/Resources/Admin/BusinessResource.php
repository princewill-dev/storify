<?php

namespace App\Http\Resources\Admin;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\KycApplication;
use App\Models\OwnershipType;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-4 (admin console) — the business directory row / business console payload.
 *
 * `detailed` carries the console blocks legacy's detail page answered — owner
 * with phone/status/verified badge, team with roles, stores with their
 * ownership/business types, warehouses with stock counts, the subscription
 * and the KYC panel. The lean shape is the directory row and deliberately
 * stays a single query-budget: it only eager-loads what it shows.
 *
 * @property-read Business $resource
 */
final class BusinessResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(bool $detailed = true): static
    {
        $this->detailed = $detailed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Business $business */
        $business = $this->resource;

        $business->loadMissing([
            'owner:id,name,email,phone,account_code,status,is_verified,email_verified_at,last_login_at',
            'activeSubscription.subscriptionPlan:id,name',
        ]);

        if ($business->stores_count === null) {
            $business->loadCount([
                'stores as stores_count' => fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED),
                'warehouses as warehouses_count' => fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
                'users as users_count',
            ]);
        }

        $subscription = $business->activeSubscription;
        $owner = $business->owner;

        $data = [
            'id' => $business->id,
            'name' => $business->name,
            'business_code' => $business->business_code,
            'prefix' => $business->prefix,
            'slug' => $business->slug,
            'status' => $business->status,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'account_code' => $owner->account_code,
                'status' => $owner->status,
                'is_verified' => (bool) $owner->is_verified,
            ] : null,
            'plan' => $subscription?->subscriptionPlan?->name,
            'subscription' => [
                'name' => $subscription?->subscriptionPlan?->name,
                'status' => $subscription?->status,
                'ends_at' => $subscription?->expires_at?->toISOString(),
            ],
            'stores_count' => (int) ($business->stores_count ?? 0),
            'warehouses_count' => (int) ($business->warehouses_count ?? 0),
            'users_count' => (int) ($business->users_count ?? 0),
            'created_at' => $business->created_at?->toISOString(),
        ];

        if (! $this->detailed) {
            return $data;
        }

        $data['description'] = $business->description;
        $data['currency'] = $business->currency;
        // Business has no ownershipType()/businessType() relations (the model
        // is shared and off limits for this workstream), so the curated type
        // names the console shows are resolved from their own tables.
        $data['business_type'] = $business->business_type_id
            ? BusinessType::query()->whereKey($business->business_type_id)->value('name')
            : null;
        $data['ownership_type'] = $business->ownership_type_id
            ? OwnershipType::query()->whereKey($business->ownership_type_id)->value('name')
            : null;
        $data['updated_at'] = $business->updated_at?->toISOString();

        if ($owner !== null) {
            $data['owner']['email_verified_at'] = $owner->email_verified_at?->toISOString();
            $data['owner']['last_login_at'] = $owner->last_login_at?->toISOString();
        }

        $business->loadMissing([
            'stores.ownershipType:id,name',
            'stores.businessType:id,name',
            'warehouses' => fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
        ]);

        $data['stores'] = $business->stores
            ->where('status', '!=', Store::STATUS_DELETED)
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'store_type' => $store->store_type,
                'ownership_type' => $store->ownershipType?->name,
                'business_type' => $store->businessType?->name,
            ])->values()->all();

        $data['warehouses'] = $business->warehouses->map(fn (Warehouse $warehouse) => [
            'id' => $warehouse->id,
            'warehouse_code' => $warehouse->warehouse_code,
            'name' => $warehouse->name,
            'status' => $warehouse->status->value,
            // Legacy's "stock items" column counted stock-location rows; a row
            // holding nothing is not stock, so only positive quantities count.
            'stock_items_count' => (int) $warehouse->stockLocations()->where('quantity', '>', 0)->count(),
        ])->values()->all();

        $data['team'] = $this->teamPayload($business);

        $application = $business->kycApplications()->orderByDesc('id')->first();
        $data['kyc'] = $application ? $this->kycPayload($application) : null;

        return $data;
    }

    /**
     * Every user belonging to the business with the roles the console needs to
     * show. Spatie roles are team-scoped, so the team context has to be the
     * member's business while their roles are read.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamPayload(Business $business): array
    {
        $previousTeam = getPermissionsTeamId();

        try {
            return $business->users()
                ->orderBy('name')
                ->get()
                ->map(function (User $member) {
                    setPermissionsTeamId($member->business_id);

                    return [
                        'id' => $member->id,
                        'account_code' => $member->account_code,
                        'name' => $member->name,
                        'email' => $member->email,
                        'role' => $member->role,
                        'status' => $member->status,
                        'is_verified' => (bool) $member->is_verified,
                        'roles' => $member->getRoleNames()->values()->all(),
                    ];
                })->values()->all();
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function kycPayload(KycApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'legal_name' => $application->legal_name,
            'submitted_at' => $application->submitted_at?->toISOString(),
            'approved_at' => $application->approved_at?->toISOString(),
            'rejected_at' => $application->rejected_at?->toISOString(),
            'review_notes' => $application->review_notes,
            'reviewed_by' => $application->reviewed_by,
            'reviewer' => $application->reviewer?->name,
            'document_type' => $application->documentType?->name,
        ];
    }
}
