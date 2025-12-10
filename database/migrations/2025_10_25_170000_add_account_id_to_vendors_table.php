<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'account_id')) {
                $table->string('account_id')->nullable()->unique();
            }
        });

        // Backfill existing rows
        $vendors = DB::table('vendors')->whereNull('account_id')->get(['id']);
        foreach ($vendors as $v) {
            // Ensure uniqueness
            do {
                $candidate = 'vd_'.Str::lower(Str::random(10));
                $exists = DB::table('vendors')->where('account_id', $candidate)->exists();
            } while ($exists);
            DB::table('vendors')->where('id', $v->id)->update(['account_id' => $candidate]);
        }
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'account_id')) {
                $table->dropUnique(['account_id']);
                $table->dropColumn('account_id');
            }
        });
    }
};
