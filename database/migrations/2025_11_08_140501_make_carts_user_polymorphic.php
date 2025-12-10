<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure the foreign key does not exist before dropping it (it may have been removed by a prior migration)
        $constraintName = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'carts')
            ->where('COLUMN_NAME', 'user_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->value('CONSTRAINT_NAME');

        Schema::table('carts', function (Blueprint $table) use ($constraintName) {
            if ($constraintName) {
                $table->dropForeign($constraintName);
            }

            // Add user_type column for polymorphic relationship
            if (!Schema::hasColumn('carts', 'user_type')) {
                $table->string('user_type')->nullable()->after('user_id');
            }
        });
        
        // Update existing records to set user_type
        DB::table('carts')
            ->whereNotNull('user_id')
            ->update(['user_type' => 'App\\Models\\Customer']); // Assume existing are customers
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('user_type');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }
};
