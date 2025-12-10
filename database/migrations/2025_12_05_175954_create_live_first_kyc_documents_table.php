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
        Schema::create('live_first_kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('live_first_applications')->onDelete('cascade');
            $table->enum('document_type', [
                'nin',
                'passport',
                'payslip_old',
                'payslip_recent',
                'video',
                'selfie',
                'appointment_letter',
                'bank_authorization'
            ]);
            $table->string('file_path');
            $table->string('file_name');
            $table->bigInteger('file_size')->nullable(); // in bytes
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_first_kyc_documents');
    }
};
