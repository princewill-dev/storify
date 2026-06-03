<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockLocation;
use Illuminate\Console\Command;

class BackfillStockLocationBusinessId extends Command
{
    protected $signature = 'storify:backfill-stock-location-business-id
                            {--business-id= : Specific business_id to backfill; if omitted, backfills all null records}';

    protected $description = 'Backfill null business_id on stock_locations from their related product';

    public function handle(): int
    {
        $businessId = $this->option('business-id');

        $query = StockLocation::withoutGlobalScopes()->whereNull('business_id');

        if ($businessId) {
            $query->whereHas('product', fn($q) => $q->withoutGlobalScopes()->where('business_id', $businessId));
            $this->info("Backfilling stock_locations for business_id = {$businessId}...");
        } else {
            $this->info('Finding all stock_locations with null business_id...');
        }

        $locations = $query->get();

        if ($locations->isEmpty()) {
            $this->info('No stock_locations need backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$locations->count()} records to backfill.");
        $bar = $this->output->createProgressBar($locations->count());
        $bar->start();

        $updated = 0;
        $skipped = 0;

        foreach ($locations as $loc) {
            $product = Product::withoutGlobalScopes()->find($loc->product_id);

            if (!$product || !$product->business_id) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $loc->updateQuietly(['business_id' => $product->business_id]);
            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Backfill complete.");
        $this->info("  Updated: {$updated}");
        $this->info("  Skipped: {$skipped} (no product or product has no business_id)");

        $remaining = StockLocation::withoutGlobalScopes()->whereNull('business_id')->count();
        if ($remaining > 0) {
            $this->warn("  {$remaining} stock_locations still have null business_id.");
        }

        return self::SUCCESS;
    }
}
