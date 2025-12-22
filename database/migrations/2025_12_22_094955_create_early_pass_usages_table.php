<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('early_pass_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('early_pass_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();
        });

        // Migrate existing data
        $usedPasses = DB::table('early_passes')->where('is_used', true)->get();
        foreach ($usedPasses as $pass) {
            if (!empty($pass->used_by_vendor_id)) {
                $store = DB::table('stores')->where('vendor_id', $pass->used_by_vendor_id)->first();
                
                DB::table('early_pass_usages')->insert([
                    'early_pass_id' => $pass->id,
                    'vendor_id' => $pass->used_by_vendor_id,
                    'store_id' => $store?->id,
                    'used_at' => $pass->used_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('early_passes', function (Blueprint $table) {
            $table->dropForeign(['used_by_vendor_id']); 
            $table->dropColumn(['is_used', 'used_by_vendor_id', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('early_passes', function (Blueprint $table) {
            $table->boolean('is_used')->default(false);
            $table->foreignId('used_by_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->index('is_used');
        });

        if (Schema::hasTable('early_pass_usages')) {
             $usages = DB::table('early_pass_usages')->orderBy('used_at')->get();
             foreach ($usages as $usage) {
                 DB::table('early_passes')->where('id', $usage->early_pass_id)->update([
                     'is_used' => true,
                     'used_by_vendor_id' => $usage->vendor_id,
                     'used_at' => $usage->used_at,
                 ]);
             }
        }

        Schema::dropIfExists('early_pass_usages');
    }
};
