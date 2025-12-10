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
        Schema::create('live_first_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            
            // Personal Information
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->string('phone_number');
            
            // Employment Information
            $table->string('employer_name');
            $table->decimal('years_with_employer', 4, 1)->nullable(); // e.g., 3.5 years
            
            // Origin Information
            $table->string('state_of_origin');
            $table->string('lga_of_origin');
            $table->string('community')->nullable();
            $table->string('village')->nullable();
            
            // Residential Information
            $table->string('residential_state');
            $table->string('residential_lga');
            $table->text('residential_address');
            
            // Review tracking
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_first_applications');
    }
};
