<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('variant_code')->unique();
            $table->string('sku')->nullable()->index();
            $table->decimal('size', 10, 2)->nullable();
            $table->foreignId('size_unit_id')->nullable()->constrained('size_units');
            $table->decimal('weight', 10, 2)->nullable();
            $table->foreignId('weight_unit_id')->nullable()->constrained('weight_units');
            $table->string('color', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 12, 2);
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('status')->default('active');
            $table->boolean('featured')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
