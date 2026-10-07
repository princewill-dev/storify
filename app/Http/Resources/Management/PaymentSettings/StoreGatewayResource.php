<?php

namespace App\Http\Resources\Management\PaymentSettings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — a business gateway as the store-side payment-method modal renders
 * it: the masked public key (never the secret) and whether this store
 * currently accepts it.
 */
final class StoreGatewayResource extends JsonResource
{
    use Concerns\MasksGatewayKeys;

    public function __construct(object $row, private readonly bool $assigned)
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
            'assigned' => $this->assigned,
        ];
    }
}
