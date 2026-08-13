<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WarehouseWipe extends Command
{
    protected $signature = 'warehouse:wipe
                            {identifier : The warehouse code (e.g. whs_dsgcwk4s1n)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Wipe all products belonging to a warehouse';

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
        $this->info("Products to wipe: {$productIds->count()}");

        if (!$this->option('force') && !$this->confirm('This will PERMANENTLY delete these products. Continue?', false)) {
            $this->info('Aborted.');
            return Command::FAILURE;
        }

        DB::transaction(function () use ($productIds) {
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
            }

            Product::whereIn('id', $productIds)->delete();
        });

        $this->info("Warehouse [{$identifier}] products wiped successfully.");
        return Command::SUCCESS;
    }
}
