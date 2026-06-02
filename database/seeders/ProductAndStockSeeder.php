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
        'Electronics',
        'Fashion',
        'Home & Kitchen',
        'Beauty & Health',
        'Sports & Outdoors',
    ];

    protected array $productCatalog = [
        'Electronics' => [
            ['Wireless Bluetooth Headphones', 'SoundWave', 12500, 350, 120],
            ['USB-C Fast Charger 65W', 'PowerUp', 8000, 180, 80],
            ['Portable Bluetooth Speaker', 'SoundWave', 9500, 420, 200],
            ['Wireless Ergonomic Mouse', 'ClickPro', 4500, 95, 60],
            ['HDMI 4K Cable 2m', 'LinkFast', 2200, 50, 30],
            ['Laptop Cooling Stand', 'CoolTech', 6500, 750, 300],
            ['Webcam 1080p with Mic', 'ClearView', 11000, 210, 100],
            ['Mechanical Gaming Keyboard', 'TypeForce', 18500, 890, 400],
        ],
        'Fashion' => [
            ['Classic Leather Watch', 'TimeMark', 15000, 85, 40],
            ['Polarized Aviator Sunglasses', 'SunGear', 7500, 45, 25],
            ['Canvas Leather Backpack', 'CarryAll', 12000, 680, 300],
            ['Merino Wool Beanie', 'WarmCove', 3500, 60, 30],
            ['Minimalist Canvas Sneakers', 'StrideX', 9500, 520, 250],
            ['Stainless Steel Bracelet', 'Metaluxe', 5500, 28, 15],
            ['Waterproof Nylon Belt', 'HoldFast', 4200, 75, 40],
            ['Cotton Bucket Hat', 'ShadeCo', 2800, 55, 30],
        ],
        'Home & Kitchen' => [
            ['Stainless Steel French Press', 'BrewMate', 6500, 480, 220],
            ['Cast Iron Skillet 12-inch', 'IronChef', 11000, 2100, 900],
            ['Bamboo Cutting Board Set', 'EcoPrep', 4500, 380, 180],
            ['Glass Meal Prep Containers 5-Pack', 'FreshKeep', 8000, 750, 350],
            ['Silicone Spatula Set of 4', 'FlexCook', 2800, 110, 55],
            ['Digital Kitchen Scale', 'PreciseMeasure', 5200, 230, 100],
            ['Airtight Coffee Canister', 'BrewGuard', 3500, 280, 130],
            ['Non-Stick Baking Mat', 'BakePro', 2200, 65, 35],
        ],
        'Beauty & Health' => [
            ['Vitamin C Serum 30ml', 'GlowUp', 8500, 38, 20],
            ['Bamboo Hair Brush Set', 'NaturalTouch', 4800, 72, 35],
            ['Hypoallergenic Face Moisturiser', 'PureSkin', 6200, 95, 45],
            ['Organic Lip Balm 3-Pack', 'KissEco', 1800, 22, 12],
            ['Natural Deodorant Stick', 'FreshDay', 2500, 50, 25],
            ['Collagen Peptide Powder 500g', 'FlexBody', 13500, 340, 160],
            ['Reusable Makeup Remover Pads', 'CleanFace', 1500, 18, 10],
            ['Essential Oil Diffuser', 'AromaZen', 9800, 420, 200],
        ],
        'Sports & Outdoors' => [
            ['Insulated Water Bottle 1L', 'HydroKeep', 4500, 310, 140],
            ['Quick-Dry Gym Towel', 'DryFit', 2200, 85, 40],
            ['Adjustable Resistance Bands', 'PowerFlex', 3800, 160, 75],
            ['Yoga Mat Non-Slip 6mm', 'BalancePro', 7500, 900, 400],
            ['Compression Running Socks', 'SpeedStride', 2800, 40, 20],
            ['Collapsible Camping Chair', 'TrekKing', 15000, 3200, 1400],
            ['LED Headlamp Rechargeable', 'TrailLight', 5500, 95, 45],
            ['First Aid Kit Ultra-Compact', 'SafeStep', 3200, 180, 85],
        ],
    ];

    protected array $sectionNames = [
        'Aisle A — Electronics & Gadgets',
        'Aisle B — Bulk Storage',
        'Cold Storage — Perishables',
        'Receiving Bay — Inbound',
        'Picking Zone — Outbound',
        'Overflow — Seasonal Items',
        'High-Value Cage — Secure Storage',
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
        if ($stores->isEmpty()) {
            $this->warn("No stores found for business [{$business->name}]. Skipping.");
            return;
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

                    $totalProducts++;
                    $totalStock += $quantity;
                }
            }

            $this->info("  Store [{$store->name}]: {$totalProducts} products seeded.");
        }

        $this->info("Done: {$totalProducts} products across " . $stores->count() . " stores, warehouse stock created for [{$warehouse?->name}].");
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
            'High-value electronics and gadgets stored in locked cabinets.',
            'Pallet racks for bulk inventory and oversized items.',
            'Temperature-controlled zone for perishable goods.',
            'Incoming shipments are staged here for quality checks and counting.',
            'Orders are picked and packed in this zone before dispatch.',
            'Excess stock and seasonal items temporarily stored here.',
            'Restricted-access cage for expensive items and sensitive inventory.',
        ];
    }
}
