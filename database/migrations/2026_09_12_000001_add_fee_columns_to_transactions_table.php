<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_kobo')->nullable()->after('amount');
            $table->unsignedBigInteger('net_kobo')->nullable()->after('fee_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['fee_kobo', 'net_kobo']);
        });
    }
};
