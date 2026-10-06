<?php

namespace App\Http\Resources\Management;

use App\Enums\TransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-18 — a status option for the list controls, so the SPA does not print
 * raw values.
 *
 * @property TransactionStatus $resource
 */
class TransactionStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'value' => $this->resource->value,
            'label' => $this->resource->label(),
        ];
    }
}
