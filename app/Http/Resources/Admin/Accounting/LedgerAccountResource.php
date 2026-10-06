<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-13 — a platform chart-of-accounts row.
 *
 * `balance_kobo` is opt-in (`withSignedBalance`) and, when requested, is
 * expressed on the account's normal side — assets/expenses as debits,
 * everything else as credits — the rule the chart always applied.
 *
 * @property-read LedgerAccount $resource
 */
final class LedgerAccountResource extends JsonResource
{
    /** Chart-of-accounts grouping order; mirrors the legacy grouped tables. */
    private const TYPE_LABELS = [
        LedgerAccount::TYPE_ASSET => 'Assets',
        LedgerAccount::TYPE_LIABILITY => 'Liabilities',
        LedgerAccount::TYPE_EQUITY => 'Equity',
        LedgerAccount::TYPE_INCOME => 'Income',
        LedgerAccount::TYPE_EXPENSE => 'Expenses',
    ];

    private bool $withBalance = false;

    private int $debitMinusCredit = 0;

    /**
     * Attach the raw debit-minus-credit total; the normal-side sign is applied
     * at serialization.
     */
    public function withSignedBalance(int $debitMinusCredit): static
    {
        $this->withBalance = true;
        $this->debitMinusCredit = $debitMinusCredit;

        return $this;
    }

    /**
     * The platform chart, grouped by type with per-group counts (legacy) and
     * computed balances (improvement: one grouped query instead of the legacy
     * dashboard's query-per-cash-account loop).
     *
     * @param  Collection<int, LedgerAccount>  $accounts
     * @param  array<int, int>  $balances  raw debit-minus-credit per account id
     * @return array<int, array<string, mixed>>
     */
    public static function groups(Collection $accounts, array $balances): array
    {
        $groups = [];

        foreach (self::TYPE_LABELS as $type => $label) {
            $groupAccounts = $accounts->where('type', $type)->values();

            if ($groupAccounts->isEmpty()) {
                continue;
            }

            $rows = $groupAccounts
                ->map(fn (LedgerAccount $account) => static::make($account)
                    ->withSignedBalance($balances[$account->id] ?? 0)
                    ->resolve())
                ->all();

            $groups[] = [
                'type' => $type,
                'label' => $label,
                'count' => count($rows),
                'balance_kobo' => array_sum(array_column($rows, 'balance_kobo')),
                'accounts' => $rows,
            ];
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LedgerAccount $account */
        $account = $this->resource;

        $data = [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'subtype' => $account->subtype,
            'is_active' => (bool) $account->is_active,
            'is_system' => (bool) $account->is_system,
        ];

        if ($this->withBalance) {
            $data['balance_kobo'] = $account->isDebitNormal()
                ? $this->debitMinusCredit
                : -$this->debitMinusCredit;
        }

        return $data;
    }
}
