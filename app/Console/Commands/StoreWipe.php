<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StoreWipe extends Command
{
    protected $signature = 'store:wipe
                            {identifier : The store ID (e.g. st_6507410129)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Wipe all products belonging to a store';

    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        $store = Store::where('store_id', $identifier)->first();

        if (!$store) {
            $this->error("Store [{$identifier}] not found.");
            return Command::FAILURE;
        }

        $productIds = Product::where('store_id', $store->id)->pluck('id');

        $this->warn("Store: {$store->name} ({$store->store_id})");
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

        $this->info("Store [{$identifier}] products wiped successfully.");
        return Command::SUCCESS;
    }
}
