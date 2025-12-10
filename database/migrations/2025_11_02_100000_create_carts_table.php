<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('guest_token', 64)->nullable()->index();
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0); // kobo
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['store_id','user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
