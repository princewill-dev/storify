<?php

namespace App\Http\Resources\Admin;

use App\Models\EarlyPassUsage;
use App\Repositories\Admin\EarlyPassRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 (admin console) — one early-pass redemption row for the detail page.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them). The redeemer, their
 * business and the store are rendered as explicit nulls when missing — a
 * redemption by a user with no business must not crash the detail view — and
 * `used_at` is an ISO-8601 string or null.
 *
 * The eager loads that feed this shape live in
 * {@see EarlyPassRepository::paginateUsages()}.
 *
 * @property-read EarlyPassUsage $resource
 */
class EarlyPassUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EarlyPassUsage $usage */
        $usage = $this->resource;

        return [
            'id' => $usage->id,
            'used_at' => $usage->used_at?->toISOString(),
            'user' => $usage->user ? [
                'id' => $usage->user->id,
                'account_code' => $usage->user->account_code,
                'name' => $usage->user->name,
                'email' => $usage->user->email,
            ] : null,
            'business' => $usage->user?->business ? [
                'id' => $usage->user->business->id,
                'name' => $usage->user->business->name,
                'business_code' => $usage->user->business->business_code,
            ] : null,
            'store' => $usage->store ? [
                'id' => $usage->store->id,
                'store_id' => $usage->store->store_id,
                'name' => $usage->store->name,
            ] : null,
        ];
    }
}
