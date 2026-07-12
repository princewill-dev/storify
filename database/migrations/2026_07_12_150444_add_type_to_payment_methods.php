<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('type')->default('traditional')->after('code');
        });

        // Update existing records
        DB::table('payment_methods')->where('code', 'paystack')->update(['type' => 'gateway']);
        DB::table('payment_methods')->where('code', 'bank_transfer')->update(['type' => 'traditional']);

        // Seed paystack if missing
        if (!DB::table('payment_methods')->where('code', 'paystack')->exists()) {
            DB::table('payment_methods')->insert([
                'code' => 'paystack', 'type' => 'gateway', 'name' => 'Paystack',
                'description' => 'Accept card payments via Paystack', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (!DB::table('payment_methods')->where('code', 'bank_transfer')->exists()) {
            DB::table('payment_methods')->insert([
                'code' => 'bank_transfer', 'type' => 'traditional', 'name' => 'Bank Transfer',
                'description' => 'Customers pay directly to your bank account', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
