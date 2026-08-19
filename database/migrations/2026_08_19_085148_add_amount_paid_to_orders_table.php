<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total');
        });

        DB::statement("
            UPDATE orders o
            SET o.amount_paid = o.total
            WHERE EXISTS (
                SELECT 1 FROM transactions t
                WHERE t.order_id = o.id
                  AND t.status = 'confirmed'
            )
            AND o.amount_paid = 0
        ");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
