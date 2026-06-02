<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('pos_enabled')->default(false)->after('payment_mode');
            $table->text('physical_address')->nullable()->after('pos_enabled');
            $table->string('store_type')->default('online')->after('physical_address');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['pos_enabled', 'physical_address', 'store_type']);
        });
    }
};
