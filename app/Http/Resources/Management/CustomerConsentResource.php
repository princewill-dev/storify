<?php

namespace App\Http\Resources\Management;

use App\Models\CustomerConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-56 — one row of the consent trail.
 *
 * The IP and the source are here because they are the whole reason this is a
 * trail rather than a boolean: they answer "when, and how, did they agree",
 * which is the question a consent record exists to answer. Listed newest first
 * so the top row is the current answer and everything under it is history.
 *
 * `label` comes from the enum so the screen does not have to hold a second
 * copy of the wording.
 */
final class CustomerConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerConsent $consent */
        $consent = $this->resource;

        return [
            'id' => $consent->id,
            'type' => $consent->type->value,
            'label' => $consent->type->label(),
            'granted' => (bool) $consent->granted,
            'source' => $consent->source,
            'ip_address' => $consent->ip_address,
            'created_at' => $consent->created_at?->toISOString(),
        ];
    }
}
