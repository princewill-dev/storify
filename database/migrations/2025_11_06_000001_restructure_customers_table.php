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
            // Drop user_id foreign key and column
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            
            // Drop address-related columns (moving to delivery_addresses table)
            $table->dropColumn([
                'company_name',
                'country',
                'state',
                'city',
                'street_address',
                'apartment',
                'zip_code',
            ]);
            
            // Add new columns
            $table->string('password');
            $table->string('ip_address')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            
            // Add indexes
            $table->index('email');
            $table->index('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Remove new columns
            $table->dropColumn(['password', 'ip_address', 'email_verified_at', 'remember_token']);
            
            // Add back user_id
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            
            // Add back address columns
            $table->string('company_name')->nullable();
            $table->string('country')->default('Nigeria');
            $table->string('state');
            $table->string('city');
            $table->text('street_address');
            $table->string('apartment')->nullable();
            $table->string('zip_code')->nullable();
        });
    }
};
