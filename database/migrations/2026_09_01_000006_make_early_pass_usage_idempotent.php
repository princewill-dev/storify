<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('early_pass_usages', function (Blueprint $table) {
            $table->unique(['early_pass_id', 'user_id'], 'early_pass_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('early_pass_usages', function (Blueprint $table) {
            $table->dropUnique('early_pass_user_unique');
        });
    }
};
