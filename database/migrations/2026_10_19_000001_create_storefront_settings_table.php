<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-store presentation settings for a storefront.
 *
 * Kept beside `stores` rather than on it for the same reason the storefront
 * itself is a separate surface: these are the fields the public site reads, and
 * the table is where a future template/theme switch will live. `template_key`
 * is stored now but has no writer — a later template picker adds values, not a
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignId('store_id')
                ->unique()
                ->constrained('stores')
                ->cascadeOnDelete();

            // Nullable throughout: the effective value falls back to the
            // store's own name, description and logo, so clearing a field is
            // the way to say "use what the store already says".
            $table->string('site_title', 255)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('og_image_path', 2048)->nullable();

            $table->string('template_key', 64)->default('default');

            $table->timestamps();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_settings');
    }
};
