<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-12 — one row of the dispatch modal's agent picker.
 *
 * The listing is already scoped to the order's business and the Delivery
 * Agent role by OrderFulfilmentRepository; this resource only shapes the four
 * columns the picker renders. Legacy supplied the same list from the show
 * action.
 */
final class FulfilmentDeliveryAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $agent */
        $agent = $this->resource;

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'phone' => $agent->phone,
            'email' => $agent->email,
        ];
    }
}
