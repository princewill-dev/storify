<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('gateway');
            $table->text('public_key');
            $table->text('secret_key');
            $table->string('webhook_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'gateway']);
            $table->index(['business_id', 'gateway', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_gateways');
    }
};
