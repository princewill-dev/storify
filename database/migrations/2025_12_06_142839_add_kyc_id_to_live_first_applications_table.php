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
        // Add column as nullable first (only if it doesn't exist)
        if (!Schema::hasColumn('live_first_applications', 'kyc_id')) {
            Schema::table('live_first_applications', function (Blueprint $table) {
                $table->string('kyc_id', 30)->nullable()->after('id');
            });
        }
        
        // Update existing records with unique KYC IDs
        DB::table('live_first_applications')
            ->whereNull('kyc_id')
            ->orWhere('kyc_id', '')
            ->orderBy('id')
            ->each(function ($application) {
                DB::table('live_first_applications')
                    ->where('id', $application->id)
                    ->update(['kyc_id' => 'KYC_' . strtoupper(\Illuminate\Support\Str::random(14))]);
            });
        
        // Now make it unique and not nullable
        try {
            Schema::table('live_first_applications', function (Blueprint $table) {
                $table->string('kyc_id', 30)->unique()->change();
            });
        } catch (\Exception $e) {
            // Index might already exist, check if we can just modify the column
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
            // If it's just duplicate entry error from existing constraint, continue
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_first_applications', function (Blueprint $table) {
            $table->dropUnique(['kyc_id']);
            $table->dropColumn('kyc_id');
        });
    }
};
