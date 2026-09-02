<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('pos_session_id');
            $table->unique(
                ['business_id', 'store_id', 'source', 'idempotency_key'],
                'orders_pos_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_pos_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
