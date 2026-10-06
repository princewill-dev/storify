<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-13 — one row of the dashboard's cash & clearing panel.
 *
 * The panel shows the raw debit-minus-credit total for the account (every
 * cash/bank/gateway_clearing subtype is an asset, so no normal-side re-signing
 * was applied here) and deliberately omits the chart-row fields the panel has
 * never carried.
 *
 * @property-read LedgerAccount $resource
 */
final class CashBalanceResource extends JsonResource
{
    private int $balanceKobo = 0;

    /**
     * The whole panel, balances attached per account.
     *
     * @param  Collection<int, LedgerAccount>  $accounts
     * @param  array<int, int>  $balances  raw debit-minus-credit per account id
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Collection $accounts, array $balances): array
    {
        return $accounts
            ->map(fn (LedgerAccount $account) => self::make($account)
                ->withBalance($balances[$account->id] ?? 0)
                ->resolve())
            ->values()->all();
    }

    public function withBalance(int $balanceKobo): static
    {
        $this->balanceKobo = $balanceKobo;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LedgerAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'subtype' => $account->subtype,
            'balance_kobo' => $this->balanceKobo,
        ];
    }
}
