<?php

namespace App\Http\Resources\Pos;

use App\Models\PosSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The reconciliation block the terminal's `close` response renders.
 *
 * Field names, types and order are frozen: the three balances and the
 * difference are integer kobo, `closed_at` an ISO 8601 string. All four
 * figures are written by PosSession::close() immediately before this resource
 * renders — the maths stays on the model, this class only maps it.
 */
final class PosClosedSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PosSession $session */
        $session = $this->resource;

        return [
            'session_code' => $session->session_code,
            'opening_balance' => $session->opening_balance,
            'closing_balance_expected' => $session->closing_balance_expected,
            'closing_balance_actual' => $session->closing_balance_actual,
            'difference' => $session->difference,
            'closed_at' => $session->closed_at->toISOString(),
        ];
    }
}
