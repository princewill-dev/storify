<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'delivery_address_id')) {
                $table->foreignId('delivery_address_id')
                    ->after('vendor_id')
                    ->nullable()
                    ->constrained('delivery_addresses')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('orders', 'delivery_route_id')) {
                $table->foreignId('delivery_route_id')
                    ->after('delivery_address_id')
                    ->nullable()
                    ->constrained('delivery_routes')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('orders', 'delivery_state')) {
                $table->string('delivery_state')->nullable()->after('delivery_route_id');
            }

            if (!Schema::hasColumn('orders', 'delivery_area')) {
                $table->string('delivery_area')->nullable()->after('delivery_state');
            }

            if (!Schema::hasColumn('orders', 'delivery_days')) {
                $table->unsignedSmallInteger('delivery_days')->nullable()->after('delivery_area');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'delivery_address_id')) {
                $table->dropConstrainedForeignId('delivery_address_id');
            }

            if (Schema::hasColumn('orders', 'delivery_route_id')) {
                $table->dropConstrainedForeignId('delivery_route_id');
            }

            $columns = collect(['delivery_state', 'delivery_area', 'delivery_days'])
                ->filter(fn ($column) => Schema::hasColumn('orders', $column))
                ->values()
                ->all();

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
