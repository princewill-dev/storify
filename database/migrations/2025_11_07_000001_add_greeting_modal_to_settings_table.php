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
        Schema::table('settings', function (Blueprint $table) {
            $table->text('company_description')->nullable()->after('company_name');
            $table->boolean('greeting_modal_enabled')->default(false)->after('og_type');
            $table->string('greeting_modal_frequency')->default('never')->after('greeting_modal_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['company_description', 'greeting_modal_enabled', 'greeting_modal_frequency']);
        });
    }
};
