<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the per-store payment assignment the same two things the Plugins
 * override table has: a tenant anchor, and a place to hold a store's own
 * credentials when it does not use the business's.
 *
 * `business_id` is denormalised from `stores` so a tenant-scoped query does not
 * need a join. It is added nullable and backfilled, so no in-flight checkout
 * breaks while the migration runs.
 *
 * Note what is NOT added: an `api_keys` column. Old code read
 * `$gateway->pivot->api_keys`, which never existed, and the temptation is to
 * add it to make that read work. That would be the wrong fix — it would create
 * a second, divergent place for secrets. Credentials live in `config`, resolved
 * through PaymentGatewayResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_payment_method', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('store_id')
                ->constrained('businesses')->cascadeOnDelete();
            $table->json('config')->nullable()->after('is_active');
        });

        // Backfill from the owning store. Chunked because a marketplace-sized
        // stores table should not be loaded in one go.
        DB::table('stores')->select('id', 'business_id')->orderBy('id')->chunk(500, function ($stores) {
            foreach ($stores as $store) {
                DB::table('store_payment_method')
                    ->where('store_id', $store->id)
                    ->whereNull('business_id')
                    ->update(['business_id' => $store->business_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_payment_method', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn('config');
        });
    }
};
