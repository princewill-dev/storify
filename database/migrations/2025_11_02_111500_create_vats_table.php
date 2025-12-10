<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vats', function (Blueprint $table) {
            $table->id();
            $table->decimal('percentage', 5, 2); // e.g., 7.50
            $table->boolean('active')->default(true);
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vats');
    }
};
