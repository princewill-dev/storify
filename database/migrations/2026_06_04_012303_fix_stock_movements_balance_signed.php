<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements MODIFY balance_before BIGINT NULL');
        DB::statement('ALTER TABLE stock_movements MODIFY balance_after BIGINT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements MODIFY balance_before BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE stock_movements MODIFY balance_after BIGINT UNSIGNED NULL');
    }
};
