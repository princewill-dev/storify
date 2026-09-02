<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_vendor_subscription_id_foreign');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('vendor_subscription_id', 'subscription_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('subscription_id', 'vendor_subscription_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('vendor_subscription_id')
                ->references('id')
                ->on('subscriptions')
                ->nullOnDelete();
        });
    }
};
