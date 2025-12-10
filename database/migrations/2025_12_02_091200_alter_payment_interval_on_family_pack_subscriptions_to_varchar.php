<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change enum('6_months','12_months') to VARCHAR(32) to support values like 'weekly' and 'monthly'
        DB::statement("ALTER TABLE family_pack_subscriptions MODIFY COLUMN payment_interval VARCHAR(32) NOT NULL");
    }

    public function down(): void
    {
        // Revert back to enum if needed
        DB::statement("ALTER TABLE family_pack_subscriptions MODIFY COLUMN payment_interval ENUM('6_months','12_months') NOT NULL");
    }
};
