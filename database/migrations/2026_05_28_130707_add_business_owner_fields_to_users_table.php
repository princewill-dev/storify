<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('role');
            }
            if (!Schema::hasColumn('users', 'is_verified')) {
                $table->boolean('is_verified')->default(false)->after('status');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('is_verified');
            }
            if (!Schema::hasColumn('users', 'location')) {
                $table->string('location')->nullable()->after('last_login_at');
            }
            if (!Schema::hasColumn('users', 'ip_address')) {
                $table->string('ip_address')->nullable()->after('location');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'is_verified', 'last_login_at', 'location', 'ip_address']);
        });
    }
};
