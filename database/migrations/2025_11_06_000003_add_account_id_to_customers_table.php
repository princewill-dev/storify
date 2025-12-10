<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\Customer;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('account_id')->nullable()->after('id');
        });
        
        // Generate account_id for existing customers
        Customer::whereNull('account_id')->orWhere('account_id', '')->chunk(100, function ($customers) {
            foreach ($customers as $customer) {
                $customer->account_id = 'cus_' . strtoupper(Str::random(8));
                $customer->save();
            }
        });
        
        // Now make it unique and indexed
        Schema::table('customers', function (Blueprint $table) {
            $table->string('account_id')->unique()->change();
            $table->index('account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
