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
        Schema::create('store_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_code', 20);
            $table->string('account_number', 20);
            $table->string('account_name');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(true);
            $table->timestamps();
            
            $table->index(['store_id', 'is_primary']);
            $table->unique(['store_id', 'account_number', 'bank_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_banks');
    }
};
