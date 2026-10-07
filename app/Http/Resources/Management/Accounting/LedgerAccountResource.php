<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-23 — one chart-of-accounts row: the account fields the SPA table and
 * form render, the parent link, and — on the list route only — the
 * sign-corrected posted balance.
 *
 * Balances are keyed by ledger account id and handed in by the controller
 * from LedgerAccountRepository::postedBalances(); the create/update/toggle
 * payloads have no balances to show, so their balance_kobo is null, exactly
 * as the controller's private accountPayload() emitted before.
 *
 * Balances are sign-corrected per account type exactly as legacy did: assets
 * and expenses grow on the debit side, everything else on the credit side.
 */
final class LedgerAccountResource extends JsonResource
{
    /** @var Collection<int, \stdClass>|null */
    private ?Collection $balances;

    /**
     * @param  Collection<int, \stdClass>|null  $balances
     */
    public function __construct(LedgerAccount $account, ?Collection $balances = null)
    {
        parent::__construct($account);

        $this->balances = $balances;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LedgerAccount $account */
        $account = $this->resource;

        $balanceKobo = null;

        if ($this->balances !== null) {
            $row = $this->balances->get($account->id);
            $debit = (int) ($row->debit ?? 0);
            $credit = (int) ($row->credit ?? 0);

            $balanceKobo = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
        }

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'subtype' => $account->subtype,
            'parent' => $account->parent ? [
                'id' => $account->parent->id,
                'code' => $account->parent->code,
                'name' => $account->parent->name,
            ] : null,
            'description' => $account->description,
            'is_system' => (bool) $account->is_system,
            'is_active' => (bool) $account->is_active,
            'balance_kobo' => $balanceKobo,
        ];
    }
}
