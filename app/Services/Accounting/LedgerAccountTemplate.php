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
            // Where a business's money sits while Storify holds it. Deliberately
            // separate from 1030 rather than folded into it: 1030 means "at the
            // provider, on the business's own keys", and this means "collected by
            // Storify and owed to the business". Both can be true at once for one
            // business, and `LedgerReportService::integrity()` has to tell them
            // apart to reconcile either.
            ['code' => '1040', 'name' => 'Funds Held by Storify', 'type' => 'asset', 'subtype' => 'held_by_platform'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'accounts_receivable'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'inventory'],
            ['code' => '1500', 'name' => 'Fixed Assets', 'type' => 'asset', 'subtype' => 'fixed_asset'],

            // Liabilities
            ['code' => '2010', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'accounts_payable'],
            ['code' => '2100', 'name' => 'VAT Payable', 'type' => 'liability', 'subtype' => 'tax_payable'],
            ['code' => '2200', 'name' => 'Accrued Liabilities', 'type' => 'liability', 'subtype' => 'accrued_liability'],
            // **Retired going forward, kept because posted entries point at it.**
            //
            // It used to hold the provider's fee on every collected sale — a claim
            // on the charge that was never ours, because the business was the one
            // being charged for it. That is reversed: on a sale Storify charges on
            // its own credentials, the provider takes its fee out of our balance
            // and the cost is ours ({@see 5510}). So nothing credits this account
            // any more, and its remaining balance is the pre-cutover accruals that
            // were left where they were rather than reclassified.
            //
            // The account and its mapping stay for that reason alone — deleting
            // either would orphan the entries that reference them.
            ['code' => '2210', 'name' => 'Provider Fees Payable', 'type' => 'liability', 'subtype' => 'provider_fees_payable'],
            // The platform's side of the same money. A collected sale credits this
            // with the business's share; the payout that settles it debits it back
            // out. It is a liability because the money is not ours — it is the
            // whole reason the chart needed a new account at all.
            //
            // Like every account in this template it exists on both scopes. A
            // business's books carry it unused, exactly as the platform's carry
            // Cost of Goods Sold; the template is a superset and the posting
            // engine resolves only the keys a given entry needs.
            ['code' => '2300', 'name' => 'Funds Held for Businesses', 'type' => 'liability', 'subtype' => 'business_funds_held'],

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
            // Named for the one thing a business sees on this account: what it
            // cost to take the payment. It holds what the business was actually
            // charged — both the provider's charge and Storify's cut on a sale
            // charged through the business's own keys, and Storify's cut alone on
            // one Storify charged for it, where the provider's charge is our own
            // cost and lives on 5510. One line either way, under the one name the
            // business is given for it.
            //
            // The `gateway_fees` subtype is the mapping key the posting engine
            // resolves this account through; it is a contract and does not move
            // with the label or with what the account holds.
            ['code' => '5500', 'name' => 'Processing Fee', 'type' => 'expense', 'subtype' => 'gateway_fees'],
            // What the provider charges **Storify** for taking a payment on its
            // behalf. A separate account from 5500 rather than the platform
            // scope's copy of it, because 5500 has exactly one meaning — the
            // single deduction a business sees — and this is not a deduction a
            // business ever sees. It is our cost of doing business, and it needs
            // to be identifiable as that by subtype, not only by whose books it
            // is on.
            //
            // It carries a balance on the platform's books only. Like every
            // account here it is instantiated on both scopes, so a business's
            // chart holds it unused — as it already holds 2210, 2300 and 1040 —
            // and a zero-balance account never reaches a P&L, because
            // `LedgerReportService::profitAndLoss()` skips those.
            ['code' => '5510', 'name' => 'Provider Processing Fees', 'type' => 'expense', 'subtype' => 'provider_processing_fees'],
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
            'held_by_platform' => '1040',
            'accounts_receivable' => '1100',
            'inventory' => '1200',
            'fixed_assets' => '1500',
            'accounts_payable' => '2010',
            'tax_payable' => '2100',
            'accrued_liabilities' => '2200',
            'provider_fees_payable' => '2210',
            'business_funds_held' => '2300',
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
            'provider_processing_fees' => '5510',
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
