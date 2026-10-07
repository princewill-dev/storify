<?php

namespace App\Http\Resources\Admin\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS7 — one slice of the payment-method donut, scoped by the from/to range
 * (the range scoped exactly this chart in legacy) and the store filter.
 *
 * Rows arrive as raw `selectRaw` results — `method`, `count` and a decimal
 * `total` string — so the casts the controller carried stay here.
 *
 * @property-read object{method: string, count: int|string, total: int|float|string} $resource
 */
final class PaymentBreakdownResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'method' => (string) $row->method,
            'count' => (int) $row->count,
            'total' => (float) $row->total,
        ];
    }
}
