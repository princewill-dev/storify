<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\LedgerMapping;
use App\Services\Accounting\LedgerAccountTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-37 — one auto-posting mapping row of the settings screen, and the
 * 20-row payload (one row per template key, even where no mapping exists
 * yet).
 *
 * Mapping keys are validated against the template's 20 keys, so a typo no
 * longer creates a junk mapping row; the label falls back to a humanised key
 * for any future template addition.
 *
 * @property-read LedgerMapping|null $resource
 */
final class MappingResource extends JsonResource
{
    /** Human labels for the 20 mapping keys, in the legacy screen's order. */
    private const LABELS = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'gateway_clearing' => 'Gateway clearing',
        'accounts_receivable' => 'Accounts receivable',
        'inventory' => 'Inventory',
        'fixed_assets' => 'Fixed assets',
        'accounts_payable' => 'Accounts payable',
        'tax_payable' => 'Tax payable',
        'accrued_liabilities' => 'Accrued liabilities',
        'owner_equity' => "Owner's equity",
        'retained_earnings' => 'Retained earnings',
        'opening_balance_equity' => 'Opening balance equity',
        'sales_income' => 'Sales income',
        'service_charge_income' => 'Service charge income',
        'shipping_income' => 'Shipping income',
        'other_income' => 'Other income',
        'sales_discounts' => 'Sales discounts',
        'cogs' => 'Cost of goods sold',
        'gateway_fees' => 'Gateway fees',
        'default_expense' => 'Default expense',
    ];

    public function __construct(
        ?LedgerMapping $mapping,
        private readonly string $key,
        private readonly string $defaultCode,
    ) {
        parent::__construct($mapping);
    }

    /**
     * Every template key in template order, mapped or not.
     *
     * @param  Collection<string, LedgerMapping>  $mappings
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Collection $mappings): array
    {
        $rows = [];

        foreach (LedgerAccountTemplate::mappings() as $key => $defaultCode) {
            /** @var LedgerMapping|null $mapping */
            $mapping = $mappings->get($key);

            $rows[] = (new self($mapping, $key, $defaultCode))->resolve();
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mapping = $this->resource;

        return [
            'key' => $this->key,
            'label' => self::LABELS[$this->key] ?? ucfirst(str_replace('_', ' ', $this->key)),
            'account_id' => $mapping?->ledger_account_id,
            'account_code' => $mapping?->account?->code,
            'account_name' => $mapping?->account?->name,
            'default_code' => $this->defaultCode,
        ];
    }
}
