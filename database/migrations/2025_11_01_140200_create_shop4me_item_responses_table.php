<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop4me_item_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop4me_item_id')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('type')->nullable(); // issue, suggestion, substitution, unavailable, price-change
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop4me_item_responses');
    }
};
