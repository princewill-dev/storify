<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop4me_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop4me_request_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('type'); // status_changed, item_response, note
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop4me_events');
    }
};
