<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop4me_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop4me_request_id')->index();
            // Catalog references (optional, for mixed basket)
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('product_variant_id')->nullable()->index();

            // Custom entry fallback
            $table->string('name')->nullable();
            $table->decimal('qty', 12, 3)->default(1);
            $table->string('unit_hint')->nullable();
            $table->decimal('amount_hint', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('allow_substitute')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop4me_items');
    }
};
