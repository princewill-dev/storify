<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('kyc_document_id')->nullable()->after('kyc_document_type_id');
        });

        Schema::table('vendor_kyc_applications', function (Blueprint $table) {
            $table->string('kyc_document_id')->nullable()->after('kyc_document_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_kyc_applications', function (Blueprint $table) {
            $table->dropColumn('kyc_document_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('kyc_document_id');
        });
    }
};
