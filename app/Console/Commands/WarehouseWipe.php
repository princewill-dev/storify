<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WarehouseWipe extends Command
{
    protected $signature = 'warehouse:wipe
                            {identifier : The warehouse code (e.g. whs_dsgcwk4s1n)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Wipe all data belonging to a warehouse';

    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        $warehouse = Warehouse::where('warehouse_code', $identifier)->first();

        if (!$warehouse) {
            $this->error("Warehouse [{$identifier}] not found.");
            return Command::FAILURE;
        }

        $sectionIds = $warehouse->sections()->pluck('id');
        $productCount = Product::whereIn('section_id', $sectionIds)->count();
        $sectionCount = $sectionIds->count();
        $stockCount = StockLocation::where('locationable_type', Warehouse::class)
            ->where('locationable_id', $warehouse->id)
            ->count();
        $transferCount = \App\Models\StockTransfer::where(function ($q) use ($warehouse) {
            $q->where('from_location_type', Warehouse::class)->where('from_location_id', $warehouse->id)
              ->orWhere('to_location_type', Warehouse::class)->where('to_location_id', $warehouse->id);
        })->count();

        $this->warn("Warehouse: {$warehouse->name} ({$warehouse->warehouse_code})");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Sections', $sectionCount],
                ['Products', $productCount],
                ['Stock Locations', $stockCount],
                ['Stock Transfers', $transferCount],
            ]
        );

        if (!$this->option('force') && !$this->confirm('This will PERMANENTLY delete all this data. Continue?', false)) {
            $this->info('Aborted.');
            return Command::FAILURE;
        }

        DB::transaction(function () use ($warehouse, $sectionIds) {
            Product::whereIn('section_id', $sectionIds)->delete();

            StockLocation::where('locationable_type', Warehouse::class)
                ->where('locationable_id', $warehouse->id)
                ->delete();

            \App\Models\StockTransfer::where(function ($q) use ($warehouse) {
                $q->where('from_location_type', Warehouse::class)->where('from_location_id', $warehouse->id)
                  ->orWhere('to_location_type', Warehouse::class)->where('to_location_id', $warehouse->id);
            })->delete();

            $warehouse->sections()->delete();
            $warehouse->delete();
        });

        $this->info("Warehouse [{$identifier}] wiped successfully.");
        return Command::SUCCESS;
    }
}
