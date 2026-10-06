<?php

namespace App\Http\Resources\Management\Invoice;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-21 — a customer picker row for the create/edit invoice form.
 *
 * @property Customer $resource
 */
final class CustomerOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->full_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
        ];
    }
}
