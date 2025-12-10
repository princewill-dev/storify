<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return string[]
     */
    private function getVendorForeignConstraintNames(): array
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $records = $connection->select(
            'SELECT constraint_name FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$database, 'orders', 'vendor_id']
        );

        return array_values(array_filter(array_map(static fn ($row) => $row->constraint_name ?? null, $records)));
    }

    public function up(): void
    {
        $constraints = $this->getVendorForeignConstraintNames();

        Schema::table('orders', function (Blueprint $table) use ($constraints) {
            foreach ($constraints as $constraint) {
                $table->dropForeign($constraint);
            }

            $table->foreign('vendor_id', 'orders_vendor_id_to_vendors_foreign')
                ->references('id')
                ->on('vendors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $constraints = $this->getVendorForeignConstraintNames();

        Schema::table('orders', function (Blueprint $table) use ($constraints) {
            foreach ($constraints as $constraint) {
                $table->dropForeign($constraint);
            }

            $table->foreign('vendor_id', 'orders_vendor_id_to_users_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
