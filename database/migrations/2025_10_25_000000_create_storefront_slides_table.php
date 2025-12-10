<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storefront_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price_override', 12, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('status', 50)->default('active');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('storefront_slides');
    }
};
