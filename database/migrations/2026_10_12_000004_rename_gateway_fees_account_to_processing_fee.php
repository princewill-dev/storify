<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 5500 is renamed "Processing Fee" for the businesses that already have it.
 *
 * The chart of accounts is seeded per business, and
 * `LedgerSetupService::ensureForBusiness()` uses `firstOrCreate` — so changing
 * the template's label (see `LedgerAccountTemplate`) only reaches businesses
 * created after this ships. Every existing business would keep reading "Bank &
 * Gateway Fees" on the very account whose whole purpose just changed: it now
 * carries the provider's fee *and* Storify's cut as one combined line.
 *
 * The match is on name as well as code, deliberately. A business is free to
 * rename its own accounts, and one that has already called 5500 something it
 * chose should not have that overwritten by a platform migration.
 *
 * Only the label moves. `code`, `subtype` and the chart's account identity are
 * untouched, and so is the `gateway_fees` mapping key the posting engine
 * resolves accounts through — that is a contract, not a label.
 */
return new class extends Migration
{
    private const CODE = '5500';

    private const FROM = 'Bank & Gateway Fees';

    private const TO = 'Processing Fee';

    public function up(): void
    {
        $this->rename(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->rename(self::TO, self::FROM);
    }

    /**
     * Both directions are the same statement, so they cannot drift apart. Each
     * is a no-op on a database that has already run it, which is what makes a
     * re-run safe.
     */
    private function rename(string $from, string $to): void
    {
        DB::table('ledger_accounts')
            ->where('code', self::CODE)
            ->where('name', $from)
            ->update(['name' => $to, 'updated_at' => now()]);
    }
};
