<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->nullable()->after('amount');
            $table->unsignedBigInteger('average_cost_kobo')->nullable()->after('cost_price');
            $table->boolean('is_taxable')->default(true)->after('average_cost_kobo');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_kobo')->nullable()->after('subtotal');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('cost_kobo');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_rate');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_cost_kobo')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'average_cost_kobo', 'is_taxable']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cost_kobo', 'tax_rate', 'tax_amount']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost_kobo');
        });
    }
};
