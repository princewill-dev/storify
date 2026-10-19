<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-written storefront pages — About, Terms, Privacy, and anything else.
 *
 * About/Terms/Privacy are not seeded and carry no special flag: the management
 * screen offers them as starters, and once created they are ordinary pages an
 * owner may rename or delete. That keeps one code path for every page and lets
 * a shop that does not want a Terms page simply not have one.
 *
 * `content` is stored after HTMLPurifier has run (see
 * App\Support\Storefront\StorefrontHtmlSanitizer). The public endpoints read
 * only published rows; `show_in_footer` narrows that further for the footer
 * navigation but does not gate direct access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_pages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->string('title', 160);
            $table->string('slug', 120);
            $table->longText('content')->nullable();

            $table->boolean('is_published')->default(false);
            $table->boolean('show_in_footer')->default(true);

            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'is_published']);
            $table->index(['store_id', 'is_published', 'show_in_footer']);
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_pages');
    }
};
