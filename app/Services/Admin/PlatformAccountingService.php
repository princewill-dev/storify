<?php

namespace App\Services\Admin;

use App\Models\FiscalPeriod;
use App\Repositories\Admin\Accounting\PlatformLedgerRepository;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-13 — platform-books write workflows that span more than one table.
 *
 * The mapping save is one transaction over the mapping rows, so it lives here
 * rather than in the repository (which never opens transactions) or the
 * controller. Period close/reopen pair the fiscal_periods write with its
 * activity-log row and request log; the year-close workflow itself stays in
 * the shared LedgerClosingService, which owns the ledger maths.
 */
final class PlatformAccountingService
{
    public function __construct(private readonly PlatformLedgerRepository $ledger) {}

    /**
     * @param  array<string, int|string>  $mappings
     */
    public function saveMappings(array $mappings, int $userId): void
    {
        DB::transaction(function () use ($mappings) {
            foreach ($mappings as $key => $accountId) {
                $this->ledger->saveMapping((string) $key, $accountId);
            }
        });

        ActivityLogger::log('accounting_mappings_updated', 'Platform accounting mappings updated.', [
            'keys' => array_keys($mappings),
        ]);

        Log::info('api.admin.accounting_mappings_updated', [
            'user_id' => $userId,
            'keys' => array_keys($mappings),
        ]);
    }

    public function closePeriod(FiscalPeriod $period, int $userId): void
    {
        $this->ledger->closePeriod($period, $userId);

        ActivityLogger::log('accounting_period_closed', "Platform fiscal period {$period->name} closed.", [
            'period_id' => $period->id,
        ]);

        Log::info('api.admin.accounting_period_closed', [
            'user_id' => $userId,
            'period_id' => $period->id,
        ]);
    }

    public function reopenPeriod(FiscalPeriod $period, int $userId): void
    {
        $this->ledger->reopenPeriod($period);

        ActivityLogger::log('accounting_period_reopened', "Platform fiscal period {$period->name} reopened.", [
            'period_id' => $period->id,
        ]);

        Log::info('api.admin.accounting_period_reopened', [
            'user_id' => $userId,
            'period_id' => $period->id,
        ]);
    }
}
