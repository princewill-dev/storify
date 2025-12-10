<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_services', function (Blueprint $table) {
            $table->string('page_link')->nullable()->unique()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('company_services', function (Blueprint $table) {
            $table->dropUnique(['page_link']);
            $table->dropColumn('page_link');
        });
    }
};
