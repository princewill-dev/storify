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
        Schema::create('delivery_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('Home'); // e.g., Home, Office, etc.
            $table->string('recipient_name'); // Can be different from customer name
            $table->string('recipient_phone');
            $table->string('company_name')->nullable();
            $table->string('country')->default('Nigeria');
            $table->string('state');
            $table->string('city');
            $table->text('street_address');
            $table->string('apartment')->nullable();
            $table->string('zip_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            
            // Indexes
            $table->index('customer_id');
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_addresses');
    }
};
