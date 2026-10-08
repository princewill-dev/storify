<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store plugin overrides.
 *
 * A row here wins over the business default for that store. Two tables rather
 * than one table with a nullable `store_id`: MySQL treats NULLs as distinct in
 * a unique index, so `unique(business_id, store_id, plugin_key)` would accept
 * the business-wide row more than once.
 *
 * A store row is also how a store *disables* something the business enabled —
 * the row's existence decides precedence, not its `is_enabled` flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('plugin_key', 64);
            $table->boolean('is_enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'plugin_key']);
            $table->index(['business_id', 'plugin_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_plugins');
    }
};
