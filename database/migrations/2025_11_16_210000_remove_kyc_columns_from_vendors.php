<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vendors')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'kyc_status')) {
                $table->dropColumn('kyc_status');
            }
            if (Schema::hasColumn('vendors', 'kyc_submitted_at')) {
                $table->dropColumn('kyc_submitted_at');
            }
            if (Schema::hasColumn('vendors', "kyc_document_type_id")) {
                try {
                    $table->dropForeign(['kyc_document_type_id']);
                } catch (\Throwable $e) {
                    // ignore if constraint already removed
                }
                $table->dropColumn('kyc_document_type_id');
            }
            if (Schema::hasColumn('vendors', 'kyc_document_id')) {
                $table->dropColumn('kyc_document_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendors')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'kyc_status')) {
                $table->string('kyc_status', 50)->nullable()->after('location');
            }
            if (!Schema::hasColumn('vendors', 'kyc_submitted_at')) {
                $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_status');
            }
            if (!Schema::hasColumn('vendors', 'kyc_document_type_id')) {
                $table->foreignId('kyc_document_type_id')
                    ->nullable()
                    ->constrained('kyc_document_types')
                    ->nullOnDelete()
                    ->after('kyc_submitted_at');
            }
            if (!Schema::hasColumn('vendors', 'kyc_document_id')) {
                $table->string('kyc_document_id')->nullable()->after('kyc_document_type_id');
            }
        });
    }
};
