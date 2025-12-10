<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'phone')) {
                return;
            }

            $table->string('phone')->nullable()->change();
            $table->unique('phone', 'vendors_phone_unique');
        });

        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'support_email') || ! Schema::hasColumn('stores', 'support_phone')) {
                return;
            }

            $table->string('support_email')->nullable()->change();
            $table->string('support_phone')->nullable()->change();
            $table->unique('support_email', 'stores_support_email_unique');
            $table->unique('support_phone', 'stores_support_phone_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'phone')) {
                $table->dropUnique('vendors_phone_unique');
            }
        });

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'support_email')) {
                $table->dropUnique('stores_support_email_unique');
            }
            if (Schema::hasColumn('stores', 'support_phone')) {
                $table->dropUnique('stores_support_phone_unique');
            }
        });
    }
};
