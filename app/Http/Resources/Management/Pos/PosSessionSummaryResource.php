<?php

namespace App\Http\Resources\Management\Pos;

use App\Models\PosSession;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-17 — one cash-register session as the list, open and close responses
 * render it. Every money figure is an integer in kobo.
 *
 * `sales_total_amount` is pre-computed by withSum() on list queries; the model
 * method is the fallback for single rows (open/close responses). The inline
 * `(int) round((float) $amount * 100)` conversion the controller carried is
 * the {@see Naira::koboFromRounded()} contract.
 *
 * @property PosSession $resource
 */
final class PosSessionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PosSession $session */
        $session = $this->resource;

        $salesTotal = $session->sales_total_amount !== null
            ? Naira::koboFromRounded($session->sales_total_amount)
            : $session->calculateSalesTotal();

        $cashSalesTotal = $session->calculateCashSalesTotal();

        return [
            'id' => $session->id,
            'session_code' => $session->session_code,
            'status' => $session->status,
            'is_open' => $session->isOpen(),
            'store' => $session->store ? (new PosStoreResource($session->store))->toArray($request) : null,
            'staff' => $session->staff ? [
                'id' => $session->staff->id,
                'name' => $session->staff->name,
            ] : null,
            'opened_at' => $session->opened_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(),
            'opening_balance' => (int) $session->opening_balance,
            // Open sessions show the drawer as it stands now (float + confirmed
            // cash legs); closed sessions show the frozen reconciliation.
            'expected_close' => $session->isOpen()
                ? (int) $session->opening_balance + $cashSalesTotal
                : (int) $session->closing_balance_expected,
            'actual_close' => $session->closing_balance_actual !== null ? (int) $session->closing_balance_actual : null,
            'difference' => $session->difference !== null ? (int) $session->difference : null,
            'sales_total' => $salesTotal,
            'cash_sales_total' => $cashSalesTotal,
            'orders_count' => (int) ($session->orders_count ?? $session->orders()->count()),
            'notes' => $session->notes,
        ];
    }
}
