<?php

namespace App\Http\Resources\Pos;

use App\Models\PosSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The open-register block the terminal's `status` and `open` responses
 * render.
 *
 * Field names, types and order are frozen: `opening_balance`, `sales_total`
 * and `cash_sales_total` are integer kobo, `opened_at` an ISO 8601 string.
 *
 * `withZeroTotals()` reproduces the open response: a register that was just
 * created has no orders, and the legacy payload hard-coded `0` for both
 * totals rather than asking the model — that is kept so opening a session
 * does not run the two aggregate queries the status call does.
 */
final class PosSessionResource extends JsonResource
{
    private bool $computeTotals = true;

    /**
     * The just-opened payload: totals are literal zeroes, not aggregates.
     */
    public function withZeroTotals(): self
    {
        $this->computeTotals = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PosSession $session */
        $session = $this->resource;

        return [
            'session_code' => $session->session_code,
            'opened_at' => $session->opened_at->toISOString(),
            'opening_balance' => $session->opening_balance,
            'sales_total' => $this->computeTotals ? $session->calculateSalesTotal() : 0,
            'cash_sales_total' => $this->computeTotals ? $session->calculateCashSalesTotal() : 0,
        ];
    }
}
