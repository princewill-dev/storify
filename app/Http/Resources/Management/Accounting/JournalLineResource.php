<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — match-picker candidate payload (a posted journal line joined to its
 * entry, so `entry_number`/`entry_date` come from the joined row).
 *
 * @mixin JournalLine
 */
final class JournalLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date instanceof \DateTimeInterface
                ? $this->entry_date->format('Y-m-d')
                : (string) $this->entry_date,
            'description' => $this->description,
            'debit_kobo' => (int) $this->debit_kobo,
            'credit_kobo' => (int) $this->credit_kobo,
            // Signed net, so the picker can show which way the money went.
            'amount_kobo' => (int) $this->debit_kobo - (int) $this->credit_kobo,
        ];
    }
}
