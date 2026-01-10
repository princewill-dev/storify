<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('balance_updated_at')->nullable()->after('paid_at');
            $table->unsignedBigInteger('store_balance_before')->nullable()->after('balance_updated_at')->comment('Store balance before this transaction in kobo');
            $table->unsignedBigInteger('store_balance_after')->nullable()->after('store_balance_before')->comment('Store balance after this transaction in kobo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['balance_updated_at', 'store_balance_before', 'store_balance_after']);
        });
    }
};
