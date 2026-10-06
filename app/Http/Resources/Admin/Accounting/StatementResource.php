<?php

namespace App\Http\Resources\Admin\Accounting;

use App\Models\LedgerAccount;

/**
 * WS-13 — the three platform statements (P&L, balance sheet, trial balance)
 * shaped for the wire from the raw arrays `LedgerReportService` returns.
 *
 * The report service speaks in model objects, so this is a static presenter
 * rather than a JsonResource instance: each named builder fixes the field set
 * the statement screen expects.
 */
final class StatementResource
{
    /**
     * The dashboard totals: the balance-sheet figures plus the all-time P&L
     * (legacy totalled every posted platform entry with no date window, so
     * there is no lower bound to show).
     *
     * @param  array<string, mixed>  $balanceSheet
     * @param  array<string, mixed>  $profitAndLoss
     * @return array<string, mixed>
     */
    public static function dashboardTotals(array $balanceSheet, array $profitAndLoss): array
    {
        return [
            'assets' => $balanceSheet['total_assets'],
            'liabilities' => $balanceSheet['total_liabilities'],
            'equity' => $balanceSheet['total_equity'],
            'revenue' => $profitAndLoss['total_income'],
            'expenses' => $profitAndLoss['total_expenses'],
            'net_profit' => $profitAndLoss['net_profit'],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function profitAndLoss(array $report): array
    {
        return [
            'income' => self::rows($report['income']),
            'expenses' => self::rows($report['expenses']),
            'total_income' => $report['total_income'],
            'total_expenses' => $report['total_expenses'],
            'net_profit' => $report['net_profit'],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function balanceSheet(array $report): array
    {
        return [
            'assets' => self::rows($report['assets']),
            'liabilities' => self::rows($report['liabilities']),
            'equity' => self::rows($report['equity']),
            'total_assets' => $report['total_assets'],
            'total_liabilities' => $report['total_liabilities'],
            'total_equity' => $report['total_equity'],
            'net_profit' => $report['net_profit'],
            'as_of' => $report['as_of'],
            // The accounting equation, surfaced rather than left for the
            // reader to check by hand.
            'balanced' => $report['total_assets'] === $report['total_liabilities'] + $report['total_equity'],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function trialBalance(array $report): array
    {
        return [
            'lines' => self::rows($report['lines']),
            'total_debit' => $report['total_debit'],
            'total_credit' => $report['total_credit'],
            'balanced' => $report['total_debit'] === $report['total_credit'],
        ];
    }

    /**
     * Report rows carry the account model; serialize it so the JSON shape
     * never depends on model attribute visibility.
     *
     * @param  array<int, array{account: LedgerAccount, amount?: int, debit?: int, credit?: int}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private static function rows(array $lines): array
    {
        return array_map(function (array $line) {
            /** @var LedgerAccount $account */
            $account = $line['account'];

            $row = [
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype,
            ];

            if (array_key_exists('amount', $line)) {
                $row['amount'] = $line['amount'];
            }

            if (array_key_exists('debit', $line)) {
                $row['debit'] = $line['debit'];
            }

            if (array_key_exists('credit', $line)) {
                $row['credit'] = $line['credit'];
            }

            return $row;
        }, $lines);
    }
}
