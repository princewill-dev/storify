<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\FiscalYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-13 — one fiscal year row of the platform accounting settings screen.
 * `can_close` is the model's own predicate, so the picker and the close
 * endpoint answer the same question.
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
            'can_close' => $year->isOpen(),
        ];
    }
}
