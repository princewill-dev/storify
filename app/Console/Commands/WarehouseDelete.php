<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WarehouseDelete extends Command
{
    protected $signature = 'warehouse:delete
                            {identifier : The warehouse code (e.g. whs_dsgcwk4s1n)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Permanently delete a warehouse with all its products, sections, and stock';

    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        $warehouse = Warehouse::where('warehouse_code', $identifier)->first();

        if (!$warehouse) {
            $this->error("Warehouse [{$identifier}] not found.");
            return Command::FAILURE;
        }

        $sectionIds = $warehouse->sections()->pluck('id');
        $productIds = Product::whereIn('section_id', $sectionIds)->pluck('id');

        $this->warn("Warehouse: {$warehouse->name} ({$warehouse->warehouse_code})");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Sections', $sectionIds->count()],
                ['Products', $productIds->count()],
                ['Stock Locations', StockLocation::where('locationable_type', Warehouse::class)->where('locationable_id', $warehouse->id)->count()],
            ]
        );

        if (!$this->option('force') && !$this->confirm('This will PERMANENTLY delete the warehouse and ALL its data. Continue?', false)) {
            $this->info('Aborted.');
            return Command::FAILURE;
        }

        DB::transaction(function () use ($warehouse, $productIds) {
            if ($productIds->isNotEmpty()) {
                DB::table('stock_movements')->whereIn('product_id', $productIds)->delete();
                DB::table('inventory_stocks')->whereIn('product_id', $productIds)->delete();
                DB::table('inventory_movements')->whereIn('product_id', $productIds)->delete();
                DB::table('product_images')->whereIn('product_id', $productIds)->delete();
                DB::table('product_variants')->whereIn('product_id', $productIds)->delete();
                DB::table('cart_items')->whereIn('product_id', $productIds)->delete();
                DB::table('pack_items')->whereIn('product_id', $productIds)->delete();
                DB::table('storefront_slides')->whereIn('product_id', $productIds)->delete();
                DB::table('stock_transfer_items')->whereIn('product_id', $productIds)->delete();
                Product::whereIn('id', $productIds)->delete();
            }

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

        $this->info("Warehouse [{$identifier}] deleted successfully.");
        return Command::SUCCESS;
    }
}
