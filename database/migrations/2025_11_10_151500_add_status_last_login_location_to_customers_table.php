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
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'status')) {
                $table->enum('status', ['ACTIVE', 'SUSPENDED', 'DELETED'])
                    ->default('ACTIVE')
                    ->after('email_verified_at');
            }

            if (!Schema::hasColumn('customers', 'last_login')) {
                $table->timestamp('last_login')
                    ->nullable()
                    ->after('status');
            }

            if (!Schema::hasColumn('customers', 'location')) {
                $table->string('location')
                    ->nullable()
                    ->after('last_login');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'location')) {
                $table->dropColumn('location');
            }

            if (Schema::hasColumn('customers', 'last_login')) {
                $table->dropColumn('last_login');
            }

            if (Schema::hasColumn('customers', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
