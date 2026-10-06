<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\LedgerMapping;
use App\Services\Accounting\LedgerAccountTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-13 — one auto-posting mapping row of the platform accounting settings
 * screen, and the 20-row payload (one row per template key, even where no
 * mapping exists yet).
 *
 * Mapping keys are validated against the template's 20 keys, so a typo no
 * longer creates a junk mapping row; the label falls back to a humanised key
 * for any future template addition.
 *
 * @property-read LedgerMapping|null $resource
 */
final class MappingResource extends JsonResource
{
    /** Human labels for the 20 mapping keys (the legacy admin screen's set). */
    private const LABELS = [
        'cash' => 'Cash on Hand',
        'bank' => 'Bank',
        'gateway_clearing' => 'Payment Gateway Clearing',
        'accounts_receivable' => 'Accounts Receivable',
        'inventory' => 'Inventory',
        'fixed_assets' => 'Fixed Assets',
        'accounts_payable' => 'Accounts Payable',
        'tax_payable' => 'VAT Payable',
        'accrued_liabilities' => 'Accrued Liabilities',
        'owner_equity' => "Owner's Equity",
        'retained_earnings' => 'Retained Earnings',
        'opening_balance_equity' => 'Opening Balance Equity',
        'sales_income' => 'Subscription Revenue',
        'service_charge_income' => 'Service Charge Income',
        'shipping_income' => 'Shipping Income',
        'other_income' => 'Other Income',
        'sales_discounts' => 'Sales Discounts',
        'cogs' => 'Cost of Goods Sold',
        'gateway_fees' => 'Bank & Gateway Fees',
        'default_expense' => 'Miscellaneous Expense',
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
