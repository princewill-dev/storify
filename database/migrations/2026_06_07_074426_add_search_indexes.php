<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('products', 'name');
        $this->addIndex('products', 'product_code');
        $this->addIndex('stores', 'name');
        $this->addIndex('warehouses', 'name');
        $this->addIndex('customers', 'first_name');
        $this->addIndex('customers', 'last_name');
        $this->addIndex('transactions', 'reference');
        $this->addIndex('users', 'name');
        $this->addIndex('users', 'phone');
    }

    public function down(): void
    {
        $this->dropIndex('products', 'name');
        $this->dropIndex('products', 'product_code');
        $this->dropIndex('stores', 'name');
        $this->dropIndex('warehouses', 'name');
        $this->dropIndex('customers', 'first_name');
        $this->dropIndex('customers', 'last_name');
        $this->dropIndex('transactions', 'reference');
        $this->dropIndex('users', 'name');
        $this->dropIndex('users', 'phone');
    }

    private function addIndex(string $table, string $column): void
    {
        $indexName = $table . '_' . $column . '_index';
        $exists = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        );
        if (!$exists || $exists->cnt == 0) {
            Schema::table($table, fn(Blueprint $t) => $t->index($column));
        }
    }

    private function dropIndex(string $table, string $column): void
    {
        $indexName = $table . '_' . $column . '_index';
        $exists = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        );
        if ($exists && $exists->cnt > 0) {
            Schema::table($table, fn(Blueprint $t) => $t->dropIndex([$column]));
        }
    }
};
