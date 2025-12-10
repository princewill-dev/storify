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
        Schema::create('page_stylings', function (Blueprint $table) {
            $table->id();
            $table->string('page_name')->unique(); // e.g., 'product_details', 'home', 'checkout'
            $table->string('page_label'); // Human-readable name
            $table->string('background_color')->nullable(); // Hex color code
            $table->text('custom_css')->nullable(); // Additional custom CSS
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_stylings');
    }
};
