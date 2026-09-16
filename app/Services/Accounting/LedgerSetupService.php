<?php

namespace App\Services\Accounting;

use App\Models\ExpenseCategory;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use Carbon\Carbon;

class LedgerSetupService
{
    /**
     * Idempotently bootstrap a full set of books for a business (or platform when null).
     */
    public function ensureForBusiness(?int $businessId): void
    {
        $accountsByCode = [];

        foreach (LedgerAccountTemplate::accounts() as $definition) {
            $account = LedgerAccount::firstOrCreate(
                ['business_id' => $businessId, 'code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'subtype' => $definition['subtype'],
                    'currency' => 'NGN',
                    'is_system' => true,
                    'is_active' => true,
                ]
            );

            $accountsByCode[$definition['code']] = $account;
        }

        foreach (LedgerAccountTemplate::mappings() as $key => $code) {
            if (! isset($accountsByCode[$code])) {
                continue;
            }

            LedgerMapping::firstOrCreate(
                ['business_id' => $businessId, 'key' => $key],
                ['ledger_account_id' => $accountsByCode[$code]->id]
            );
        }

        foreach (LedgerAccountTemplate::expenseCategories() as $name => $code) {
            if (! isset($accountsByCode[$code])) {
                continue;
            }

            ExpenseCategory::firstOrCreate(
                ['business_id' => $businessId, 'name' => $name],
                ['ledger_account_id' => $accountsByCode[$code]->id, 'is_active' => true]
            );
        }

        $this->ensureFiscalYear($businessId, (int) now()->year);
    }

    public function ensureFiscalYear(?int $businessId, int $year): FiscalYear
    {
        $fiscalYear = FiscalYear::firstOrCreate(
            ['business_id' => $businessId, 'name' => (string) $year],
            [
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-12-31",
                'status' => 'open',
            ]
        );

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1);
            $end = $start->copy()->endOfMonth();

            FiscalPeriod::firstOrCreate(
                ['business_id' => $businessId, 'name' => $start->format('Y-m')],
                [
                    'fiscal_year_id' => $fiscalYear->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => 'open',
                ]
            );
        }

        return $fiscalYear;
    }

    /**
     * Resolve the open fiscal period containing the date, creating the year if needed.
     *
     * @throws \RuntimeException when the period is closed
     */
    public function resolveOpenPeriod(?int $businessId, string $date): FiscalPeriod
    {
        $date = Carbon::parse($date);
        $periodName = $date->format('Y-m');

        $period = FiscalPeriod::where('business_id', $businessId)
            ->where('name', $periodName)
            ->first();

        if (! $period) {
            $this->ensureFiscalYear($businessId, (int) $date->year);
            $period = FiscalPeriod::where('business_id', $businessId)
                ->where('name', $periodName)
                ->firstOrFail();
        }

        if (! $period->isOpen()) {
            throw new \RuntimeException("Fiscal period {$period->name} is closed.");
        }

        return $period;
    }

    /**
     * Look up a mapped account id for a business.
     */
    public function accountId(?int $businessId, string $key): int
    {
        $mapping = LedgerMapping::where('business_id', $businessId)
            ->where('key', $key)
            ->first();

        if (! $mapping) {
            $this->ensureForBusiness($businessId);

            $mapping = LedgerMapping::where('business_id', $businessId)
                ->where('key', $key)
                ->firstOrFail();
        }

        return (int) $mapping->ledger_account_id;
    }
}
