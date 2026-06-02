<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Section;
use App\Models\StockLocation;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class SyncStockLocationsSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::whereNotNull('section_id')->get();
        $created = 0;
        $updated = 0;

        foreach ($products as $product) {
            $section = Section::find($product->section_id);
            if (!$section || !$section->warehouse_id) continue;

            $location = StockLocation::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'locationable_type' => Warehouse::class,
                    'locationable_id' => $section->warehouse_id,
                ],
                [
                    'quantity' => max(0, (int) $product->quantity),
                    'min_quantity' => 0,
                ]
            );

            if ($location->wasRecentlyCreated) {
                $created++;
            } elseif ($product->quantity != $location->quantity) {
                $location->update(['quantity' => max(0, (int) $product->quantity)]);
                $updated++;
            }
        }

        $this->command?->info("Stock locations synced: {$created} created, {$updated} updated.");
    }
}
