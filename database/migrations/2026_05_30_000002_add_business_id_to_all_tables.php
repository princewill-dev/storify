<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'stores',
        'warehouses',
        'orders',
        'products',
        'transactions',
        'customers',
        'pos_sessions',
        'categories',
        'services',
        'coupons',
        'locations',
        'sections',
        'subscriptions',
        'payments',
        'kyc_applications',
        'staff_assignments',
        'staff_documents',
        'stock_locations',
        'delivery_routes',
        'inventory_movements',
        'packs',
        'product_images',
        'product_variants',
        'store_banks',
        'store_payment_gateways',
        'storefront_slides',
        'bulk_orders',
        'bulk_order_items',
        'family_packs',
        'family_pack_members',
        'family_pack_items',
        'shop4me_requests',
        'shop4me_events',
        'early_pass_usages',
        'live_first_applications',
        'activity_logs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->foreignId('business_id')->after('id')->nullable()->constrained('businesses')->cascadeOnDelete();
                });
            }
        }

        $this->modifyUsersTable();
        $this->addBusinessIdToSpatieTables();
    }

    private function modifyUsersTable(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->nullOnDelete();
            }
        });

        $foreignKeys = [
            'ownership_type_id' => 'users_ownership_type_id_foreign',
            'business_type_id' => 'users_business_type_id_foreign',
        ];

        foreach ($foreignKeys as $column => $fkName) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($fkName, $column) {
                    $table->dropForeign($fkName);
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasColumn('users', 'slug')) {
            try { Schema::table('users', fn($t) => $t->dropUnique('users_slug_unique')); } catch (\Exception $e) {}
            try { Schema::table('users', fn($t) => $t->dropUnique('slug')); } catch (\Exception $e) {}
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }

        $simpleColumns = ['description', 'business_setup_complete', 'business_location',
            'business_model', 'currency', 'physical_store_count', 'store_slug'];

        foreach ($simpleColumns as $col) {
            if (Schema::hasColumn('users', $col)) {
                Schema::table('users', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }

    private function addBusinessIdToSpatieTables(): void
    {
        $spatieTables = ['roles', 'model_has_permissions', 'model_has_roles'];

        foreach ($spatieTables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('business_id');
                });
            }
        }

        if (Schema::hasColumn('users', 'business_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('business_id');
            });
        }

        $spatieTables = ['roles', 'model_has_permissions', 'model_has_roles'];
        foreach ($spatieTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('business_id');
                });
            }
        }
    }
};
