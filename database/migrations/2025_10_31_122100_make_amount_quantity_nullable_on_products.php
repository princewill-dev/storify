<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable()->change();
            $table->decimal('amount', 12, 2)->nullable()->change();
            $table->foreignId('currency_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable(false)->change();
            $table->decimal('amount', 12, 2)->nullable(false)->change();
            $table->foreignId('currency_id')->nullable(false)->change();
        });
    }
};
