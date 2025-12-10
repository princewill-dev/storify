<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop4me_requests', function (Blueprint $table) {
            $table->string('payment_status')->default('unpaid')->after('status')->index();
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            $table->string('payment_method')->nullable()->after('paid_at');
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->decimal('payment_amount', 12, 2)->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('shop4me_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_status','paid_at','payment_method','payment_reference','payment_amount']);
        });
    }
};
