<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\FiscalYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — one fiscal year row of the settings screen and of the year-close
 * response.
 *
 * @property-read FiscalYear $resource
 */
final class FiscalYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FiscalYear $year */
        $year = $this->resource;

        return [
            'id' => $year->id,
            'name' => $year->name,
            'start_date' => $year->start_date?->toDateString(),
            'end_date' => $year->end_date?->toDateString(),
            'status' => $year->status,
        ];
    }
}
