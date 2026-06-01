<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // === STEP 1: Add vendor columns to users ===
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'slug')) $table->string('slug')->nullable()->unique()->after('name');
            if (!Schema::hasColumn('users', 'description')) $table->text('description')->nullable()->after('location');
            if (!Schema::hasColumn('users', 'ownership_type_id')) $table->foreignId('ownership_type_id')->nullable()->after('description')->constrained('ownership_types')->nullOnDelete();
            if (!Schema::hasColumn('users', 'business_type_id')) $table->foreignId('business_type_id')->nullable()->after('ownership_type_id')->constrained('business_types')->nullOnDelete();
            if (!Schema::hasColumn('users', 'business_setup_complete')) $table->boolean('business_setup_complete')->default(false)->after('business_type_id');
            if (!Schema::hasColumn('users', 'business_location')) $table->string('business_location')->nullable()->after('business_setup_complete');
            if (!Schema::hasColumn('users', 'business_model')) $table->string('business_model', 20)->nullable()->after('business_location');
            if (!Schema::hasColumn('users', 'currency')) $table->string('currency', 10)->nullable()->after('business_model');
            if (!Schema::hasColumn('users', 'physical_store_count')) $table->string('physical_store_count', 10)->nullable()->after('currency');
            if (!Schema::hasColumn('users', 'store_slug')) $table->string('store_slug')->nullable()->after('physical_store_count');
            if (!Schema::hasColumn('users', 'account_code') && !Schema::hasColumn('users', 'user_code')) $table->string('account_code', 30)->nullable()->unique()->after('id');
        });

        // === STEP 2: Copy vendor data into users ===
        $this->dropSlugUniqueTemporarily();

        $vendors = DB::table('vendors')->get();
        foreach ($vendors as $v) {
            $userId = $v->user_id;
            if (!$userId || !DB::table('users')->where('id', $userId)->exists()) continue;

            $slug = $this->uniqueSlug($v->slug ?? Str::slug($v->name));

            DB::table('users')->where('id', $userId)->update([
                'slug' => $slug,
                'description' => $v->description ?? null,
                'ownership_type_id' => $v->ownership_type_id ?? null,
                'business_type_id' => $v->business_type_id ?? null,
                'business_setup_complete' => $v->business_setup_complete ?? false,
                'business_location' => $v->business_location ?? null,
                'business_model' => $v->business_model ?? null,
                'currency' => $v->currency ?? null,
                'physical_store_count' => $v->physical_store_count ?? null,
                'store_slug' => $v->store_slug ?? null,
                'account_code' => $v->account_id ?? null,
                'is_verified' => $v->is_verified ?? false,
                'status' => $v->status ?? 'active',
                'phone' => $v->phone ?? null,
                'ip_address' => $v->ip_address ?? null,
                'updated_at' => now(),
            ]);
        }

        $this->restoreSlugUnique();

        // === STEP 3: Migrate FK: vendor_id → user_id on all tables ===
        $fkMigrations = [
            ['table' => 'stores', 'constraint' => 'stores_vendor_id_foreign'],
            ['table' => 'orders', 'constraint' => 'orders_vendor_id_to_vendors_foreign'],
            ['table' => 'vendor_kyc_applications', 'constraint' => 'vendor_kyc_applications_vendor_id_foreign'],
            ['table' => 'vendor_subscriptions', 'constraint' => 'vendor_subscriptions_vendor_id_foreign'],
            ['table' => 'payments', 'constraint' => 'payments_vendor_id_foreign'],
            ['table' => 'early_pass_usages', 'constraint' => 'early_pass_usages_vendor_id_foreign'],
            ['table' => 'staff', 'constraint' => 'staff_vendor_id_foreign'],
            ['table' => 'roles', 'constraint' => 'roles_vendor_id_foreign'],
            ['table' => 'warehouses', 'constraint' => 'warehouses_vendor_id_foreign'],
            ['table' => 'locations', 'constraint' => 'locations_vendor_id_foreign'],
        ];

        foreach ($fkMigrations as $m) {
            $table = $m['table'];
            $constraint = $m['constraint'];

            // Drop old FK
            try { DB::statement("ALTER TABLE `$table` DROP FOREIGN KEY `$constraint`"); } catch (\Throwable $e) {}

            // Rename column
            if (Schema::hasColumn($table, 'vendor_id')) {
                DB::statement("ALTER TABLE `$table` CHANGE `vendor_id` `user_id` BIGINT UNSIGNED NULL");
            }
        }

        // === STEP 4: Recreate FKs pointing to users ===
        $newFks = [
            'stores' => 'user_id',
            'orders' => 'user_id',
            'vendor_kyc_applications' => 'user_id',
            'vendor_subscriptions' => 'user_id',
            'payments' => 'user_id',
            'early_pass_usages' => 'user_id',
            'staff' => 'user_id',
            'roles' => 'user_id',
            'warehouses' => 'user_id',
            'locations' => 'user_id',
        ];

        foreach ($newFks as $table => $column) {
            try {
                Schema::table($table, function (Blueprint $table) use ($column) {
                    $table->foreign($column)->references('id')->on('users')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {}
        }

        // === STEP 5: Rename tables ===
        if (Schema::hasTable('vendor_kyc_applications') && !Schema::hasTable('kyc_applications')) {
            Schema::rename('vendor_kyc_applications', 'kyc_applications');
        }
        if (Schema::hasTable('vendor_subscriptions') && !Schema::hasTable('subscriptions')) {
            Schema::rename('vendor_subscriptions', 'subscriptions');
        }

        // === STEP 6: Update orders FK (already done, but fix any constraint name) ===
        try {
            DB::statement("ALTER TABLE `orders` DROP FOREIGN KEY IF EXISTS `orders_user_id_to_vendors_foreign`");
        } catch (\Throwable $e) {}

        // === STEP 7: Drop old trigger/index for vendor_id on subscriptions if exists ===
        try { DB::statement('DROP INDEX IF EXISTS vendor_subscriptions_vendor_id_status_index ON vendor_subscriptions'); } catch (\Throwable $e) {}
        try { DB::statement('DROP INDEX IF EXISTS vendor_subscriptions_vendor_id_status_index ON subscriptions'); } catch (\Throwable $e) {}
        try { DB::statement('DROP INDEX IF EXISTS payments_vendor_id_status_index ON payments'); } catch (\Throwable $e) {}

        try {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index(['user_id', 'status']);
            });
        } catch (\Throwable $e) {}

        try {
            Schema::table('payments', function (Blueprint $table) {
                $table->index(['user_id', 'status']);
            });
        } catch (\Throwable $e) {}

        // === STEP 8: Drop vendors table ===
        // (Commented out for safety — uncomment after verifying data migration)
        // Schema::dropIfExists('vendors');
    }

    public function down(): void
    {
        // This is a one-way migration. Restore from backup if needed.
    }

    protected function dropSlugUniqueTemporarily(): void
    {
        try { DB::statement('ALTER TABLE users DROP INDEX users_slug_unique'); } catch (\Throwable $e) {}
    }

    protected function restoreSlugUnique(): void
    {
        try { DB::statement('ALTER TABLE users ADD UNIQUE users_slug_unique (slug)'); } catch (\Throwable $e) {}
    }

    protected function uniqueSlug(string $slug): string
    {
        $original = $slug;
        $counter = 1;
        while (DB::table('users')->where('slug', $slug)->exists()) {
            $slug = $original . '-' . $counter++;
        }
        return $slug;
    }
};
