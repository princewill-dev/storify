<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_payment_method')) {
            // Table already created in previous partial run — just mark as migrated
            return;
        }
        Schema::create('store_payment_method', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'payment_method_id']);
        });

        // Migrate existing StorePaymentGateway records (Paystack) — only if old table still exists
        $paystackId = DB::table('payment_methods')->where('code', 'paystack')->value('id');
        if (Schema::hasTable('store_payment_gateways')) {
            $storeGateways = DB::table('store_payment_gateways')->where('gateway', 'paystack')->where('is_active', true)->get();
            foreach ($storeGateways as $sg) {
                DB::table('store_payment_method')->insertOrIgnore([
                    'store_id' => $sg->store_id, 'payment_method_id' => $paystackId,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Migrate store_bank pivot (Bank Transfer) — only if that table still exists
        $bankTransferId = DB::table('payment_methods')->where('code', 'bank_transfer')->value('id');
        if (Schema::hasTable('store_bank')) {
            foreach (DB::table('store_bank')->get() as $p) {
                DB::table('store_payment_method')->insertOrIgnore([
                    'store_id' => $p->store_id, 'payment_method_id' => $bankTransferId,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_method');
    }
};
