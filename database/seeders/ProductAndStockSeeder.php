<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Section;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductAndStockSeeder extends Seeder
{
    protected array $categoryNames = [
        'Paints & Mediums',
        'Brushes & Tools',
        'Canvas & Surfaces',
        'Sketching & Drawing',
        'Craft & Sculpting',
    ];

    protected array $productCatalog = [
        'Paints & Mediums' => [
            ['Acrylic Paint Set 24x12ml', 'Arteza', 8500, 480, 150],
            ['Professional Oil Paint Set 12x40ml', 'Winsor & Newton', 18500, 620, 80],
            ['Watercolour Paint Tin 36 Colours', 'Van Gogh', 12000, 350, 100],
            ['Heavy Body Acrylic 200ml — Titanium White', 'Liquitex', 4500, 220, 200],
            ['Gouache Paint Set 18x30ml', 'Holbein', 9500, 420, 120],
            ['Acrylic Pouring Medium 500ml', 'Mont Marte', 3200, 510, 90],
            ['Linseed Oil Refined 250ml', 'Art Spectrum', 2500, 260, 180],
            ['Spray Paint — Matte Black 400ml', 'MTN Colors', 3800, 400, 140],
        ],
        'Brushes & Tools' => [
            ['Professional Brush Set 12pc — Taklon', 'da Vinci', 11000, 180, 100],
            ['Palette Knife Set 5pc — Stainless Steel', 'RGM', 5500, 160, 85],
            ['Flat Wash Brush 2-inch — Hog Bristle', 'Princeton', 2800, 70, 150],
            ['Fine Detail Liner Brush Set 7pc', 'Winsor & Newton', 6500, 50, 120],
            ['Silicone Painting Wedge Set 5pc', 'Catalyst', 4200, 120, 75],
            ['Brush Cleaner & Restorer 250ml', 'General Pencil', 1800, 270, 200],
            ['Watercolour Mop Brush — Squirrel Hair #8', 'Escoda', 7500, 30, 60],
            ['Artist Brush Roll-Up Organizer 24-Slot', 'Art Advantage', 5200, 220, 80],
        ],
        'Canvas & Surfaces' => [
            ['Stretched Cotton Canvas 16x20in 5-Pack', 'Masterpiece', 15000, 2400, 60],
            ['Canvas Panel Board 11x14in 10-Pack', 'Fredrix', 8000, 1100, 100],
            ['Wood Painting Panel Cradle 12x12in', 'Ampersand', 6800, 850, 70],
            ['Watercolour Paper Pad A4 300gsm 20 Sheets', 'Arches', 9500, 380, 140],
            ['Mixed Media Art Journal A5 80 Pages', 'Strathmore', 4500, 320, 120],
            ['Gallery Wrapped Canvas 24x36in — Deep Edge', 'Blick Studio', 22000, 3600, 35],
            ['Painting Panel Round 30cm 3-Pack', 'Artlicious', 3500, 580, 90],
            ['Canvas Roll 1.5m x 3m — Cotton Duck', 'Fabriano', 14500, 4200, 40],
        ],
        'Sketching & Drawing' => [
            ['Graphite Pencil Set 24pc — 6H to 8B', 'Faber-Castell', 7500, 180, 160],
            ['Charcoal Drawing Set 12pc — Willow & Compressed', 'Generals', 4200, 250, 120],
            ['Soft Pastel Set 48pc — Half Sticks', 'Rembrandt', 13500, 380, 80],
            ['Sketchbook A4 Hardbound 120 Pages 110gsm', 'Moleskine', 6500, 420, 140],
            ['Coloured Pencil Set 72pc — Oil-Based', 'Prismacolor', 18000, 520, 90],
            ['Fineliner Pen Set 12pc — Pigment Ink', 'Staedtler', 3800, 80, 200],
            ['Kneaded Eraser & Blending Stump Kit', 'Tombow', 1500, 40, 250],
            ['Metallic Marker Set 10pc — Dual Tip', 'Ohuhu', 5200, 150, 110],
        ],
        'Craft & Sculpting' => [
            ['Air-Dry Modelling Clay 2.5kg — White', 'Jovi', 4500, 2550, 70],
            ['Polymer Clay Starter Kit 24 Colours', 'Sculpey', 8500, 680, 90],
            ['Wire Armature Figure 30cm — Adjustable', 'Armature Pro', 3200, 180, 45],
            ['Modelling Tool Set 15pc — Wood & Metal', 'Mont Marte', 5500, 350, 100],
            ['Plaster of Paris 5kg — Fine Casting', 'DAP', 2800, 5100, 60],
            ['Mosaic Tile Assortment 500g — Glass', 'Mosaic Mercantile', 6200, 520, 80],
            ['Resin Art Kit — UV Crystal Clear 500ml', 'ArtResin', 11000, 600, 55],
            ['Pottery Carving Ribbon Tool Set 8pc', 'Kemper', 4800, 120, 120],
        ],
    ];

    protected array $sectionNames = [
        'Aisle A — Paints & Inks',
        'Aisle B — Canvas & Surfaces',
        'Climate-Controlled — Paper & Prints',
        'Bulk Storage — Clay & Plaster',
        'Fulfilment — Orders & Packing',
        'Overflow — Seasonal Art Kits',
        'High-Value Cage — Premium Pigments',
    ];

    public function run(?int $businessId = null, $warehouseId = null): void
    {
        $warehouse = null;
        $business = null;

        if ($warehouseId) {
            $warehouse = is_numeric($warehouseId)
                ? Warehouse::find($warehouseId)
                : Warehouse::where('warehouse_code', $warehouseId)->first();

            if (!$warehouse) {
                $msg = "Warehouse '{$warehouseId}' not found.";
                if ($this->command) $this->command->warn($msg); else echo $msg . PHP_EOL;
                return;
            }
            $business = $warehouse->business;
        }

        if (!$business) {
            $business = $businessId
                ? Business::find($businessId)
                : Business::first();
        }

        if (!$business) {
            $this->warn('No business found. Ensure BusinessSeeder has run first.');
            return;
        }

        $stores = $business->stores;

        if ($warehouseId) {
            if (!$warehouse) {
                $msg = "Warehouse '{$warehouseId}' not found.";
                if ($this->command) $this->command->warn($msg); else echo $msg . PHP_EOL;
                return;
            }
            $sections = $this->seedSections($warehouse, $business);
            $sizeUnitIds = \DB::table('size_units')->pluck('id')->all();
            $weightUnitIds = \DB::table('weight_units')->pluck('id')->all();
            $currencyId = \DB::table('currencies')->where('is_default', true)->value('id') ?? 1;
            $total = $this->seedWarehouseProducts($warehouse, $business, $sections, $sizeUnitIds, $weightUnitIds, $currencyId);
            $this->info("Done: {$total} warehouse-only products created for [{$warehouse->name}].");
            return;
        }

        if ($stores->isEmpty()) {
            $store = Store::firstOrCreate(
                ['business_id' => $business->id, 'slug' => Str::slug($business->name)],
                [
                    'name' => $business->name . ' Store',
                    'user_id' => $business->user_id,
                    'status' => 'active',
                ]
            );
            $stores = collect([$store]);
            $this->line("No stores found — created default store [{$store->name}].");
        }

        if (!$warehouse) {
            $warehouse = Warehouse::where('business_id', $business->id)->first();
        }

        if (!$warehouse) {
            $this->warn("No warehouse found for business [{$business->name}]. Skipping warehouse stock.");
        }

        $sizeUnitIds = \DB::table('size_units')->pluck('id')->all();
        $weightUnitIds = \DB::table('weight_units')->pluck('id')->all();
        $currencyId = \DB::table('currencies')->where('is_default', true)->value('id') ?? 1;
        $sections = $warehouse ? $this->seedSections($warehouse, $business) : collect([]);

        $totalProducts = 0;
        $totalStock = 0;

        foreach ($stores as $store) {
            // Seed categories
            $categories = $this->seedCategories($store);

            // Count existing products for this store (idempotent guard)
            $existingCount = Product::where('store_id', $store->id)->count();
            if ($existingCount > 0) {
                $this->line("  Store [{$store->name}] already has {$existingCount} products. Skipping.");
                continue;
            }

            // Seed products
            foreach ($categories as $cat) {
                $products = $this->productCatalog[$cat->name] ?? [];
                foreach ($products as $index => $pData) {
                    [$name, $brand, $amount, $weight, $quantity] = $pData;
                    $isVariant = $index % 3 === 0;

                    $assignedSection = $sections->isNotEmpty() ? $sections[$index % $sections->count()] : null;

                    $product = Product::create([
                        'store_id' => $store->id,
                        'business_id' => $business->id,
                        'warehouse_id' => $warehouse?->id,
                        'product_code' => 'prd_' . strtoupper(Str::random(8)),
                        'category_id' => $cat->id,
                        'section_id' => $assignedSection?->id,
                        'name' => $name,
                        'brand' => $brand,
                        'slug' => Str::slug($name) . '-' . substr((string) Str::uuid(), 0, 8),
                        'description' => "High-quality {$name} by {$brand}. Perfect for everyday use.",
                        'quantity' => $isVariant ? null : $quantity,
                        'stock_quantity' => $isVariant ? null : $quantity + rand(50, 200),
                        'size' => !empty($sizeUnitIds) ? rand(10, 500) : null,
                        'size_unit_id' => !empty($sizeUnitIds) ? Arr::random($sizeUnitIds) : null,
                        'weight' => $weight,
                        'weight_unit_id' => !empty($weightUnitIds) ? Arr::random($weightUnitIds) : null,
                        'amount' => $isVariant ? null : $amount,
                        'currency_id' => $currencyId,
                        'discount_percentage' => rand(0, 3) === 0 ? rand(5, 25) : null,
                        'status' => 'active',
                        'featured' => $index < 3,
                        'cod_available' => true,
                        'has_variants' => $isVariant,
                        'color' => Arr::random(['Black', 'White', 'Navy', 'Forest Green', 'Charcoal', 'Beige']),
                    ]);

                    // Create variants for 1/3 of products
                    if ($isVariant) {
                        $colors = ['Black', 'Silver', 'Blue', 'Red'];
                        foreach ($colors as $vi => $color) {
                            ProductVariant::create([
                                'product_id' => $product->id,
                                'business_id' => $business->id,
                                'variant_code' => 'var_' . strtoupper(Str::random(10)),
                                'sku' => strtoupper(substr($brand, 0, 3)) . '-' . Str::random(5),
                                'color' => $color,
                                'quantity' => rand(5, 50),
                                'amount' => $amount + ($vi * 500),
                                'currency_id' => $currencyId,
                                'size' => rand(10, 200),
                                'size_unit_id' => !empty($sizeUnitIds) ? Arr::random($sizeUnitIds) : null,
                                'weight' => $weight + ($vi * 10),
                                'weight_unit_id' => !empty($weightUnitIds) ? Arr::random($weightUnitIds) : null,
                                'status' => 'active',
                                'featured' => $vi === 0,
                            ]);
                        }
                    }

                    // Warehouse stock (bulk inventory)
                    if ($warehouse) {
                        StockLocation::firstOrCreate(
                            [
                                'product_id' => $product->id,
                                'product_variant_id' => null,
                                'locationable_type' => Warehouse::class,
                                'locationable_id' => $warehouse->id,
                            ],
                            [
                                'quantity' => $quantity * rand(3, 10),
                                'min_quantity' => $quantity * 2,
                                'business_id' => $business->id,
                            ]
                        );
                    }

                    // Store stock (what's currently on shelves)
                    StockLocation::firstOrCreate(
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'locationable_type' => Store::class,
                            'locationable_id' => $store->id,
                        ],
                        [
                            'quantity' => $quantity,
                            'min_quantity' => rand(1, 20),
                            'business_id' => $business->id,
                        ]
                    );

                    $this->attachImage($product, $totalProducts);

                    $totalProducts++;
                    $totalStock += $quantity;
                }
            }

            $this->info("  Store [{$store->name}]: {$totalProducts} products seeded.");
        }

        $this->info("Done: {$totalProducts} products across " . $stores->count() . " stores, warehouse stock created for [{$warehouse?->name}].");
    }

    protected function seedWarehouseProducts(Warehouse $warehouse, Business $business, $sections, array $sizeUnitIds, array $weightUnitIds, $currencyId): int
    {
        $existingCount = Product::where('warehouse_id', $warehouse->id)->whereNull('store_id')->count();
        if ($existingCount > 0) {
            $this->line("  Warehouse [{$warehouse->name}] already has {$existingCount} warehouse-only products. Skipping.");

            // Attach images to existing products if they don't have any
            $productsWithoutImages = Product::where('warehouse_id', $warehouse->id)
                ->whereNull('store_id')
                ->whereDoesntHave('images')
                ->get();

            $imgCount = 0;
            foreach ($productsWithoutImages as $product) {
                $this->attachImage($product, $imgCount);
                $imgCount++;
            }
            if ($imgCount > 0) {
                $this->line("  Attached images to {$imgCount} existing products.");
            }

            return $existingCount;
        }

        $totalProducts = 0;

        foreach ($this->categoryNames as $categoryName) {
            $products = $this->productCatalog[$categoryName] ?? [];
            foreach ($products as $index => $pData) {
                [$name, $brand, $amount, $weight, $quantity] = $pData;
                $assignedSection = $sections->isNotEmpty() ? $sections[$index % $sections->count()] : null;

                $product = Product::create([
                    'store_id' => null,
                    'warehouse_id' => $warehouse->id,
                    'business_id' => $business->id,
                    'product_code' => 'prd_' . strtoupper(Str::random(8)),
                    'category_id' => null,
                    'section_id' => $assignedSection?->id,
                    'name' => $name,
                    'brand' => $brand,
                    'slug' => Str::slug($name) . '-' . substr((string) Str::uuid(), 0, 8),
                    'description' => "Warehouse stock: {$name} by {$brand}.",
                    'quantity' => $quantity * 10,
                    'stock_quantity' => $quantity * 15,
                    'size' => !empty($sizeUnitIds) ? rand(10, 500) : null,
                    'size_unit_id' => !empty($sizeUnitIds) ? Arr::random($sizeUnitIds) : null,
                    'weight' => $weight,
                    'weight_unit_id' => !empty($weightUnitIds) ? Arr::random($weightUnitIds) : null,
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                    'discount_percentage' => rand(0, 3) === 0 ? rand(5, 25) : null,
                    'status' => 'active',
                    'featured' => $index < 3,
                    'cod_available' => false,
                    'has_variants' => false,
                    'color' => Arr::random(['Black', 'White', 'Navy', 'Forest Green', 'Charcoal', 'Beige']),
                ]);

                StockLocation::firstOrCreate(
                    ['product_id' => $product->id, 'product_variant_id' => null, 'locationable_type' => Warehouse::class, 'locationable_id' => $warehouse->id],
                    ['quantity' => $quantity * 10, 'min_quantity' => $quantity * 2, 'business_id' => $business->id]
                );

                $this->attachImage($product, $totalProducts);

                $totalProducts++;
            }
        }

        return $totalProducts;
    }

    protected function seedCategories(Store $store): array
    {
        $categories = [];
        foreach ($this->categoryNames as $name) {
            $slug = Str::slug($name);
            $cat = Category::firstOrCreate(
                ['store_id' => $store->id, 'slug' => $slug],
                ['name' => $name, 'status' => 'active', 'business_id' => $store->business_id]
            );
            $categories[] = $cat;
        }
        return $categories;
    }

    protected function seedSections(Warehouse $warehouse, Business $business): \Illuminate\Support\Collection
    {
        $sections = collect();
        $existingCount = Section::where('warehouse_id', $warehouse->id)->count();

        if ($existingCount >= count($this->sectionNames)) {
            $this->line("  Warehouse [{$warehouse->name}] already has {$existingCount} sections. Skipping section creation.");
            return Section::where('warehouse_id', $warehouse->id)->get();
        }

        foreach ($this->sectionNames as $i => $name) {
            $sec = Section::firstOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'name' => $name,
                ],
                [
                    'business_id' => $business->id,
                    'section_code' => 'sec_' . Str::lower(Str::random(10)),
                    'description' => $this->sectionDescriptions()[$i] ?? null,
                    'is_active' => true,
                ]
            );
            $sections->push($sec);
        }

        $this->info("  Warehouse [{$warehouse->name}]: {$sections->count()} sections created.");
        return $sections;
    }

    protected function line(string $message): void
    {
        if ($this->command) $this->command->line($message); else echo $message . PHP_EOL;
    }

    protected function warn(string $message): void
    {
        if ($this->command) $this->command->warn($message); else echo $message . PHP_EOL;
    }

    protected function info(string $message): void
    {
        if ($this->command) $this->command->info($message); else echo $message . PHP_EOL;
    }

    protected function sectionDescriptions(): array
    {
        return [
            'Premium paints, inks, and pigments stored in climate-controlled cabinets.',
            'Stretched canvases, panels, and rolled fabric in vertical storage racks.',
            'Temperature and humidity regulated zone for fine art papers and prints.',
            'Bulk clay, plaster, and heavy sculpting materials stored on pallet racks.',
            'Picked, packed, and dispatched art supply orders in this zone.',
            'Seasonal art kits, gift sets, and promotional bundles temporarily stored.',
            'High-value pigments, gold leaf, and rare materials in secure locked storage.',
        ];
    }

    public static function wipe($warehouseId): void
    {
        $warehouse = is_numeric($warehouseId)
            ? Warehouse::find($warehouseId)
            : Warehouse::where('warehouse_code', $warehouseId)->first();

        if (!$warehouse) {
            echo "Warehouse '{$warehouseId}' not found." . PHP_EOL;
            return;
        }

        $productCount = Product::where('warehouse_id', $warehouse->id)->delete();
        $sectionCount = Section::where('warehouse_id', $warehouse->id)->delete();
        $stockCount = StockLocation::where('locationable_type', Warehouse::class)
            ->where('locationable_id', $warehouse->id)->delete();

        echo "Wiped warehouse [{$warehouse->name}]: {$productCount} products, {$sectionCount} sections, {$stockCount} stock locations." . PHP_EOL;
    }

    protected function attachImage(Product $product, int $seed): void
    {
        $sourceDir = base_path('.temp_products');
        if (!is_dir($sourceDir)) {
            return;
        }

        $files = glob($sourceDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
        if (empty($files)) {
            return;
        }

        try {
            \Storage::disk('public')->makeDirectory('products/seeds');

            $sourcePath = $files[$seed % count($files)];
            $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
            $filename = 'products/seeds/' . $product->product_code . '.' . $ext;

            \Storage::disk('public')->put($filename, file_get_contents($sourcePath));

            \App\Models\ProductImage::create([
                'product_id' => $product->id,
                'business_id' => $product->business_id,
                'path' => $filename,
                'is_primary' => true,
                'position' => 0,
            ]);
        } catch (\Throwable $e) {
            // Silently skip — seed images are optional
        }
    }
}
