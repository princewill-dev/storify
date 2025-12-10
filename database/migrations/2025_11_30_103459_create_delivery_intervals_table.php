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
        Schema::create('delivery_intervals', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Weekly", "Monthly"
            $table->string('slug')->unique(); // e.g., "weekly", "monthly"
            $table->integer('days_count'); // Number of days in interval
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_intervals');
    }
};
