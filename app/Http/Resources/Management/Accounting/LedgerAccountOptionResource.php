<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-37 — bank/cash/gateway account option for the import modal picker.
 *
 * @mixin LedgerAccount
 */
final class LedgerAccountOptionResource extends JsonResource
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
            'subtype' => $this->subtype,
        ];
    }
}
