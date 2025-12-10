<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('company_logo_path')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->text('company_address')->nullable();
            $table->text('branch_address')->nullable();
            $table->json('api_keys')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
