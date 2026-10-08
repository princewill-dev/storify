<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-wide plugin defaults.
 *
 * The counterpart to `store_plugins` below, and the same two-layer shape
 * payments already uses (`business_payment_method` for the business,
 * `store_payment_gateways` for a store). A `store_plugins` row overrides the
 * row here; with no store row, a store inherits whatever is set here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            // A key from App\Support\Plugins\PluginRegistry — not a foreign key,
            // because plugins are code rather than rows.
            $table->string('plugin_key', 64);
            $table->boolean('is_enabled')->default(true);
            // Field values keyed by the plugin's own field schema.
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'plugin_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_plugins');
    }
};
