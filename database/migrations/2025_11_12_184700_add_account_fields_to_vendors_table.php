<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'password')) {
                $table->string('password')->nullable()->after('email');
            }
            if (!Schema::hasColumn('vendors', 'is_verified')) {
                $table->boolean('is_verified')->default(false)->after('password');
            }
            if (!Schema::hasColumn('vendors', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('is_verified');
            }
            if (!Schema::hasColumn('vendors', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('vendors', 'last_login')) {
                $table->timestamp('last_login')->nullable()->after('email_verified_at');
            }
            if (!Schema::hasColumn('vendors', 'location')) {
                $table->string('location')->nullable()->after('last_login');
            }
            if (!Schema::hasColumn('vendors', 'kyc_status')) {
                $table->string('kyc_status', 50)->default('draft')->after('location');
            }
            if (!Schema::hasColumn('vendors', 'kyc_submitted_at')) {
                $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'kyc_submitted_at')) {
                $table->dropColumn('kyc_submitted_at');
            }
            if (Schema::hasColumn('vendors', 'kyc_status')) {
                $table->dropColumn('kyc_status');
            }
            if (Schema::hasColumn('vendors', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('vendors', 'last_login')) {
                $table->dropColumn('last_login');
            }
            if (Schema::hasColumn('vendors', 'ip_address')) {
                $table->dropColumn('ip_address');
            }
            if (Schema::hasColumn('vendors', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }
            if (Schema::hasColumn('vendors', 'is_verified')) {
                $table->dropColumn('is_verified');
            }
            if (Schema::hasColumn('vendors', 'password')) {
                $table->dropColumn('password');
            }
        });
    }
};
