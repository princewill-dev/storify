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
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('subject_type')->nullable()->after('action');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->json('old_values')->nullable()->after('description');
            $table->json('new_values')->nullable()->after('old_values');
            
            // Add index for polymorphic relationship
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id', 'old_values', 'new_values']);
        });
    }
};
