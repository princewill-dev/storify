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
        Schema::create('family_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_pack_order_id')->constrained('family_pack_orders')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('set null');
            
            // Product details
            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2)->nullable(); // Null for custom items initially
            $table->decimal('subtotal', 10, 2)->default(0);
            
            // Custom item fields
            $table->boolean('is_custom')->default(false);
            $table->decimal('budgeted_amount', 10, 2)->nullable(); // Customer's estimated amount for custom items
            
            $table->timestamps();
            
            // Indexes
            $table->index('family_pack_order_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_pack_items');
    }
};
