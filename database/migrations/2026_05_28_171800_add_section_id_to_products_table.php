<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('store_id')->constrained('sections')->nullOnDelete();
        });

        // Seed default location + section for existing data
        $this->seedDefaults();
    }

    protected function seedDefaults(): void
    {
        $vendors = DB::table('vendors')->get();

        foreach ($vendors as $vendor) {
            // Create default location if vendor has warehouses but no locations
            $hasWarehouses = DB::table('warehouses')->where('vendor_id', $vendor->id)->exists();
            if (!$hasWarehouses) continue;

            $locationId = DB::table('locations')->insertGetId([
                'location_code' => 'loc_' . Str::lower(Str::random(10)),
                'vendor_id' => $vendor->id,
                'name' => 'Main Location',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Assign warehouses to this location
            $warehouses = DB::table('warehouses')->where('vendor_id', $vendor->id)->whereNull('location_id')->get();
            foreach ($warehouses as $wh) {
                DB::table('warehouses')->where('id', $wh->id)->update(['location_id' => $locationId]);

                // Create default section for each warehouse
                $sectionId = DB::table('sections')->insertGetId([
                    'section_code' => 'sec_' . Str::lower(Str::random(10)),
                    'warehouse_id' => $wh->id,
                    'name' => 'General',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Assign products in this warehouse's stores to the default section
                $storeIds = DB::table('stores')->where('vendor_id', $vendor->id)->pluck('id');
                DB::table('products')->whereIn('store_id', $storeIds)->whereNull('section_id')
                    ->update(['section_id' => $sectionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });
    }
};
