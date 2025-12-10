<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_kyc_applications', function (Blueprint $table) {
            $table->string('selfie_image_path')->nullable()->after('identification_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_kyc_applications', function (Blueprint $table) {
            $table->dropColumn('selfie_image_path');
        });
    }
};
