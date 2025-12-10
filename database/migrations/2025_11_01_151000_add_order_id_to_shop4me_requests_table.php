<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop4me_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->after('delivery_address_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('shop4me_requests', function (Blueprint $table) {
            $table->dropColumn('order_id');
        });
    }
};
