<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('packs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['pack_id','product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_items');
    }
};
