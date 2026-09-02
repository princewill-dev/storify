<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('support_messages')
            ->where('replied_by_type', 'vendor')
            ->update(['replied_by_type' => 'business']);
    }

    public function down(): void
    {
        DB::table('support_messages')
            ->where('replied_by_type', 'business')
            ->update(['replied_by_type' => 'vendor']);
    }
};
