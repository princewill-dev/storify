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
        Schema::create('bulk_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained();
            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->boolean('is_custom')->default(false);
            $table->decimal('budgeted_amount', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_order_items');
    }
};
