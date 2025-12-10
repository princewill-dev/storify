<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('product_code')->unique();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['store_id','slug']);
            $table->index(['store_id','category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
