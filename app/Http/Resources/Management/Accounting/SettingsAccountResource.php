<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — one row of the accounting settings screen's account list.
 *
 * `is_active` rides along so an inactive account a mapping already points at
 * stays visible in the picker instead of silently rendering as a blank
 * select. The row carries no parent, description or balance — it is the
 * settings list shape only.
 *
 * @mixin LedgerAccount
 */
final class SettingsAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'subtype' => $this->subtype,
            'is_active' => (bool) $this->is_active,
            'is_system' => (bool) $this->is_system,
        ];
    }
}
