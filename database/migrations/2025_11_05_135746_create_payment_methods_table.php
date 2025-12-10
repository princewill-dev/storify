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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Bank Transfer", "Paystack", "Cash on Delivery"
            $table->string('code')->unique(); // e.g., "bank_transfer", "paystack", "cod"
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable(); // Store API keys, account details, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
