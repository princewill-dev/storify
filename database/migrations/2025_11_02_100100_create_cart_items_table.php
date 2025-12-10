<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key', 100)->nullable();
            $table->string('name')->nullable(); // snapshot
            $table->unsignedBigInteger('unit_amount'); // kobo
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('line_subtotal'); // unit_amount * qty
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['cart_id','product_id','variant_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
