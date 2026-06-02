<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'prefix')) {
                $table->string('prefix', 6)->nullable()->after('name');
            }
            if (!Schema::hasColumn('businesses', 'business_code')) {
                $table->string('business_code', 30)->nullable()->unique()->after('prefix');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'user_code') && !Schema::hasColumn('users', 'account_code')) {
                $table->renameColumn('user_code', 'account_code');
            }
        });

        $this->backfillBusinessCodes();
        $this->backfillAccountCodes();
    }

    private function backfillBusinessCodes(): void
    {
        if (!Schema::hasColumn('businesses', 'prefix')) {
            return;
        }

        $businesses = DB::table('businesses')->whereNull('business_code')->orWhereNull('prefix')->get();

        foreach ($businesses as $b) {
            $words = explode(' ', preg_replace('/[^a-zA-Z\s]/', '', $b->name));
            $prefix = collect($words)->filter()->count() === 1
                ? strtoupper(substr($words[0], 0, 2))
                : strtoupper(implode('', array_map(
                    fn($w) => $w[0] ?? '',
                    array_slice(array_filter($words), 0, 3)
                )));

            DB::table('businesses')->where('id', $b->id)->update([
                'prefix' => $prefix ?: 'ST',
                'business_code' => $prefix . '_BIZ_' . Str::upper(Str::random(8)),
            ]);
        }
    }

    private function backfillAccountCodes(): void
    {
        if (!Schema::hasColumn('users', 'account_code')) {
            return;
        }

        $users = DB::table('users')->whereNull('account_code')->get();

        foreach ($users as $u) {
            $prefix = 'PL';

            if ($u->business_id) {
                $business = DB::table('businesses')->where('id', $u->business_id)->first();
                if ($business?->prefix) {
                    $prefix = $business->prefix;
                }
            }

            DB::table('users')->where('id', $u->id)->update([
                'account_code' => $prefix . '_ACT_' . Str::upper(Str::random(8)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['prefix', 'business_code']);
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'account_code') && !Schema::hasColumn('users', 'user_code')) {
                $table->renameColumn('account_code', 'user_code');
            }
        });
    }
};
