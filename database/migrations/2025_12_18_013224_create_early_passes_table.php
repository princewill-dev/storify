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
        Schema::create('early_passes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_used')->default(false);
            $table->foreignId('used_by_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            
            $table->index('code');
            $table->index('is_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('early_passes');
    }
};
