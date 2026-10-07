<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\FiscalPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — one fiscal period row of the settings screen and of the
 * close/reopen responses.
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
