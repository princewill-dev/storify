<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\FiscalPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-13 — one fiscal period row of the platform accounting settings screen.
 *
 * @property-read FiscalPeriod $resource
 */
final class FiscalPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FiscalPeriod $period */
        $period = $this->resource;

        return [
            'id' => $period->id,
            'name' => $period->name,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'status' => $period->status,
            'closed_at' => $period->closed_at?->toISOString(),
        ];
    }
}
