<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->bigInteger('quantity_change');
            $table->string('movement_type'); // in, out, adjust
            $table->string('reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamps();
            $table->index(['store_id','product_id']);
            $table->unique(['store_id','idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
