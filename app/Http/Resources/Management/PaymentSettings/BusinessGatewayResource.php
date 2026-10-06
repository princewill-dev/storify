<?php

namespace App\Http\Resources\Management\PaymentSettings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * WS-11 — a connected business gateway (a `business_payment_method` pivot row
 * joined with its payment method), as the payment-settings screen renders it.
 *
 * The secret key is never serialized — legacy round-tripped it into the edit
 * modal in plaintext.
 */
class BusinessGatewayResource extends JsonResource
{
    use Concerns\MasksGatewayKeys;

    public function __construct(object $row, private readonly int $assignedStoresCount)
    {
        parent::__construct($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $config = json_decode($this->resource->config ?: '{}', true) ?: [];

        return [
            'id' => (int) $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'is_active' => (bool) $this->resource->is_active,
            'public_key_masked' => $this->maskKey($config['public_key'] ?? null),
            'has_secret_key' => ! empty($config['secret_key']),
            'assigned_stores_count' => $this->assignedStoresCount,
            'created_at' => isset($this->resource->created_at) ? Carbon::parse($this->resource->created_at)->toISOString() : null,
        ];
    }
}
