<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Switch payment_interval columns from ENUM to VARCHAR to support weekly/monthly as well
        try {
            DB::statement("ALTER TABLE family_pack_orders MODIFY COLUMN payment_interval VARCHAR(32) NULL");
        } catch (\Throwable $e) {
            // ignore if already altered
        }
        try {
            DB::statement("ALTER TABLE family_pack_subscriptions MODIFY COLUMN payment_interval VARCHAR(32) NOT NULL");
        } catch (\Throwable $e) {
            // ignore if already altered
        }
    }

    public function down(): void
    {
        // Revert back to the original ENUM set
        try {
            DB::statement("ALTER TABLE family_pack_orders MODIFY COLUMN payment_interval ENUM('6_months','12_months') NULL");
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            DB::statement("ALTER TABLE family_pack_subscriptions MODIFY COLUMN payment_interval ENUM('6_months','12_months') NOT NULL");
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
