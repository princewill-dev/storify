<?php

namespace App\Services\Accounting;

class LedgerAccountTemplate
{
    /**
     * Default chart of accounts.
     *
     * @return array<int, array{code: string, name: string, type: string, subtype: string}>
     */
    public static function accounts(): array
    {
        return [
            // Assets
            ['code' => '1010', 'name' => 'Cash on Hand', 'type' => 'asset', 'subtype' => 'cash'],
            ['code' => '1020', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
            ['code' => '1030', 'name' => 'Payment Gateway Clearing', 'type' => 'asset', 'subtype' => 'gateway_clearing'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'accounts_receivable'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'inventory'],
            ['code' => '1500', 'name' => 'Fixed Assets', 'type' => 'asset', 'subtype' => 'fixed_asset'],

            // Liabilities
            ['code' => '2010', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'accounts_payable'],
            ['code' => '2100', 'name' => 'VAT Payable', 'type' => 'liability', 'subtype' => 'tax_payable'],
            ['code' => '2200', 'name' => 'Accrued Liabilities', 'type' => 'liability', 'subtype' => 'accrued_liability'],

            // Equity
            ['code' => '3010', 'name' => "Owner's Equity", 'type' => 'equity', 'subtype' => 'owner_equity'],
            ['code' => '3020', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'retained_earnings'],
            ['code' => '3030', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'subtype' => 'opening_balance_equity'],

            // Income
            ['code' => '4010', 'name' => 'Sales Revenue', 'type' => 'income', 'subtype' => 'sales_income'],
            ['code' => '4020', 'name' => 'Service Charge Income', 'type' => 'income', 'subtype' => 'service_charge_income'],
            ['code' => '4030', 'name' => 'Shipping Income', 'type' => 'income', 'subtype' => 'shipping_income'],
            ['code' => '4090', 'name' => 'Other Income', 'type' => 'income', 'subtype' => 'other_income'],
            ['code' => '4900', 'name' => 'Sales Discounts', 'type' => 'income', 'subtype' => 'sales_discounts'],

            // Expenses
            ['code' => '5010', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cogs'],
            ['code' => '5100', 'name' => 'Salaries & Wages', 'type' => 'expense', 'subtype' => 'payroll'],
            ['code' => '5200', 'name' => 'Rent', 'type' => 'expense', 'subtype' => 'rent'],
            ['code' => '5300', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'utilities'],
            ['code' => '5400', 'name' => 'Marketing', 'type' => 'expense', 'subtype' => 'marketing'],
            ['code' => '5500', 'name' => 'Bank & Gateway Fees', 'type' => 'expense', 'subtype' => 'gateway_fees'],
            ['code' => '5600', 'name' => 'Office Supplies', 'type' => 'expense', 'subtype' => 'office_supplies'],
            ['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'subtype' => 'default_expense'],
        ];
    }

    /**
     * Mapping keys to account codes used by the posting engine.
     *
     * @return array<string, string>
     */
    public static function mappings(): array
    {
        return [
            'cash' => '1010',
            'bank' => '1020',
            'gateway_clearing' => '1030',
            'accounts_receivable' => '1100',
            'inventory' => '1200',
            'fixed_assets' => '1500',
            'accounts_payable' => '2010',
            'tax_payable' => '2100',
            'accrued_liabilities' => '2200',
            'owner_equity' => '3010',
            'retained_earnings' => '3020',
            'opening_balance_equity' => '3030',
            'sales_income' => '4010',
            'service_charge_income' => '4020',
            'shipping_income' => '4030',
            'other_income' => '4090',
            'sales_discounts' => '4900',
            'cogs' => '5010',
            'gateway_fees' => '5500',
            'default_expense' => '5900',
        ];
    }

    /**
     * Default expense categories mapped to account codes.
     *
     * @return array<string, string>
     */
    public static function expenseCategories(): array
    {
        return [
            'Salaries & Wages' => '5100',
            'Rent' => '5200',
            'Utilities' => '5300',
            'Marketing' => '5400',
            'Office Supplies' => '5600',
            'Miscellaneous' => '5900',
        ];
    }
}
