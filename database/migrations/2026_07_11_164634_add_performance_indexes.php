<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('stock_locations', ['locationable_type', 'locationable_id']);
        $this->addIndex('stock_locations', ['product_id', 'locationable_type', 'locationable_id']);
        $this->addIndex('transactions', 'status');
        $this->addIndex('orders', ['user_id', 'store_id', 'created_at']);
        $this->addIndex('products', ['store_id', 'featured', 'status']);
        $this->addIndex('stores', ['status', 'has_website']);
    }

    public function down(): void
    {
        $this->dropIndex('stock_locations', ['locationable_type', 'locationable_id']);
        $this->dropIndex('stock_locations', ['product_id', 'locationable_type', 'locationable_id']);
        $this->dropIndex('transactions', 'status');
        $this->dropIndex('orders', ['user_id', 'store_id', 'created_at']);
        $this->dropIndex('products', ['store_id', 'featured', 'status']);
        $this->dropIndex('stores', ['status', 'has_website']);
    }

    private function addIndex(string $table, string|array $columns): void
    {
        $cols = (array) $columns;
        $indexName = $table . '_' . implode('_', $cols) . '_idx';
        $indexName = substr($indexName, 0, 64);
        $exists = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        );
        if (!$exists || $exists->cnt == 0) {
            Schema::table($table, fn(Blueprint $t) => $t->index($cols, $indexName));
        }
    }

    private function dropIndex(string $table, string|array $columns): void
    {
        $cols = (array) $columns;
        $indexName = $table . '_' . implode('_', $cols) . '_idx';
        $indexName = substr($indexName, 0, 64);
        $exists = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        );
        if ($exists && $exists->cnt > 0) {
            Schema::table($table, fn(Blueprint $t) => $t->dropIndex($indexName));
        }
    }
};
