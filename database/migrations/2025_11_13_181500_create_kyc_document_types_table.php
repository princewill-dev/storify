<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('kyc_document_type_id')->nullable()->after('kyc_submitted_at')->constrained('kyc_document_types');
        });

        Schema::table('vendor_kyc_applications', function (Blueprint $table) {
            $table->foreignId('kyc_document_type_id')->nullable()->after('identification_document_path')->constrained('kyc_document_types');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_kyc_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kyc_document_type_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kyc_document_type_id');
        });

        Schema::dropIfExists('kyc_document_types');
    }
};
