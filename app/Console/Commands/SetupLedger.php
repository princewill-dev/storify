<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Console\Command;

class SetupLedger extends Command
{
    protected $signature = 'ledger:setup {--business= : Only set up a single business id}';

    protected $description = 'Create the chart of accounts, mappings, and fiscal periods for businesses (and the platform)';

    public function handle(LedgerSetupService $setup): int
    {
        $setup->ensureForBusiness(null);
        $this->info('Platform books ready.');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($q) => $q->whereKey((int) $this->option('business')))
            ->get();

        foreach ($businesses as $business) {
            $setup->ensureForBusiness($business->id);
            $this->line("Books ready for #{$business->id} {$business->name}");
        }

        $this->info("Done. {$businesses->count()} business(es) processed.");

        return self::SUCCESS;
    }
}
