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
        Schema::table('bulk_orders', function (Blueprint $table) {
            $table->timestamp('customer_accepted_at')->nullable()->after('reviewed_at');
            $table->enum('last_updated_by', ['admin', 'customer'])->nullable()->after('customer_accepted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulk_orders', function (Blueprint $table) {
            $table->dropColumn(['customer_accepted_at', 'last_updated_by']);
        });
    }
};
